<?php
/**
 * AfterBill - CSV export for Reports (same filters as reports.php).
 */

require_once __DIR__ . '/includes/auth.php';
require_login();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/reports_repo.php';

$userId = current_user_id();

$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to'   => $_GET['date_to'] ?? '',
    'year'      => $_GET['year'] ?? '',
    'month'     => $_GET['month'] ?? '',
    'category'  => $_GET['category'] ?? '',
    'status'    => $_GET['status'] ?? '',
];

$bills = reports_fetch_bills($userId, $filters);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="afterbill-report-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Bill Name', 'Category', 'Amount', 'Due Date', 'Frequency', 'Status', 'Paid On', 'Notes']);

foreach ($bills as $bill) {
    $display = bill_display_status($bill['status'], $bill['due_date']);
    fputcsv($out, [
        $bill['name'],
        $bill['category'],
        number_format((float)$bill['amount'], 2, '.', ''),
        $bill['due_date'],
        $bill['frequency'],
        status_label($display),
        $bill['paid_at'] ? substr($bill['paid_at'], 0, 10) : '',
        $bill['notes'] ?? '',
    ]);
}

fclose($out);
exit;
