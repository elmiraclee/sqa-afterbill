<?php
/**
 * AfterBill - Activity Log
 * Read-only history of the signed-in user's own actions (sign-ins, bills, payments, account,
 * budgets), newest first, filterable by type, 25 per page.
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/activity.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

$perPage = 25;

$groupFilter = $_GET['type'] ?? '';
if (!is_string($groupFilter) || !isset(ACTIVITY_GROUPS[$groupFilter])) {
    $groupFilter = '';
}
$page = $_GET['page'] ?? '1';
$page = ctype_digit((string)$page) && (int)$page >= 1 ? (int)$page : 1;

$where = 'WHERE user_id = ?';
$params = [$userId];
if ($groupFilter !== '') {
    $actions = array_keys(array_filter(ACTIVITY_TYPES, fn($t) => $t[2] === $groupFilter));
    $where .= ' AND action IN (' . implode(',', array_fill(0, count($actions), '?')) . ')';
    $params = array_merge($params, $actions);
}

$setupError = false;
$entries = [];
$total = 0;
$failedLogins7d = 0;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    // LIMIT/OFFSET are ints computed above, never user strings.
    $stmt = $pdo->prepare(
        "SELECT action, details, ip_address, created_at FROM activity_log $where
         ORDER BY created_at DESC, log_id DESC LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $entries = $stmt->fetchAll();

    $failStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM activity_log
         WHERE user_id = ? AND action = 'login_failed' AND created_at >= NOW() - INTERVAL 7 DAY"
    );
    $failStmt->execute([$userId]);
    $failedLogins7d = (int)$failStmt->fetchColumn();
} catch (PDOException $e) {
    $setupError = true; // activity_log table missing: migration not applied
}
$totalPages = max(1, (int)ceil($total / $perPage));

function activityPageUrl(string $type, int $page): string
{
    return 'activity.php?' . http_build_query(array_filter(['type' => $type, 'page' => $page > 1 ? $page : null]));
}

// Group rows by calendar day for the list headings.
$byDay = [];
foreach ($entries as $e) {
    $byDay[substr($e['created_at'], 0, 10)][] = $e;
}
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log – AfterBill</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        .type-tabs { display: inline-flex; flex-wrap: wrap; background: var(--white); border: 1px solid var(--border); border-radius: 10px; padding: 4px; gap: 2px; margin-bottom: 20px; }
        .type-tabs a { padding: 7px 14px; border-radius: 7px; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-decoration: none; }
        .type-tabs a.active { background: var(--electric-blue); color: #fff; }
        .day-heading { padding: 10px 22px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); background: #F9FAFB; border-bottom: 1px solid var(--border); }
        .log-row { display: grid; grid-template-columns: 70px 170px 1fr auto; gap: 14px; align-items: center; padding: 12px 22px; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .log-row:last-child { border-bottom: none; }
        .log-time { color: var(--text-muted); font-size: 0.8rem; font-variant-numeric: tabular-nums; }
        .log-ip { color: var(--text-muted); font-size: 0.75rem; }
        .act-badge { display: inline-block; font-size: 0.72rem; font-weight: 600; padding: 4px 10px; border-radius: 999px; }
        .act-badge.green { background: #ECFDF5; color: #047857; }
        .act-badge.blue { background: #EFF6FF; color: #1D4ED8; }
        .act-badge.amber { background: #FFFBEB; color: #B45309; }
        .act-badge.red { background: #FEF2F2; color: #B91C1C; }
        .act-badge.gray { background: var(--light-gray); color: var(--text-muted); }
        .pager { display: flex; justify-content: space-between; align-items: center; padding: 12px 22px; border-top: 1px solid var(--border); font-size: 0.8rem; color: var(--text-muted); }
        @media (max-width: 680px) {
            .log-row { grid-template-columns: 1fr; gap: 4px; }
        }
    </style>
</head>
<body>
<div class="app">

    <?php $activePage = 'activity'; include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2>Activity Log</h2>
                <p><?= $total ?> event<?= $total === 1 ? '' : 's' ?><?= $groupFilter !== '' ? ' · ' . ACTIVITY_GROUPS[$groupFilter] : '' ?></p>
            </div>
            <div class="topbar-right">
                <?php require __DIR__ . '/partials/logout-button.php'; ?>
            </div>
        </header>

        <main class="page-body">

            <?php if ($setupError): ?>
                <div class="alert alert-error">The activity log is not set up in this database yet. Run database/migrations/001_budgets_activity_log.sql.</div>
            <?php endif; ?>

            <?php if ($failedLogins7d > 0): ?>
                <div class="alert alert-warning">
                    <?= $failedLogins7d ?> failed login attempt<?= $failedLogins7d === 1 ? '' : 's' ?> on your account in the last 7 days.
                    If that wasn't you, <a href="account.php">change your password</a>.
                </div>
            <?php endif; ?>

            <nav class="type-tabs" aria-label="Filter by type">
                <a href="activity.php" class="<?= $groupFilter === '' ? 'active' : '' ?>">All</a>
                <?php foreach (ACTIVITY_GROUPS as $key => $label): ?>
                    <a href="<?= activityPageUrl($key, 1) ?>" class="<?= $groupFilter === $key ? 'active' : '' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="panel">
                <?php if (!$entries): ?>
                    <div class="empty-state">No activity recorded<?= $groupFilter !== '' ? ' of this type' : '' ?> yet.</div>
                <?php else: ?>
                    <?php foreach ($byDay as $day => $rows): ?>
                        <div class="day-heading">
                            <?= $day === $today ? 'Today' : ($day === $yesterday ? 'Yesterday' : (new DateTime($day))->format('l, F j, Y')) ?>
                        </div>
                        <?php foreach ($rows as $e): ?>
                            <?php [$label, $color] = ACTIVITY_TYPES[$e['action']] ?? [$e['action'], 'gray']; ?>
                            <div class="log-row">
                                <span class="log-time"><?= (new DateTime($e['created_at']))->format('g:i A') ?></span>
                                <span><span class="act-badge <?= $color ?>"><?= htmlspecialchars($label) ?></span></span>
                                <span><?= $e['details'] !== null ? htmlspecialchars($e['details']) : '<span class="muted">—</span>' ?></span>
                                <span class="log-ip"><?php
                                    if ($e['ip_address'] === '::1' || $e['ip_address'] === '127.0.0.1') {
                                        echo 'This computer';
                                    } elseif ($e['ip_address'] !== null) {
                                        echo 'IP ' . htmlspecialchars($e['ip_address']);
                                    }
                                ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <?php if ($totalPages > 1): ?>
                        <div class="pager">
                            <span>Page <?= $page ?> of <?= $totalPages ?></span>
                            <span class="actions-cell">
                                <?php if ($page > 1): ?>
                                    <a href="<?= activityPageUrl($groupFilter, $page - 1) ?>" class="btn-outline">‹ Newer</a>
                                <?php endif; ?>
                                <?php if ($page < $totalPages): ?>
                                    <a href="<?= activityPageUrl($groupFilter, $page + 1) ?>" class="btn-outline">Older ›</a>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
</body>
</html>
