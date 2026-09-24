<?php
/**
 * AfterBill - Reports
 * All figures are computed live from the bills table for the logged-in
 * user, using the same filters as the CSV export (reports_export.php).
 */

require_once __DIR__ . '/includes/auth.php';
require_login();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/reports_repo.php';

$userId = current_user_id();
$user = get_user($userId);
$userName = $user['full_name'] ?? ($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));
$currency = $user['currency'] ?? 'PHP';

$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to'   => $_GET['date_to'] ?? '',
    'year'      => $_GET['year'] ?? '',
    'month'     => $_GET['month'] ?? '',
    'category'  => $_GET['category'] ?? '',
    'status'    => $_GET['status'] ?? '',
];

$bills = reports_fetch_bills($userId, $filters);
$summary = reports_summarize($bills);
$availableYears = reports_available_years($userId);

$monthLabels = [];
$monthValues = [];
foreach ($summary['by_month'] as $ym => $amount) {
    $monthLabels[] = (new DateTime($ym . '-01'))->format('M Y');
    $monthValues[] = round($amount, 2);
}

$categoryLabels = array_keys($summary['by_category']);
$categoryValues = array_map(fn($v) => round($v, 2), array_values($summary['by_category']));
$maxCategoryValue = !empty($categoryValues) ? max($categoryValues) : 0;

$pageTitle = 'Reports';
$pageSubtitle = 'Financial insights across all your bills.';
$activePage = 'reports';
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
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="app">

    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <?php include __DIR__ . '/partials/topbar.php'; ?>

        <main class="page-body">
            <?php include __DIR__ . '/partials/alerts.php'; ?>

            <form method="GET" class="toolbar no-print">
                <input type="date" name="date_from" class="input" style="width:auto;" value="<?= e($filters['date_from']) ?>" title="From date">
                <input type="date" name="date_to" class="input" style="width:auto;" value="<?= e($filters['date_to']) ?>" title="To date">

                <select name="year" class="input" style="width:auto;">
                    <option value="">All Years</option>
                    <?php foreach ($availableYears as $y): ?>
                        <option value="<?= (int)$y ?>" <?= (string)$filters['year'] === (string)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="month" class="input" style="width:auto;">
                    <option value="">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= (string)$filters['month'] === (string)$m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                    <?php endfor; ?>
                </select>

                <select name="category" class="input" style="width:auto;">
                    <option value="">All Categories</option>
                    <?php foreach (BILL_CATEGORIES as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= $filters['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="input" style="width:auto;">
                    <option value="">All Statuses</option>
                    <option value="upcoming" <?= $filters['status'] === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    <option value="paid" <?= $filters['status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="overdue" <?= $filters['status'] === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                </select>

                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                <a href="reports.php" class="btn btn-outline btn-sm">Reset</a>

                <span style="flex:1;"></span>

                <a href="reports_export.php?<?= http_build_query($filters) ?>" class="btn btn-outline btn-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 12m0 0l4.5-4.5M12 12V3"/></svg>
                    Export CSV
                </a>
                <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z"/></svg>
                    Print
                </button>
            </form>

            <div class="stats-grid stats-grid-4">
                <div class="stat-card">
                    <div class="stat-icon blue">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08"/></svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= (int)$summary['total_bills'] ?></div>
                        <div class="stat-label">Total Bills · <?= format_money($summary['total_amount'], $currency) ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= format_money($summary['total_paid'], $currency) ?></div>
                        <div class="stat-label">Total Paid · <?= (int)$summary['paid_count'] ?> bills</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon amber">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= format_money($summary['upcoming_amount'], $currency) ?></div>
                        <div class="stat-label">Upcoming · <?= (int)$summary['upcoming_count'] ?> bills</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon red">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z"/></svg>
                    </div>
                    <div class="stat-body">
                        <div class="stat-value"><?= format_money($summary['overdue_amount'], $currency) ?></div>
                        <div class="stat-label">Overdue · <?= (int)$summary['overdue_count'] ?> bills</div>
                    </div>
                </div>
            </div>

            <div class="report-grid">
                <div class="panel">
                    <div class="panel-header"><span class="panel-title">Monthly Spending Trend</span></div>
                    <div class="chart-wrap">
                        <?php if (empty($monthValues)): ?>
                            <div class="empty-state"><p>No data for the selected filters.</p></div>
                        <?php else: ?>
                            <canvas id="trendChart" height="220"></canvas>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header"><span class="panel-title">Payment Status</span></div>
                    <div class="chart-wrap">
                        <?php if ($summary['total_bills'] === 0): ?>
                            <div class="empty-state"><p>No data for the selected filters.</p></div>
                        <?php else: ?>
                            <canvas id="statusChart" height="220"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="panel" style="margin-bottom:20px;">
                <div class="panel-header"><span class="panel-title">Spending by Category</span></div>
                <div class="panel-body padded">
                    <?php if (empty($categoryLabels)): ?>
                        <div class="empty-state"><p>No data for the selected filters.</p></div>
                    <?php else: ?>
                        <?php foreach ($summary['by_category'] as $cat => $amount): ?>
                            <div class="category-bar-row">
                                <span class="cat-label"><?= category_icon($cat) ?> <?= e($cat) ?></span>
                                <div class="category-bar-track">
                                    <div class="category-bar-fill" style="width: <?= $maxCategoryValue > 0 ? round(($amount / $maxCategoryValue) * 100) : 0 ?>%;"></div>
                                </div>
                                <span class="cat-amount"><?= format_money($amount, $currency) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header"><span class="panel-title">Bill Details</span><span class="panel-badge blue"><?= count($bills) ?> results</span></div>
                <div class="table-wrap">
                    <?php if (empty($bills)): ?>
                        <div class="empty-state"><p>No bills match the selected filters.</p></div>
                    <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Bill</th><th>Category</th><th>Amount</th><th>Due Date</th><th>Status</th><th>Paid On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bills as $bill):
                                $display = bill_display_status($bill['status'], $bill['due_date']);
                            ?>
                            <tr>
                                <td class="cell-name"><?= e($bill['name']) ?></td>
                                <td><span class="category-pill"><?= category_icon($bill['category']) ?> <?= e($bill['category']) ?></span></td>
                                <td><?= format_money((float)$bill['amount'], $currency) ?></td>
                                <td><?= (new DateTime($bill['due_date']))->format('M j, Y') ?></td>
                                <td><span class="bill-status <?= status_badge_class($display) ?>"><?= status_label($display) ?></span></td>
                                <td class="cell-muted"><?= $bill['paid_at'] ? (new DateTime($bill['paid_at']))->format('M j, Y') : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="assets/js/app.js"></script>
<script>
    var monthLabels = <?= json_encode($monthLabels) ?>;
    var monthValues = <?= json_encode($monthValues) ?>;

    if (monthValues.length) {
        new Chart(document.getElementById('trendChart'), {
            type: 'bar',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: 'Amount',
                    data: monthValues,
                    backgroundColor: '#3B82F6',
                    borderRadius: 6,
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }

    var statusTotals = {
        paid: <?= (float)$summary['total_paid'] ?>,
        upcoming: <?= (float)$summary['upcoming_amount'] ?>,
        overdue: <?= (float)$summary['overdue_amount'] ?>
    };

    if (<?= $summary['total_bills'] ?> > 0) {
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Paid', 'Upcoming', 'Overdue'],
                datasets: [{
                    data: [statusTotals.paid, statusTotals.upcoming, statusTotals.overdue],
                    backgroundColor: ['#10B981', '#3B82F6', '#EF4444'],
                    borderWidth: 0,
                }]
            },
            options: {
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }
</script>
</body>
</html>
