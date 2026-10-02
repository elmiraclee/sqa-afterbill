<?php
/**
 * AfterBill - Dashboard
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

// Get first name only
$firstName = explode(' ', $userName)[0];

// Current date info
$now = new DateTime();
$greeting = 'Hello';
$hour = (int)$now->format('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

// Dashboard stats (FR-04)
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/recurring_bills.php';
$pdo = getPDO();

// Catch up any recurring bills whose cycle has passed before computing stats, so newly
// generated bills are reflected immediately.
generateMissedRecurringBills($pdo, (int)$_SESSION['user_id']);

// Bills due this calendar month, grouped by status: one query gives count + sum per status.
$monthStmt = $pdo->prepare(
    'SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
     FROM bills
     WHERE user_id = ?
       AND YEAR(due_date) = YEAR(CURDATE())
       AND MONTH(due_date) = MONTH(CURDATE())
     GROUP BY status'
);
$monthStmt->execute([$_SESSION['user_id']]);
$monthRows = $monthStmt->fetchAll();

$paidCount = 0;
$paidSum = 0.0;
$unpaidCount = 0;
$unpaidSum = 0.0;

foreach ($monthRows as $row) {
    if ($row['status'] === 'paid') {
        $paidCount = (int)$row['cnt'];
        $paidSum = (float)$row['total'];
    } elseif ($row['status'] === 'unpaid') {
        $unpaidCount = (int)$row['cnt'];
        $unpaidSum = (float)$row['total'];
    }
}

$billsThisMonth = $paidCount + $unpaidCount;
$monthlyTotalExpenses = $paidSum + $unpaidSum;

// Upcoming (next 7 days) and overdue counts among unpaid bills, computed live — not restricted
// to this month, and "overdue" is never stored, same logic as bills.php.
$rangeStmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS upcoming_count,
        SUM(CASE WHEN due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count
     FROM bills
     WHERE user_id = ? AND status = 'unpaid'"
);
$rangeStmt->execute([$_SESSION['user_id']]);
$rangeRow = $rangeStmt->fetch();

$upcomingCount = (int)($rangeRow['upcoming_count'] ?? 0);
$overdueCount = (int)($rangeRow['overdue_count'] ?? 0);

// Actual rows for the "Upcoming Bills" list panel — same 7-day window as $upcomingCount above.
$upcomingListStmt = $pdo->prepare(
    "SELECT b.bill_id, b.bill_name, b.amount, b.due_date, c.category_name
     FROM bills b
     JOIN categories c ON c.category_id = b.category_id
     WHERE b.user_id = ?
       AND b.status = 'unpaid'
       AND b.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
     ORDER BY b.due_date ASC"
);
$upcomingListStmt->execute([$_SESSION['user_id']]);
$upcomingBills = $upcomingListStmt->fetchAll();
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
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --deep-navy: #17233C;
            --electric-blue: #3B82F6;
            --electric-blue-hover: #2563EB;
            --white: #FFFFFF;
            --light-gray: #F3F4F6;
            --text-dark: #1F2937;
            --text-muted: #6B7280;
            --border: #E5E7EB;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --sidebar-width: 240px;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--light-gray);
            color: var(--text-dark);
        }

        /* ── Layout ── */
        .app {
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ── */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--deep-navy) 0%, #0F172A 100%);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
            overflow: hidden;
        }

        .sidebar-logo {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }

        .sidebar-logo img {
            height: 52px;
            width: auto;
            display: block;
            filter: brightness(0) invert(1);
            opacity: 0.9;
        }

        .sidebar-nav {
            flex: 1;
            padding: 16px 12px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .nav-label {
            font-size: 0.65rem;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.3);
            padding: 10px 10px 6px;
            font-weight: 600;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            color: rgba(255,255,255,0.65);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background 0.18s, color 0.18s;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
        }

        .nav-item:hover {
            background: rgba(255,255,255,0.07);
            color: rgba(255,255,255,0.9);
        }

        .nav-item.active {
            background: rgba(59, 130, 246, 0.2);
            color: #fff;
        }

        .nav-item svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .sidebar-footer {
            padding: 16px 12px;
            border-top: 1px solid rgba(255,255,255,0.07);
        }

        .user-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 10px;
        }

        .user-avatar {
            width: 34px;
            height: 34px;
            background: var(--electric-blue);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-size: 0.82rem;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.4);
        }

        /* ── Main Content ── */
        .main {
            margin-left: var(--sidebar-width);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* ── Topbar ── */
        .topbar {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .topbar-left h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--deep-navy);
        }

        .topbar-left p {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-logout {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: none;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            text-decoration: none;
            transition: border-color 0.18s, color 0.18s, background 0.18s;
        }

        .btn-logout:hover {
            border-color: var(--danger);
            color: var(--danger);
            background: #FEF2F2;
        }

        .btn-logout svg {
            width: 15px;
            height: 15px;
        }

        /* ── Page Body ── */
        .page-body {
            padding: 32px;
            flex: 1;
        }

        /* ── Welcome Banner ── */
        .welcome-banner {
            background: linear-gradient(135deg, var(--deep-navy) 0%, #1E3A5F 100%);
            border-radius: 16px;
            padding: 28px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            overflow: hidden;
            position: relative;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -40px; right: -40px;
            width: 200px; height: 200px;
            background: radial-gradient(circle, rgba(59,130,246,0.3) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .welcome-text h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 4px;
        }

        .welcome-text p {
            font-size: 0.88rem;
            color: rgba(255,255,255,0.65);
        }

        .welcome-badge {
            background: rgba(59,130,246,0.2);
            border: 1px solid rgba(59,130,246,0.4);
            border-radius: 12px;
            padding: 12px 18px;
            text-align: center;
            position: relative;
            z-index: 1;
        }

        .welcome-badge .badge-label {
            font-size: 0.7rem;
            color: rgba(255,255,255,0.5);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 4px;
        }

        .welcome-badge .badge-date {
            font-size: 0.9rem;
            font-weight: 600;
            color: #fff;
        }

        /* ── Stats Grid ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--white);
            border-radius: 14px;
            padding: 22px 24px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: box-shadow 0.2s;
        }

        .stat-card:hover {
            box-shadow: 0 4px 16px rgba(23,35,60,0.08);
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon svg {
            width: 22px;
            height: 22px;
        }

        .stat-icon.blue  { background: rgba(59,130,246,0.1); color: var(--electric-blue); }
        .stat-icon.green { background: rgba(16,185,129,0.1); color: var(--success); }
        .stat-icon.amber { background: rgba(245,158,11,0.1); color: var(--warning); }
        .stat-icon.red   { background: rgba(239,68,68,0.1); color: var(--danger); }

        .stat-body {}

        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--deep-navy);
            line-height: 1.1;
        }

        .stat-label {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* ── Content Grid ── */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .panel {
            background: var(--white);
            border-radius: 14px;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .panel-header {
            padding: 18px 22px 14px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .panel-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--deep-navy);
        }

        .panel-badge {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 999px;
        }

        .panel-badge.blue   { background: rgba(59,130,246,0.1); color: var(--electric-blue); }
        .panel-badge.amber  { background: rgba(245,158,11,0.1); color: var(--warning); }

        .panel-body {
            padding: 6px 0;
        }

        /* Bill list items */
        .bill-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 22px;
            transition: background 0.15s;
        }

        .bill-item:hover {
            background: #F9FAFB;
        }

        .bill-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .bill-info {
            flex: 1;
            min-width: 0;
        }

        .bill-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .bill-due {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 1px;
        }

        .bill-amount {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--deep-navy);
        }

        .bill-status {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
        }

        .bill-status.paid    { background: #ECFDF5; color: #047857; }
        .bill-status.pending { background: #FFFBEB; color: #B45309; }
        .bill-status.overdue { background: #FEF2F2; color: #B91C1C; }

        /* Quick action buttons */
        .quick-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            padding: 16px 22px;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 18px 12px;
            background: var(--light-gray);
            border: 1.5px solid transparent;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.18s, border-color 0.18s, box-shadow 0.18s;
            text-align: center;
        }

        .action-btn:hover {
            background: #EFF6FF;
            border-color: rgba(59,130,246,0.3);
            box-shadow: 0 2px 8px rgba(59,130,246,0.1);
        }

        .action-btn .action-icon {
            width: 38px;
            height: 52px;
            background: var(--white);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 4px rgba(23,35,60,0.1);
        }

        .action-btn .action-icon svg {
            width: 18px;
            height: 18px;
            color: var(--electric-blue);
        }

        .action-btn .action-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Empty state */
        .empty-state {
            padding: 32px 22px;
            text-align: center;
            color: var(--text-muted);
        }

        .empty-state svg {
            width: 40px;
            height: 40px;
            margin: 0 auto 10px;
            opacity: 0.35;
        }

        .empty-state p {
            font-size: 0.82rem;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .content-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 680px) {
            .sidebar { transform: translateX(-100%); }
            .main { margin-left: 0; }
            .stats-grid { grid-template-columns: 1fr; }
            .page-body { padding: 20px; }
            .topbar { padding: 0 20px; }
            .welcome-badge { display: none; }
        }
    </style>
</head>
<body>
<div class="app">

    <!-- Sidebar -->
    <?php $activePage = 'dashboard'; include __DIR__ . '/partials/sidebar.php'; ?>

    <!-- Main -->
    <div class="main">

        <!-- Topbar -->
        <header class="topbar">
            <div class="topbar-left">
                <h2>Dashboard</h2>
                <p><?= $now->format('l, F j, Y') ?></p>
            </div>
            <div class="topbar-right">
                <?php require __DIR__ . '/partials/logout-button.php'; ?>
            </div>
        </header>

        <!-- Page Body -->
        <main class="page-body">

            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <div class="welcome-text">
                    <h1><?= $greeting ?>, <?= $firstName ?>! 👋</h1>
                    <p>Here's a quick overview of your bills this month.</p>
                </div>
                <div class="welcome-badge">
                    <div class="badge-label">Today</div>
                    <div class="badge-date"><?= $now->format('M j, Y') ?></div>
                </div>
            </div>

            <?php if ($overdueCount > 0): ?>
                <a href="reminders.php" style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:#FEF2F2;border:1px solid #FECACA;color:#B91C1C;border-radius:12px;padding:14px 20px;margin-bottom:24px;text-decoration:none;font-size:0.88rem;font-weight:600;">
                    <span>⚠ You have <?= $overdueCount ?> overdue bill<?= $overdueCount === 1 ? '' : 's' ?>. Pay <?= $overdueCount === 1 ? 'it' : 'them' ?> as soon as possible.</span>
                    <span>View reminders →</span>
                </a>
            <?php endif; ?>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= $billsThisMonth ?></div>
                        <div class="stat-label">Total Bills This Month</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon green">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value">₱<?= number_format($paidSum, 2) ?></div>
                        <div class="stat-label"><?= $paidCount ?> bill<?= $paidCount === 1 ? '' : 's' ?> paid this month</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon amber">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value">₱<?= number_format($unpaidSum, 2) ?></div>
                        <div class="stat-label"><?= $unpaidCount ?> bill<?= $unpaidCount === 1 ? '' : 's' ?> unpaid this month</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon blue">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= $upcomingCount ?></div>
                        <div class="stat-label">Due in the next 7 days</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon red">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= $overdueCount ?></div>
                        <div class="stat-label">Overdue bill<?= $overdueCount === 1 ? '' : 's' ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon green">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                        </svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value">₱<?= number_format($monthlyTotalExpenses, 2) ?></div>
                        <div class="stat-label">Total Expenses This Month</div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="content-grid">

                <!-- Upcoming Bills -->
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title">Upcoming Bills</span>
                        <span class="panel-badge amber">Next 7 Days</span>
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
                            <?php foreach ($upcomingBills as $ub): ?>
                                <div class="bill-item">
                                    <div class="bill-icon" style="background: rgba(59,130,246,0.1); color: var(--electric-blue);">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/>
                                        </svg>
                                    </div>
                                    <div class="bill-info">
                                        <div class="bill-name"><?= htmlspecialchars($ub['bill_name']) ?></div>
                                        <div class="bill-due">Due <?= htmlspecialchars((new DateTime($ub['due_date']))->format('M j, Y')) ?> · <?= htmlspecialchars($ub['category_name']) ?></div>
                                    </div>
                                    <div class="bill-amount">₱<?= number_format((float)$ub['amount'], 2) ?></div>
                                </div>
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
                            <a href="add_bill.php" class="action-btn">
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
</body>
</html>