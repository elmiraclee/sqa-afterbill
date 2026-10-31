<?php
/**
 * AfterBill - Payment History
 * Every recorded payment, filterable by month and method, with CSV export and undo.
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/csv.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

$paymentMethodLabels = [
    'cash'          => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'gcash'         => 'GCash',
    'debit_card'    => 'Debit Card',
    'credit_card'   => 'Credit Card',
    'other'         => 'Other',
];

// ?month=YYYY-MM (empty = all time), ?method=<enum>
$monthFilter = $_GET['month'] ?? '';
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthFilter)) {
    $monthFilter = '';
}
$methodFilter = $_GET['method'] ?? '';
if (!isset($paymentMethodLabels[$methodFilter])) {
    $methodFilter = '';
}

$sql = 'SELECT p.payment_id, p.payment_date, p.amount_paid, p.payment_method, p.notes,
               b.bill_id, b.bill_name, b.amount AS bill_amount, b.due_date, c.category_name
        FROM payments p
        JOIN bills b ON b.bill_id = p.bill_id
        JOIN categories c ON c.category_id = b.category_id
        WHERE b.user_id = ?';
$params = [$userId];

if ($monthFilter !== '') {
    $start = new DateTime($monthFilter . '-01');
    $sql .= ' AND p.payment_date BETWEEN ? AND ?';
    $params[] = $start->format('Y-m-d');
    $params[] = $start->format('Y-m-t');
}
if ($methodFilter !== '') {
    $sql .= ' AND p.payment_method = ?';
    $params[] = $methodFilter;
}
$sql .= ' ORDER BY p.payment_date DESC, p.payment_id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// CSV export of exactly what the current filters show.
if (($_GET['export'] ?? '') === 'csv') {
    $out = csvStart('afterbill-payments' . ($monthFilter !== '' ? '-' . $monthFilter : '') . '.csv');
    fputcsv($out, ['Payment Date', 'Bill', 'Category', 'Bill Due Date', 'Amount Paid', 'Method', 'Notes']);
    foreach ($payments as $p) {
        fputcsv($out, [
            $p['payment_date'],
            csvSafe($p['bill_name']),
            csvSafe($p['category_name']),
            $p['due_date'],
            number_format((float)$p['amount_paid'], 2, '.', ''),
            $paymentMethodLabels[$p['payment_method']],
            csvSafe($p['notes']),
        ]);
    }
    fclose($out);
    exit;
}

$totalPaid = 0.0;
$lateCount = 0;
$byMethod = [];
foreach ($payments as $p) {
    $totalPaid += (float)$p['amount_paid'];
    if ($p['payment_date'] > $p['due_date']) {
        $lateCount++;
    }
    $byMethod[$p['payment_method']] = ($byMethod[$p['payment_method']] ?? 0) + (float)$p['amount_paid'];
}
arsort($byMethod);
$topMethod = $byMethod ? $paymentMethodLabels[array_key_first($byMethod)] : '—';

$exportQuery = http_build_query(array_filter(['month' => $monthFilter, 'method' => $methodFilter, 'export' => 'csv']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments – AfterBill</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app">

    <?php $activePage = 'payments'; include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2>Payment History</h2>
                <p><?= count($payments) ?> payment<?= count($payments) === 1 ? '' : 's' ?> recorded</p>
            </div>
            <div class="topbar-right">
                <a href="payments.php?<?= htmlspecialchars($exportQuery) ?>" class="btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                    </svg>
                    Export CSV
                </a>
                <?php require __DIR__ . '/partials/logout-button.php'; ?>
            </div>
        </header>

        <main class="page-body">

            <?php if (isset($_GET['unpaid'])): ?>
                <div class="alert alert-success">Payment removed. The bill is unpaid again.</div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error">Could not remove that payment. Please try again.</div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card green">
                    <div class="stat-value">₱<?= number_format($totalPaid, 2) ?></div>
                    <div class="stat-label">Total paid<?= $monthFilter !== '' ? ' in ' . (new DateTime($monthFilter . '-01'))->format('F Y') : '' ?></div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-value"><?= count($payments) ?></div>
                    <div class="stat-label">Payments</div>
                </div>
                <div class="stat-card amber">
                    <div class="stat-value"><?= $lateCount ?></div>
                    <div class="stat-label">Paid after due date</div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-value" style="font-size:1.15rem;"><?= htmlspecialchars($topMethod) ?></div>
                    <div class="stat-label">Most used method</div>
                </div>
            </div>

            <form method="GET" action="" class="filter-bar">
                <div class="filter-group">
                    <label for="month">Month</label>
                    <input type="month" id="month" name="month" value="<?= htmlspecialchars($monthFilter) ?>" onchange="this.form.submit()">
                </div>
                <div class="filter-group">
                    <label for="method">Method</label>
                    <select id="method" name="method" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($paymentMethodLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $methodFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($monthFilter !== '' || $methodFilter !== ''): ?>
                    <a href="payments.php" class="btn-outline" style="padding:9px 16px;">Clear filters</a>
                <?php endif; ?>
            </form>

            <div class="panel">
                <?php if (!$payments): ?>
                    <div class="empty-state">
                        No payments found. Record one from <a href="bills.php">My Bills</a> using “Mark as Paid”.
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Paid On</th>
                                    <th>Bill</th>
                                    <th>Category</th>
                                    <th>Amount Paid</th>
                                    <th>Method</th>
                                    <th>Notes</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $p): ?>
                                    <?php $late = $p['payment_date'] > $p['due_date']; ?>
                                    <tr>
                                        <td>
                                            <?= (new DateTime($p['payment_date']))->format('M j, Y') ?>
                                            <?php if ($late): ?><br><span class="status-badge overdue" style="margin-top:4px;">Late</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="strong"><?= htmlspecialchars($p['bill_name']) ?></span><br>
                                            <span class="muted">Due <?= (new DateTime($p['due_date']))->format('M j, Y') ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($p['category_name']) ?></td>
                                        <td class="strong">₱<?= number_format((float)$p['amount_paid'], 2) ?></td>
                                        <td><?= $paymentMethodLabels[$p['payment_method']] ?></td>
                                        <td class="muted"><?= $p['notes'] !== null ? htmlspecialchars($p['notes']) : '—' ?></td>
                                        <td>
                                            <form method="POST" action="undo_payment.php" class="inline-form"
                                                onsubmit="return confirm('Remove this payment? The bill will go back to unpaid.');">
                                                <input type="hidden" name="payment_id" value="<?= (int)$p['payment_id'] ?>">
                                                <input type="hidden" name="return" value="payments">
<<<<<<< HEAD
=======
                                                <?= csrf_field() ?>
>>>>>>> origin/sqa/audit-fixes-and-features
                                                <button type="submit" class="btn-outline red">Undo</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3">Total</td>
                                    <td>₱<?= number_format($totalPaid, 2) ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
</body>
</html>
