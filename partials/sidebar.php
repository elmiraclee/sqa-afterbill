<?php
/**
 * AfterBill - Shared app sidebar
 *
 * Set BEFORE including:
 *   $activePage  one of: dashboard, bills, calendar, payments, reports, budgets, reminders,
 *                account, activity
 * Also expects $userName / $userInitial (already escaped) like every page defines.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/budgets.php';

$activePage = $activePage ?? '';

// Reminder badge: unpaid bills that are overdue or due within the next 3 days.
$sidebarAlertStmt = getPDO()->prepare(
    "SELECT COUNT(*) FROM bills
     WHERE user_id = ? AND status = 'unpaid'
       AND due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)"
);
$sidebarAlertStmt->execute([$_SESSION['user_id']]);
$sidebarAlertCount = (int)$sidebarAlertStmt->fetchColumn();

// Budgets badge: categories over their monthly budget this month.
$sidebarBudgetOver = budgetsOverCount(getPDO(), (int)$_SESSION['user_id']);

$sidebarItems = [
    'dashboard' => ['Dashboard', 'dashboard.php', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
    'bills'     => ['My Bills', 'bills.php', 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z'],
    'calendar'  => ['Calendar', 'calendar.php', 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5'],
    'payments'  => ['Payments', 'payments.php', 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z'],
    'reports'   => ['Reports', 'reports.php', 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
    'budgets'   => ['Budgets', 'budgets.php', 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    'reminders' => ['Reminders', 'reminders.php', 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0'],
];
$accountIcon = 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z';
$activityIcon = 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z';
?>
<style>
    .nav-count {
        margin-left: auto;
        min-width: 20px;
        padding: 1px 7px;
        border-radius: 999px;
        background: #EF4444;
        color: #fff;
        font-size: 0.68rem;
        font-weight: 700;
        text-align: center;
    }
</style>
<aside class="sidebar">
    <div class="sidebar-logo">
        <a href="index.php"><img src="assets/images/logo.png" alt="AfterBill"></a>
    </div>

    <nav class="sidebar-nav">
        <span class="nav-label">Menu</span>

        <?php foreach ($sidebarItems as $key => [$label, $href, $icon]): ?>
            <a href="<?= $href ?>" class="nav-item<?= $activePage === $key ? ' active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="<?= $icon ?>"/>
                </svg>
                <?= $label ?>
                <?php if ($key === 'reminders' && $sidebarAlertCount > 0): ?>
                    <span class="nav-count" title="Overdue or due within 3 days"><?= $sidebarAlertCount ?></span>
                <?php endif; ?>
                <?php if ($key === 'budgets' && $sidebarBudgetOver > 0): ?>
                    <span class="nav-count" title="Categories over budget this month"><?= $sidebarBudgetOver ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>

        <span class="nav-label" style="margin-top:10px;">Account</span>

        <a href="account.php" class="nav-item<?= $activePage === 'account' ? ' active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="<?= $accountIcon ?>"/>
            </svg>
            Account
        </a>

        <a href="activity.php" class="nav-item<?= $activePage === 'activity' ? ' active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="<?= $activityIcon ?>"/>
            </svg>
            Activity Log
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar"><?= $userInitial ?></div>
            <div class="user-info">
                <div class="user-name"><?= $userName ?></div>
            </div>
        </div>
    </div>
</aside>
