<?php
/**
 * AfterBill - Budgets
 * Monthly spending limit per category, with progress against the bills due that month.
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/recurring_bills.php';
require_once __DIR__ . '/includes/budgets.php';
require_once __DIR__ . '/includes/activity.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

// Same as the other pages: catch up recurring bills first so "spent" includes them.
generateMissedRecurringBills($pdo, $userId);

// Selected month from ?month=YYYY-MM (POST carries it in a hidden field), default this month.
$monthParam = $_POST['month'] ?? $_GET['month'] ?? date('Y-m');
if (!is_string($monthParam) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$monthStart = new DateTime($monthParam . '-01');
$prevMonth = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthStart)->modify('+1 month')->format('Y-m');

$categories = $pdo->query('SELECT category_id, category_name FROM categories ORDER BY category_name')->fetchAll();
$categoryNames = array_column($categories, 'category_name', 'category_id');

$errors = [];
$editCategory = null; // category whose row shows the submitted (invalid) value again
$editValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $categoryId = $_POST['category_id'] ?? '';

    if (!csrf_valid()) {
        $errors[] = CSRF_ERROR;
    }
    if (!ctype_digit((string)$categoryId) || !isset($categoryNames[(int)$categoryId])) {
        $errors[] = 'Please choose a valid category.';
    }

    if ($action === 'save') {
        $limit = trim($_POST['monthly_limit'] ?? '');
        $editCategory = (int)$categoryId;
        $editValue = $limit;
        // Same bounds as bill amounts (DECIMAL(10,2)).
        if (!is_numeric($limit) || (float)$limit <= 0 || (float)$limit > 99999999.99) {
            $errors[] = 'Budget must be an amount greater than 0 (up to 99,999,999.99).';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO budgets (user_id, category_id, monthly_limit) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE monthly_limit = VALUES(monthly_limit)'
                );
                $stmt->execute([$userId, (int)$categoryId, round((float)$limit, 2)]);
                logActivity($pdo, $userId, 'budget_set',
                    $categoryNames[(int)$categoryId] . ' — ' . activityMoney(round((float)$limit, 2)) . ' / month');
                header('Location: budgets.php?month=' . $monthParam . '&saved=1');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Could not save the budget. Please try again later.';
            }
        }
    } elseif ($action === 'remove') {
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare('DELETE FROM budgets WHERE user_id = ? AND category_id = ?');
                $stmt->execute([$userId, (int)$categoryId]);
                if ($stmt->rowCount() > 0) {
                    logActivity($pdo, $userId, 'budget_removed', $categoryNames[(int)$categoryId]);
                }
                header('Location: budgets.php?month=' . $monthParam . '&removed=1');
                exit;
            } catch (PDOException $e) {
                $errors[] = 'Could not remove the budget. Please try again later.';
            }
        }
    } else {
        $errors[] = 'Unknown action.';
    }
}

try {
    $usage = budgetUsage($pdo, $userId, $monthStart);
} catch (PDOException $e) {
    // budgets table missing: this copy of the database has not had the migration applied.
    $usage = [];
    $errors[] = 'Budgets are not set up in this database yet. Run database/migrations/001_budgets_activity_log.sql.';
}

$budgeted = array_filter($usage, fn($u) => $u['limit'] !== null);
$totalBudget = array_sum(array_column($budgeted, 'limit'));
$totalSpentBudgeted = array_sum(array_column($budgeted, 'spent'));
$totalRemaining = $totalBudget - $totalSpentBudgeted;
$overCount = count(array_filter($budgeted, fn($u) => $u['status'] === 'over'));
$nearCount = count(array_filter($budgeted, fn($u) => $u['status'] === 'near'));
$unbudgetedSpent = array_sum(array_column(array_filter($usage, fn($u) => $u['limit'] === null), 'spent'));

$statusLabels = ['ok' => 'On track', 'near' => 'Near limit', 'over' => 'Over budget', 'none' => 'No budget'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budgets – AfterBill</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        .month-nav { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px; }
        .month-nav .month-title { font-size: 1rem; font-weight: 700; color: var(--deep-navy); min-width: 150px; text-align: center; }
        .progress { width: 160px; height: 8px; background: var(--light-gray); border-radius: 999px; overflow: hidden; }
        .progress span { display: block; height: 100%; border-radius: 999px; background: var(--success); }
        .progress span.near { background: var(--warning); }
        .progress span.over { background: var(--danger); }
        .pct { font-size: 0.72rem; color: var(--text-muted); margin-top: 4px; }
        .status-badge { white-space: nowrap; }
        .budget-actions { flex-wrap: nowrap; }
        .status-badge.none { background: var(--light-gray); color: var(--text-muted); }
        .status-badge.near { background: #FFFBEB; color: #B45309; }
        .budget-form { display: inline-flex; align-items: center; gap: 6px; margin: 0; }
        .budget-form input {
            width: 105px; padding: 6px 10px; border: 1.5px solid var(--border); border-radius: 7px;
            font-size: 0.8rem; font-family: inherit;
        }
        .budget-form input.invalid { border-color: var(--danger); }
        .over-text { color: #B91C1C; font-weight: 600; }
    </style>
</head>
<body>
<div class="app">

    <?php $activePage = 'budgets'; include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2>Budgets</h2>
                <p>Monthly spending limits per category</p>
            </div>
            <div class="topbar-right">
                <?php require __DIR__ . '/partials/logout-button.php'; ?>
            </div>
        </header>

        <main class="page-body">

            <?php if (isset($_GET['saved'])): ?>
                <div class="alert alert-success">Budget saved.</div>
            <?php endif; ?>
            <?php if (isset($_GET['removed'])): ?>
                <div class="alert alert-success">Budget removed.</div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>
            <?php if ($overCount > 0): ?>
                <div class="alert alert-error">
                    <?= $overCount ?> categor<?= $overCount === 1 ? 'y is' : 'ies are' ?> over budget in <?= $monthStart->format('F Y') ?>.
                </div>
            <?php elseif ($nearCount > 0): ?>
                <div class="alert alert-warning">
                    <?= $nearCount ?> categor<?= $nearCount === 1 ? 'y has' : 'ies have' ?> used <?= BUDGET_NEAR_LIMIT_PCT ?>% or more of the budget.
                </div>
            <?php endif; ?>

            <div class="month-nav">
                <a href="?month=<?= $prevMonth ?>" class="btn-outline" aria-label="Previous month">‹ Prev</a>
                <span class="month-title"><?= $monthStart->format('F Y') ?></span>
                <a href="?month=<?= $nextMonth ?>" class="btn-outline" aria-label="Next month">Next ›</a>
                <?php if ($monthParam !== date('Y-m')): ?>
                    <a href="budgets.php" class="btn-outline blue">This month</a>
                <?php endif; ?>
            </div>

            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="stat-value">₱<?= number_format($totalBudget, 2) ?></div>
                    <div class="stat-label">Total budget · <?= count($budgeted) ?> categor<?= count($budgeted) === 1 ? 'y' : 'ies' ?></div>
                </div>
                <div class="stat-card amber">
                    <div class="stat-value">₱<?= number_format($totalSpentBudgeted, 2) ?></div>
                    <div class="stat-label">Due in budgeted categories</div>
                </div>
                <div class="stat-card <?= $totalRemaining < 0 ? 'red' : 'green' ?>">
                    <div class="stat-value"><?= $totalRemaining < 0 ? '−' : '' ?>₱<?= number_format(abs($totalRemaining), 2) ?></div>
                    <div class="stat-label"><?= $totalRemaining < 0 ? 'Over total budget' : 'Remaining' ?></div>
                </div>
                <div class="stat-card red">
                    <div class="stat-value"><?= $overCount ?></div>
                    <div class="stat-label">Over budget</div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">Categories — <?= $monthStart->format('F Y') ?></span>
                    <?php if ($unbudgetedSpent > 0): ?>
                        <span class="panel-badge amber">₱<?= number_format($unbudgetedSpent, 2) ?> in categories without a budget</span>
                    <?php endif; ?>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Due this month</th>
                                <th>Budget</th>
                                <th>Remaining</th>
                                <th>Progress</th>
                                <th>Status</th>
                                <th>Set monthly budget</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usage as $catId => $u): ?>
                                <?php
                                $remaining = $u['limit'] !== null ? $u['limit'] - $u['spent'] : null;
                                $inputValue = $editCategory === $catId
                                    ? $editValue
                                    : ($u['limit'] !== null ? number_format($u['limit'], 2, '.', '') : '');
                                ?>
                                <tr>
                                    <td class="strong"><?= htmlspecialchars($u['category_name']) ?></td>
                                    <td>₱<?= number_format($u['spent'], 2) ?> <span class="muted">· <?= $u['count'] ?> bill<?= $u['count'] === 1 ? '' : 's' ?></span></td>
                                    <td><?= $u['limit'] !== null ? '₱' . number_format($u['limit'], 2) : '<span class="muted">—</span>' ?></td>
                                    <td>
                                        <?php if ($remaining === null): ?>
                                            <span class="muted">—</span>
                                        <?php elseif ($remaining < 0): ?>
                                            <span class="over-text">−₱<?= number_format(abs($remaining), 2) ?></span>
                                        <?php else: ?>
                                            ₱<?= number_format($remaining, 2) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['pct'] !== null): ?>
                                            <div class="progress" role="progressbar" aria-valuenow="<?= round($u['pct']) ?>" aria-valuemin="0" aria-valuemax="100">
                                                <span class="<?= $u['status'] ?>" style="width: <?= min(100, round($u['pct'], 1)) ?>%;"></span>
                                            </div>
                                            <div class="pct"><?= round($u['pct'], 1) ?>% used</div>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="status-badge <?= $u['status'] === 'ok' ? 'paid' : ($u['status'] === 'over' ? 'overdue' : $u['status']) ?>"><?= $statusLabels[$u['status']] ?></span></td>
                                    <td>
                                        <div class="actions-cell budget-actions">
                                            <form method="POST" action="" class="budget-form" novalidate>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="save">
                                                <input type="hidden" name="month" value="<?= $monthParam ?>">
                                                <input type="hidden" name="category_id" value="<?= (int)$catId ?>">
                                                <input type="number" name="monthly_limit" min="0.01" step="0.01" max="99999999.99"
                                                       placeholder="₱ amount" aria-label="Monthly budget for <?= htmlspecialchars($u['category_name']) ?>"
                                                       class="<?= $editCategory === $catId && $errors ? 'invalid' : '' ?>"
                                                       value="<?= htmlspecialchars($inputValue) ?>">
                                                <button type="submit" class="btn-outline blue"><?= $u['limit'] !== null ? 'Update' : 'Set' ?></button>
                                            </form>
                                            <?php if ($u['limit'] !== null): ?>
                                                <form method="POST" action="" class="inline-form"
                                                      onsubmit="return confirm('Remove the budget for ' + <?= htmlspecialchars(json_encode($u['category_name']), ENT_QUOTES) ?> + '?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="remove">
                                                    <input type="hidden" name="month" value="<?= $monthParam ?>">
                                                    <input type="hidden" name="category_id" value="<?= (int)$catId ?>">
                                                    <button type="submit" class="btn-outline red">Remove</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="muted">
                Budgets apply to every month. "Due this month" counts all bills due in <?= $monthStart->format('F Y') ?>
                (paid and unpaid), the same total Reports uses. Status turns amber at <?= BUDGET_NEAR_LIMIT_PCT ?>% and red above 100%.
            </p>
        </main>
    </div>
</div>
</body>
</html>
