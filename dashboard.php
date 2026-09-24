<?php
/**
 * AfterBill - Dashboard
 * All statistics are computed live from the `bills` table for the logged-in user.
 */

require_once __DIR__ . '/includes/auth.php';
require_login();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bills_repo.php';
require_once __DIR__ . '/includes/reminders_repo.php';

$userId = current_user_id();
$user = get_user($userId);

$userName = $user['full_name'] ?? ($_SESSION['user_name'] ?? 'User');
$_SESSION['user_name'] = $userName; // keep session in sync if profile changed
$userInitial = strtoupper(substr($userName, 0, 1));
$firstName = explode(' ', $userName)[0];
$currency = $user['currency'] ?? 'PHP';

$now = new DateTime();
$hour = (int)$now->format('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

$stats = bills_dashboard_stats($userId);
$upcomingBills = bills_upcoming($userId, 5);
$alerts = bills_due_alerts($userId, 7);

$pageTitle = 'Dashboard';
$pageSubtitle = $now->format('l, F j, Y');
$activePage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard – AfterBill</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app">

    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <?php include __DIR__ . '/partials/topbar.php'; ?>

        <main class="page-body">
            <?php include __DIR__ . '/partials/alerts.php'; ?>

            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="welcome-text">
                    <h1><?= $greeting ?>, <?= e($firstName) ?>! 👋</h1>
                    <p>Here's a quick overview of your bills this month.</p>
                </div>
                <div class="welcome-badge">
                    <div class="badge-label">Today</div>
                    <div class="badge-date"><?= $now->format('M j, Y') ?></div>
                </div>
            </div>

            <?php if (!empty($alerts)): ?>
            <div class="panel" style="margin-bottom:28px;">
                <div class="panel-header">
                    <span class="panel-title">Reminders</span>
                    <span class="panel-badge amber"><?= count($alerts) ?> active</span>
                </div>
                <div class="alert-list">
                    <?php foreach (array_slice($alerts, 0, 5) as $alert): ?>
                        <div class="reminder-alert <?= e($alert['level']) ?>">
                            <span class="dot"></span>
                            <span><?= e($alert['message']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid stats-grid-4">
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= (int)$stats['total_bills'] ?></div>
                        <div class="stat-label">Total Bills · <?= format_money($stats['total_amount'], $currency) ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon green">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= format_money($stats['paid_this_month'], $currency) ?></div>
                        <div class="stat-label">Paid This Month</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon amber">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= (int)$stats['upcoming_count'] ?></div>
                        <div class="stat-label">Upcoming · <?= format_money($stats['upcoming_amount'], $currency) ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon red">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= (int)$stats['overdue_count'] ?></div>
                        <div class="stat-label">Overdue · <?= format_money($stats['overdue_amount'], $currency) ?></div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">

                <!-- Upcoming Bills -->
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title">Upcoming Bills</span>
                        <span class="panel-badge amber">This Month</span>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($upcomingBills)): ?>
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                                </svg>
                                <p>No upcoming bills yet.<br>Add your first bill to get started!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcomingBills as $bill):
                                $display = bill_display_status($bill['status'], $bill['due_date']);
                                $due = new DateTime($bill['due_date']);
                            ?>
                            <a href="bills.php" class="bill-item" style="text-decoration:none; color:inherit;">
                                <div class="bill-icon"><?= category_icon($bill['category']) ?></div>
                                <div class="bill-info">
                                    <div class="bill-name"><?= e($bill['name']) ?></div>
                                    <div class="bill-due"><?= e($bill['category']) ?> · Due <?= $due->format('M j, Y') ?></div>
                                </div>
                                <div style="text-align:right;">
                                    <div class="bill-amount"><?= format_money((float)$bill['amount'], $currency) ?></div>
                                    <span class="bill-status <?= status_badge_class($display) ?>"><?= status_label($display) ?></span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title">Quick Actions</span>
                    </div>
                    <div class="panel-body">
                        <div class="quick-actions">
                            <a href="bills.php?action=add" class="action-btn">
                                <div class="action-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                    </svg>
                                </div>
                                <span class="action-label">Add Bill</span>
                            </a>
                            <a href="calendar.php" class="action-btn">
                                <div class="action-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                                    </svg>
                                </div>
                                <span class="action-label">View Calendar</span>
                            </a>
                            <a href="reports.php" class="action-btn">
                                <div class="action-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                                    </svg>
                                </div>
                                <span class="action-label">View Reports</span>
                            </a>
                            <a href="reminders.php" class="action-btn">
                                <div class="action-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
                                    </svg>
                                </div>
                                <span class="action-label">Reminders</span>
                            </a>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
