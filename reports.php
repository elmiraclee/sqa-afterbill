<?php
/**
 * AfterBill - Monthly Reports
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

// Same as dashboard.php/bills.php/calendar.php: catch up recurring bills before querying.
generateMissedRecurringBills($pdo, (int)$_SESSION['user_id']);

// Resolve the selected month from ?month=YYYY-MM, defaulting to the current month.
$monthParam = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$monthStart = new DateTime($monthParam . '-01');
$monthEnd = (clone $monthStart)->modify('last day of this month');
$prevMonth = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthStart)->modify('+1 month')->format('Y-m');

// 1) Selected month, grouped by status: count + sum for paid and unpaid in one query.
$statusStmt = $pdo->prepare(
    'SELECT status, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
     FROM bills
     WHERE user_id = ?
       AND due_date BETWEEN ? AND ?
     GROUP BY status'
);
$statusStmt->execute([
    $_SESSION['user_id'],
    $monthStart->format('Y-m-d'),
    $monthEnd->format('Y-m-d'),
]);

$paidCount = 0;
$paidSum = 0.0;
$unpaidCount = 0;
$unpaidSum = 0.0;
foreach ($statusStmt->fetchAll() as $row) {
    if ($row['status'] === 'paid') {
        $paidCount = (int)$row['cnt'];
        $paidSum = (float)$row['total'];
    } elseif ($row['status'] === 'unpaid') {
        $unpaidCount = (int)$row['cnt'];
        $unpaidSum = (float)$row['total'];
    }
}
$totalCount = $paidCount + $unpaidCount;
$totalExpenses = $paidSum + $unpaidSum;

// 2) Selected month, grouped by category (paid + unpaid combined), largest first. Only
// categories that actually have bills that month are returned; the UI treats the rest as zero.
$categoryStmt = $pdo->prepare(
    'SELECT c.category_id, c.category_name, COUNT(*) AS cnt, SUM(b.amount) AS total
     FROM bills b
     JOIN categories c ON c.category_id = b.category_id
     WHERE b.user_id = ?
       AND b.due_date BETWEEN ? AND ?
     GROUP BY c.category_id, c.category_name
     ORDER BY total DESC, c.category_name ASC'
);
$categoryStmt->execute([
    $_SESSION['user_id'],
    $monthStart->format('Y-m-d'),
    $monthEnd->format('Y-m-d'),
]);
$categoryTotals = $categoryStmt->fetchAll();

// Donut chart geometry. Colors are keyed by category_id so a category keeps the same color
// in every month, and deliberately avoid green/amber/red, which this page already uses for
// paid/unpaid/overdue. Each segment is a dashed arc on one circle: dash = arc length, offset =
// where it starts, and a 2px gap separates segments when there is more than one.
$chartPalette = ['#3B82F6', '#06B6D4', '#8B5CF6', '#EC4899', '#64748B', '#92400E', '#D946EF'];
$donutRadius = 70;
$donutCircumference = 2 * M_PI * $donutRadius;
$donutSegments = [];
$donutOffset = 0.0;
foreach ($categoryTotals as $catRow) {
    $catTotal = (float)$catRow['total'];
    $fraction = $totalExpenses > 0 ? $catTotal / $totalExpenses : 0;
    $arc = $fraction * $donutCircumference;
    $gap = count($categoryTotals) > 1 ? 2 : 0;
    $donutSegments[] = [
        'name'   => $catRow['category_name'],
        'cnt'    => (int)$catRow['cnt'],
        'total'  => $catTotal,
        'share'  => round($fraction * 100, 1),
        'color'  => $chartPalette[((int)$catRow['category_id'] - 1) % count($chartPalette)],
        'dash'   => max($arc - $gap, 0),
        'offset' => $donutOffset,
    ];
    $donutOffset += $arc;
}

// 3) Spending history: the current month plus the 5 before it (independent of the selected
// month above). Split by paid/unpaid so the chart can stack them; months with no bills are
// filled in as 0 so there are always exactly 6 columns in chronological order.
$historyStart = (new DateTime(date('Y-m-01')))->modify('-5 months');
$historyEnd = new DateTime(date('Y-m-t'));

$historyStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(due_date, '%Y-%m') AS ym, status, COUNT(*) AS cnt, SUM(amount) AS total
     FROM bills
     WHERE user_id = ?
       AND due_date BETWEEN ? AND ?
     GROUP BY ym, status"
);
$historyStmt->execute([
    $_SESSION['user_id'],
    $historyStart->format('Y-m-d'),
    $historyEnd->format('Y-m-d'),
]);
$historyByMonth = [];
foreach ($historyStmt->fetchAll() as $row) {
    $ym = $row['ym'];
    $historyByMonth[$ym] ??= ['cnt' => 0, 'paid' => 0.0, 'unpaid' => 0.0];
    $historyByMonth[$ym]['cnt'] += (int)$row['cnt'];
    if ($row['status'] === 'paid' || $row['status'] === 'unpaid') {
        $historyByMonth[$ym][$row['status']] += (float)$row['total'];
    }
}

$spendingHistory = [];
$maxHistoryTotal = 0.0;
for ($i = 0; $i < 6; $i++) {
    $m = (clone $historyStart)->modify("+$i months");
    $key = $m->format('Y-m');
    $entry = $historyByMonth[$key] ?? ['cnt' => 0, 'paid' => 0.0, 'unpaid' => 0.0];
    $total = $entry['paid'] + $entry['unpaid'];
    $spendingHistory[] = [
        'key'    => $key,
        'label'  => $m->format('M Y'),
        'cnt'    => $entry['cnt'],
        'paid'   => $entry['paid'],
        'unpaid' => $entry['unpaid'],
        'total'  => $total,
    ];
    $maxHistoryTotal = max($maxHistoryTotal, $total);
}

// Column chart geometry (SVG user units; the SVG scales to its container). The y-axis is
// rounded up to a "nice" step (1, 2, 2.5, 5 x 10^n) with at most 4 gridlines.
function chartCompactAmount(float $n): string
{
    if ($n >= 1000000) {
        return '₱' . rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return '₱' . rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k';
    }
    return '₱' . number_format($n, 0);
}

function chartNiceStep(float $rough): float
{
    $pow = 10 ** floor(log10($rough));
    foreach ([1, 2, 2.5, 5, 10] as $mult) {
        if ($mult * $pow >= $rough) {
            return $mult * $pow;
        }
    }
    return 10 * $pow;
}

$chartW = 640;
$plotLeft = 56;
$plotRight = 624;
$plotTop = 22;
$plotBottom = 206;
$plotHeight = $plotBottom - $plotTop;
$slotWidth = ($plotRight - $plotLeft) / 6;
$barWidth = 44;
$axisStep = $maxHistoryTotal > 0 ? chartNiceStep($maxHistoryTotal / 4) : 1;
$axisTicks = max(1, (int)ceil($maxHistoryTotal / $axisStep - 1e-9));
$axisMax = $axisStep * $axisTicks;

// 4) Analytics for the selected month.
$prevMonthStart = (clone $monthStart)->modify('-1 month');
$prevMonthEnd = (clone $prevMonthStart)->modify('last day of this month');
$prevStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(amount), 0) AS total
     FROM bills
     WHERE user_id = ?
       AND due_date BETWEEN ? AND ?'
);
$prevStmt->execute([
    $_SESSION['user_id'],
    $prevMonthStart->format('Y-m-d'),
    $prevMonthEnd->format('Y-m-d'),
]);
$prevTotal = (float)$prevStmt->fetchColumn();

// null = "nothing meaningful to compare/average", rendered as an em dash in the UI.
$monthChangePct = $prevTotal > 0 ? ($totalExpenses - $prevTotal) / $prevTotal * 100 : null;
$paidRate = $totalExpenses > 0 ? $paidSum / $totalExpenses * 100 : null;
$avgPerBill = $totalCount > 0 ? $totalExpenses / $totalCount : null;
$topCategory = $donutSegments[0] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports – AfterBill</title>
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

        .app { display: flex; min-height: 100vh; }

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

        .sidebar-logo img { height: 52px; width: auto; display: block; filter: brightness(0) invert(1); opacity: 0.9; }

        .sidebar-nav { flex: 1; padding: 16px 12px; display: flex; flex-direction: column; gap: 2px; }

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
        }

        .nav-item:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.9); }
        .nav-item.active { background: rgba(59, 130, 246, 0.2); color: #fff; }
        .nav-item svg { width: 18px; height: 18px; flex-shrink: 0; }

        .sidebar-footer { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.07); }

        .user-card { display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: 10px; }

        .user-avatar {
            width: 34px; height: 34px;
            background: var(--electric-blue);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem; font-weight: 700; color: #fff;
            flex-shrink: 0;
        }

        .user-info { flex: 1; min-width: 0; }
        .user-name { font-size: 0.82rem; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .main { margin-left: var(--sidebar-width); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

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

        .topbar-left h2 { font-size: 1.1rem; font-weight: 700; color: var(--deep-navy); }
        .topbar-left p { font-size: 0.8rem; color: var(--text-muted); }

        .btn-logout {
            display: flex; align-items: center; gap: 6px;
            padding: 8px 16px;
            background: none;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.82rem; font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            transition: border-color 0.18s, color 0.18s, background 0.18s;
        }

        .btn-logout:hover { border-color: var(--danger); color: var(--danger); background: #FEF2F2; }
        .btn-logout svg { width: 15px; height: 15px; }

        .page-body { padding: 32px; flex: 1; display: flex; flex-direction: column; gap: 20px; }

        .panel {
            background: var(--white);
            border-radius: 14px;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
        }

        .panel-header h3 { font-size: 1rem; font-weight: 700; color: var(--deep-navy); }
        .panel-header p { font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; }

        .month-nav { display: flex; align-items: center; gap: 8px; }

        .btn-nav {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            background: none;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
        }

        .btn-nav:hover { border-color: var(--electric-blue); color: var(--electric-blue); }
        .btn-nav svg { width: 16px; height: 16px; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .stat-card {
            background: var(--white);
            border-radius: 14px;
            padding: 22px 24px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 16px;
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

        .stat-icon svg { width: 22px; height: 22px; }

        .stat-icon.blue  { background: rgba(59,130,246,0.1); color: var(--electric-blue); }
        .stat-icon.green { background: rgba(16,185,129,0.1); color: var(--success); }
        .stat-icon.amber { background: rgba(245,158,11,0.1); color: var(--warning); }

        .stat-value { font-size: 1.5rem; font-weight: 800; color: var(--deep-navy); line-height: 1.1; }
        .stat-label { font-size: 0.78rem; color: var(--text-muted); margin-top: 2px; }

        .bar-track { height: 10px; background: var(--light-gray); border-radius: 999px; overflow: hidden; }
        .bar-fill { height: 100%; background: var(--electric-blue); border-radius: 999px; }
        .bar-fill.green { background: var(--success); }

        .insights-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }

        .insight-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px 20px;
            min-width: 0;
        }

        .insight-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }

        .insight-value {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--deep-navy);
            margin-top: 6px;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .insight-value.up   { color: var(--danger); }
        .insight-value.down { color: var(--success); }

        .insight-track { margin-top: 8px; }
        .insight-sub { font-size: 0.78rem; color: var(--text-muted); margin-top: 6px; }

        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: start;
        }

        .donut-wrap {
            display: flex;
            align-items: center;
            gap: 28px;
            padding: 22px;
            flex-wrap: wrap;
        }

        .donut-svg { width: 190px; height: 190px; flex-shrink: 0; }
        .donut-total { font-size: 15px; font-weight: 800; fill: var(--deep-navy); font-family: inherit; }
        .donut-sub { font-size: 11px; fill: var(--text-muted); font-family: inherit; }

        .legend { flex: 1; min-width: 220px; }

        .legend-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
        }

        .legend-row:last-child { border-bottom: none; }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .legend-name { flex: 1; min-width: 0; font-size: 0.85rem; font-weight: 600; color: var(--text-dark); }
        .legend-name span, .legend-value span { font-weight: 500; font-size: 0.75rem; color: var(--text-muted); margin-left: 6px; }
        .legend-value { font-size: 0.85rem; font-weight: 700; color: var(--deep-navy); white-space: nowrap; }

        .chart-wrap { padding: 12px 18px 18px; }
        .column-chart { width: 100%; height: auto; display: block; }
        .column-chart text { font-family: inherit; }

        .axis-grid { stroke: var(--border); stroke-width: 1; stroke-dasharray: 3 4; }
        .axis-base { stroke: #CBD5E1; stroke-width: 1; }
        .axis-label { font-size: 11px; fill: var(--text-muted); }
        .value-label { font-size: 11px; font-weight: 700; fill: var(--deep-navy); }
        .month-label { font-size: 12px; font-weight: 500; fill: var(--text-dark); }
        .month-label.month-selected { fill: var(--electric-blue); font-weight: 700; }

        .slot { fill: transparent; }
        .slot:hover { fill: #F3F4F6; }
        .slot.slot-selected { fill: #EFF6FF; }
        .bar-paid { fill: #10B981; }
        .bar-unpaid { fill: #F59E0B; }

        .chart-legend { display: flex; justify-content: center; gap: 18px; margin-top: 6px; font-size: 0.78rem; color: var(--text-muted); }
        .chart-legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }

        .empty-state { padding: 40px 22px; text-align: center; color: var(--text-muted); font-size: 0.85rem; }

        @media (max-width: 1200px) {
            .charts-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 1000px) {
            .insights-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 680px) {
            .sidebar { transform: translateX(-100%); }
            .main { margin-left: 0; }
            .page-body { padding: 20px; }
            .topbar { padding: 0 20px; }
            .insights-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app">

    <?php $activePage = 'reports'; include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2>Reports</h2>
                <p>Your monthly spending summary.</p>
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

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3><?= htmlspecialchars($monthStart->format('F Y')) ?></h3>
                        <p>Bills due this month, paid and unpaid.</p>
                    </div>
                    <div class="month-nav">
                        <a href="reports.php?month=<?= urlencode($prevMonth) ?>" class="btn-nav" aria-label="Previous month">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                            </svg>
                        </a>
                        <a href="reports.php?month=<?= urlencode($nextMonth) ?>" class="btn-nav" aria-label="Next month">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="stat-value">₱<?= number_format($totalExpenses, 2) ?></div>
                        <div class="stat-label">Total expenses · <?= $totalCount ?> bill<?= $totalCount === 1 ? '' : 's' ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon green">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="stat-value">₱<?= number_format($paidSum, 2) ?></div>
                        <div class="stat-label">Paid · <?= $paidCount ?> bill<?= $paidCount === 1 ? '' : 's' ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon amber">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="stat-value">₱<?= number_format($unpaidSum, 2) ?></div>
                        <div class="stat-label">Unpaid · <?= $unpaidCount ?> bill<?= $unpaidCount === 1 ? '' : 's' ?></div>
                    </div>
                </div>
            </div>

            <div class="insights-grid">
                <div class="insight-card">
                    <div class="insight-label">vs Previous Month</div>
                    <?php if ($monthChangePct === null): ?>
                        <div class="insight-value">—</div>
                        <div class="insight-sub">No bills in <?= htmlspecialchars($prevMonthStart->format('F Y')) ?> to compare.</div>
                    <?php else: ?>
                        <div class="insight-value <?= $monthChangePct > 0 ? 'up' : ($monthChangePct < 0 ? 'down' : '') ?>">
                            <?= $monthChangePct > 0 ? '▲' : ($monthChangePct < 0 ? '▼' : '') ?> <?= number_format(abs($monthChangePct), 1) ?>%
                        </div>
                        <div class="insight-sub">₱<?= number_format($prevTotal, 2) ?> in <?= htmlspecialchars($prevMonthStart->format('M Y')) ?></div>
                    <?php endif; ?>
                </div>

                <div class="insight-card">
                    <div class="insight-label">Paid Progress</div>
                    <?php if ($paidRate === null): ?>
                        <div class="insight-value">—</div>
                        <div class="insight-sub">No bills this month.</div>
                    <?php else: ?>
                        <div class="insight-value"><?= number_format($paidRate, 1) ?>%</div>
                        <div class="bar-track insight-track"><div class="bar-fill green" style="width: <?= round($paidRate, 1) ?>%;"></div></div>
                        <div class="insight-sub">₱<?= number_format($paidSum, 2) ?> of ₱<?= number_format($totalExpenses, 2) ?> paid</div>
                    <?php endif; ?>
                </div>

                <div class="insight-card">
                    <div class="insight-label">Average per Bill</div>
                    <?php if ($avgPerBill === null): ?>
                        <div class="insight-value">—</div>
                        <div class="insight-sub">No bills this month.</div>
                    <?php else: ?>
                        <div class="insight-value">₱<?= number_format($avgPerBill, 2) ?></div>
                        <div class="insight-sub">across <?= $totalCount ?> bill<?= $totalCount === 1 ? '' : 's' ?></div>
                    <?php endif; ?>
                </div>

                <div class="insight-card">
                    <div class="insight-label">Top Category</div>
                    <?php if ($topCategory === null): ?>
                        <div class="insight-value">—</div>
                        <div class="insight-sub">No bills this month.</div>
                    <?php else: ?>
                        <div class="insight-value"><?= htmlspecialchars($topCategory['name']) ?></div>
                        <div class="insight-sub"><?= $topCategory['share'] ?>% of spending · ₱<?= number_format($topCategory['total'], 2) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="charts-grid">

                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Expenses by Category</h3>
                            <p><?= htmlspecialchars($monthStart->format('F Y')) ?>, paid and unpaid combined.</p>
                        </div>
                    </div>

                    <?php if (empty($donutSegments)): ?>
                        <div class="empty-state">No bills due in <?= htmlspecialchars($monthStart->format('F Y')) ?>.</div>
                    <?php else: ?>
                        <div class="donut-wrap">
                            <svg class="donut-svg" viewBox="0 0 200 200" role="img"
                                 aria-label="Expenses by category for <?= htmlspecialchars($monthStart->format('F Y')) ?>">
                                <circle cx="100" cy="100" r="<?= $donutRadius ?>" fill="none" stroke="#F3F4F6" stroke-width="28"/>
                                <?php foreach ($donutSegments as $seg): ?>
                                    <circle cx="100" cy="100" r="<?= $donutRadius ?>" fill="none"
                                            stroke="<?= $seg['color'] ?>" stroke-width="28"
                                            stroke-dasharray="<?= round($seg['dash'], 2) ?> <?= round($donutCircumference - $seg['dash'], 2) ?>"
                                            stroke-dashoffset="<?= round(-$seg['offset'], 2) ?>"
                                            transform="rotate(-90 100 100)">
                                        <title><?= htmlspecialchars($seg['name']) ?>: ₱<?= number_format($seg['total'], 2) ?> (<?= $seg['share'] ?>%)</title>
                                    </circle>
                                <?php endforeach; ?>
                                <text x="100" y="98" text-anchor="middle" class="donut-total">
                                    <?= $totalExpenses >= 100000 ? chartCompactAmount($totalExpenses) : '₱' . number_format($totalExpenses, 2) ?>
                                </text>
                                <text x="100" y="116" text-anchor="middle" class="donut-sub"><?= $totalCount ?> bill<?= $totalCount === 1 ? '' : 's' ?></text>
                            </svg>

                            <div class="legend">
                                <?php foreach ($donutSegments as $seg): ?>
                                    <div class="legend-row">
                                        <span class="legend-dot" style="background: <?= $seg['color'] ?>;"></span>
                                        <span class="legend-name">
                                            <?= htmlspecialchars($seg['name']) ?>
                                            <span><?= $seg['cnt'] ?> bill<?= $seg['cnt'] === 1 ? '' : 's' ?></span>
                                        </span>
                                        <span class="legend-value">
                                            ₱<?= number_format($seg['total'], 2) ?>
                                            <span><?= $seg['share'] ?>%</span>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Spending History</h3>
                            <p>Last 6 months. Click a month to view its report.</p>
                        </div>
                    </div>

                    <?php if ($maxHistoryTotal <= 0): ?>
                        <div class="empty-state">No bills in the last 6 months.</div>
                    <?php else: ?>
                        <div class="chart-wrap">
                            <svg class="column-chart" viewBox="0 0 <?= $chartW ?> 240" role="img"
                                 aria-label="Monthly spending for the last 6 months, paid and unpaid">
                                <?php for ($t = 0; $t <= $axisTicks; $t++): ?>
                                    <?php $gy = round($plotBottom - $t / $axisTicks * $plotHeight, 1); ?>
                                    <line x1="<?= $plotLeft ?>" x2="<?= $plotRight ?>" y1="<?= $gy ?>" y2="<?= $gy ?>"
                                          class="<?= $t === 0 ? 'axis-base' : 'axis-grid' ?>"/>
                                    <text x="<?= $plotLeft - 8 ?>" y="<?= $gy + 4 ?>" text-anchor="end" class="axis-label"><?= chartCompactAmount($t * $axisStep) ?></text>
                                <?php endfor; ?>

                                <?php foreach ($spendingHistory as $i => $h): ?>
                                    <?php
                                        $slotX = $plotLeft + $i * $slotWidth;
                                        $barX = round($slotX + ($slotWidth - $barWidth) / 2, 1);
                                        $paidH = $h['paid'] / $axisMax * $plotHeight;
                                        $unpaidH = $h['unpaid'] / $axisMax * $plotHeight;
                                        $paidY = round($plotBottom - $paidH, 1);
                                        $unpaidY = round($plotBottom - $paidH - $unpaidH, 1);
                                        $isSelected = $h['key'] === $monthStart->format('Y-m');
                                    ?>
                                    <a href="reports.php?month=<?= urlencode($h['key']) ?>">
                                        <title><?= htmlspecialchars($h['label']) ?>: ₱<?= number_format($h['total'], 2) ?> (paid ₱<?= number_format($h['paid'], 2) ?>, unpaid ₱<?= number_format($h['unpaid'], 2) ?>)</title>
                                        <rect x="<?= round($slotX + 3, 1) ?>" y="<?= $plotTop - 12 ?>" width="<?= round($slotWidth - 6, 1) ?>" height="<?= $plotBottom - $plotTop + 40 ?>"
                                              rx="8" class="<?= $isSelected ? 'slot slot-selected' : 'slot' ?>"/>
                                        <?php if ($h['paid'] > 0): ?>
                                            <rect x="<?= $barX ?>" y="<?= $paidY ?>" width="<?= $barWidth ?>" height="<?= round($paidH, 1) ?>" class="bar-paid"/>
                                        <?php endif; ?>
                                        <?php if ($h['unpaid'] > 0): ?>
                                            <rect x="<?= $barX ?>" y="<?= $unpaidY ?>" width="<?= $barWidth ?>" height="<?= round($unpaidH, 1) ?>" class="bar-unpaid"/>
                                        <?php endif; ?>
                                        <?php if ($h['total'] > 0): ?>
                                            <text x="<?= round($slotX + $slotWidth / 2, 1) ?>" y="<?= $unpaidY - 6 ?>" text-anchor="middle" class="value-label"><?= chartCompactAmount($h['total']) ?></text>
                                        <?php endif; ?>
                                        <text x="<?= round($slotX + $slotWidth / 2, 1) ?>" y="<?= $plotBottom + 20 ?>" text-anchor="middle"
                                              class="<?= $isSelected ? 'month-label month-selected' : 'month-label' ?>"><?= htmlspecialchars($h['label']) ?></text>
                                    </a>
                                <?php endforeach; ?>
                            </svg>

                            <div class="chart-legend">
                                <span><i style="background: #10B981;"></i>Paid</span>
                                <span><i style="background: #F59E0B;"></i>Unpaid</span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </main>
    </div>
</div>
</body>
</html>
