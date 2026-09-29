<?php
/**
 * AfterBill - Reminders
 * Overdue, due-today and upcoming unpaid bills within a chosen reminder window.
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/recurring_bills.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

generateMissedRecurringBills($pdo, $userId);

// Reminder window: remembered for the session so it sticks while navigating.
$allowedWindows = [3, 7, 14, 30];
if (isset($_GET['days']) && in_array((int)$_GET['days'], $allowedWindows, true)) {
    $_SESSION['reminder_days'] = (int)$_GET['days'];
}
$windowDays = $_SESSION['reminder_days'] ?? 7;

// Every unpaid bill that is overdue or due within the window, in one query.
$stmt = $pdo->prepare(
    "SELECT b.bill_id, b.bill_name, b.amount, b.due_date, b.is_recurring, c.category_name,
            DATEDIFF(b.due_date, CURDATE()) AS days_left
     FROM bills b
     JOIN categories c ON c.category_id = b.category_id
     WHERE b.user_id = ?
       AND b.status = 'unpaid'
       AND b.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
     ORDER BY b.due_date ASC, b.bill_name ASC"
);
$stmt->execute([$userId, $windowDays]);

$groups = [
    'overdue'  => ['rows' => [], 'total' => 0.0],
    'today'    => ['rows' => [], 'total' => 0.0],
    'upcoming' => ['rows' => [], 'total' => 0.0],
];
foreach ($stmt->fetchAll() as $row) {
    $daysLeft = (int)$row['days_left'];
    $key = $daysLeft < 0 ? 'overdue' : ($daysLeft === 0 ? 'today' : 'upcoming');
    $groups[$key]['rows'][] = $row;
    $groups[$key]['total'] += (float)$row['amount'];
}

$alertTotal = count($groups['overdue']['rows']) + count($groups['today']['rows']);
$sections = [
    'overdue'  => ['Overdue', 'red', 'These bills are past their due date. Pay them as soon as possible.'],
    'today'    => ['Due Today', 'blue', 'Pay these today to avoid late fees.'],
    'upcoming' => ['Coming Up', 'amber', "Due within the next $windowDays days."],
];

function reminderDueText(int $daysLeft): string
{
    if ($daysLeft < 0) {
        $n = -$daysLeft;
        return "Overdue by $n day" . ($n === 1 ? '' : 's');
    }
    if ($daysLeft === 0) {
        return 'Due today';
    }
    return $daysLeft === 1 ? 'Due tomorrow' : "Due in $daysLeft days";
}

// Summary sent to the browser for the optional desktop notification.
$notifyText = [];
if ($groups['overdue']['rows']) {
    $notifyText[] = count($groups['overdue']['rows']) . ' overdue';
}
if ($groups['today']['rows']) {
    $notifyText[] = count($groups['today']['rows']) . ' due today';
}
if ($groups['upcoming']['rows']) {
    $notifyText[] = count($groups['upcoming']['rows']) . " due in the next $windowDays days";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reminders – AfterBill</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        .window-tabs { display: inline-flex; background: var(--white); border: 1px solid var(--border); border-radius: 10px; padding: 4px; gap: 2px; }
        .window-tabs a {
            padding: 7px 14px; border-radius: 7px; font-size: 0.8rem; font-weight: 600;
            color: var(--text-muted); text-decoration: none;
        }
        .window-tabs a.active { background: var(--electric-blue); color: #fff; }
        .toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
        .toolbar-label { font-size: 0.8rem; color: var(--text-muted); margin-right: 8px; }
        .panel-note { padding: 10px 22px; font-size: 0.78rem; color: var(--text-muted); background: #F9FAFB; border-bottom: 1px solid var(--border); }
        .all-clear { text-align: center; padding: 48px 22px; }
        .all-clear h3 { font-size: 1.05rem; color: var(--deep-navy); margin-bottom: 6px; }
        .all-clear p { font-size: 0.85rem; color: var(--text-muted); }
        #notifyStatus { font-size: 0.78rem; color: var(--text-muted); }
    </style>
</head>
<body>
<div class="app">

    <?php $activePage = 'reminders'; include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2>Reminders</h2>
                <p><?= $alertTotal ?> bill<?= $alertTotal === 1 ? '' : 's' ?> need<?= $alertTotal === 1 ? 's' : '' ?> attention now</p>
            </div>
            <div class="topbar-right">
                <a href="auth/logout.php" class="btn-logout">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                    </svg>
                    Logout
                </a>
            </div>
        </header>

        <main class="page-body">

            <div class="toolbar">
                <div>
                    <span class="toolbar-label">Remind me about bills due within</span>
                    <nav class="window-tabs" aria-label="Reminder window">
                        <?php foreach ($allowedWindows as $d): ?>
                            <a href="?days=<?= $d ?>" class="<?= $d === $windowDays ? 'active' : '' ?>"><?= $d ?> days</a>
                        <?php endforeach; ?>
                    </nav>
                </div>
                <div>
                    <button type="button" id="notifyBtn" class="btn-outline blue">Enable desktop alerts</button>
                    <span id="notifyStatus"></span>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card red">
                    <div class="stat-value">₱<?= number_format($groups['overdue']['total'], 2) ?></div>
                    <div class="stat-label"><?= count($groups['overdue']['rows']) ?> overdue</div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-value">₱<?= number_format($groups['today']['total'], 2) ?></div>
                    <div class="stat-label"><?= count($groups['today']['rows']) ?> due today</div>
                </div>
                <div class="stat-card amber">
                    <div class="stat-value">₱<?= number_format($groups['upcoming']['total'], 2) ?></div>
                    <div class="stat-label"><?= count($groups['upcoming']['rows']) ?> due in the next <?= $windowDays ?> days</div>
                </div>
            </div>

            <?php if (!$groups['overdue']['rows'] && !$groups['today']['rows'] && !$groups['upcoming']['rows']): ?>
                <div class="panel">
                    <div class="all-clear">
                        <h3>You're all caught up 🎉</h3>
                        <p>No unpaid bills are overdue or due in the next <?= $windowDays ?> days.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($sections as $key => [$title, $color, $note]): ?>
                <?php if (!$groups[$key]['rows']) continue; ?>
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title"><?= $title ?></span>
                        <span class="panel-badge <?= $color ?>"><?= count($groups[$key]['rows']) ?> · ₱<?= number_format($groups[$key]['total'], 2) ?></span>
                    </div>
                    <div class="panel-note"><?= htmlspecialchars($note) ?></div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Bill</th>
                                    <th>Category</th>
                                    <th>Amount</th>
                                    <th>Due Date</th>
                                    <th>Reminder</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groups[$key]['rows'] as $bill): ?>
                                    <?php $daysLeft = (int)$bill['days_left']; ?>
                                    <tr>
                                        <td class="strong"><?= htmlspecialchars($bill['bill_name']) ?><?= $bill['is_recurring'] ? ' <span class="muted">· monthly</span>' : '' ?></td>
                                        <td><?= htmlspecialchars($bill['category_name']) ?></td>
                                        <td>₱<?= number_format((float)$bill['amount'], 2) ?></td>
                                        <td><?= (new DateTime($bill['due_date']))->format('M j, Y') ?></td>
                                        <td><span class="status-badge <?= $key === 'upcoming' ? 'unpaid' : ($key === 'today' ? 'today' : 'overdue') ?>"><?= reminderDueText($daysLeft) ?></span></td>
                                        <td>
                                            <div class="actions-cell">
                                                <a href="mark_paid.php?bill_id=<?= (int)$bill['bill_id'] ?>" class="btn-outline blue">Mark as Paid</a>
                                                <a href="edit_bill.php?bill_id=<?= (int)$bill['bill_id'] ?>" class="btn-outline">Edit</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </main>
    </div>
</div>

<script>
(function () {
    var summary = <?= json_encode(implode(', ', $notifyText)) ?>;
    var btn = document.getElementById('notifyBtn');
    var status = document.getElementById('notifyStatus');

    if (!('Notification' in window)) {
        btn.style.display = 'none';
        return;
    }

    function notify() {
        if (summary) {
            new Notification('AfterBill reminders', { body: 'You have ' + summary + '.' });
        }
    }

    function refresh() {
        if (Notification.permission === 'granted') {
            btn.style.display = 'none';
            status.textContent = 'Desktop alerts are on.';
        } else if (Notification.permission === 'denied') {
            btn.style.display = 'none';
            status.textContent = 'Desktop alerts are blocked in your browser settings.';
        }
    }

    btn.addEventListener('click', function () {
        Notification.requestPermission().then(function (p) {
            refresh();
            if (p === 'granted') notify();
        });
    });

    refresh();
    // Once per browser session, so navigating back here doesn't spam alerts.
    try {
        if (Notification.permission === 'granted' && !sessionStorage.getItem('abNotified')) {
            sessionStorage.setItem('abNotified', '1');
            notify();
        }
    } catch (e) {}
})();
</script>
</body>
</html>
