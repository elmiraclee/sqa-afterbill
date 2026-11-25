<?php
/**
 * AfterBill - Mark several bills as paid at once (from My Bills)
 * POST-only action endpoint (no HTML) — redirects back to bills.php.
 *
 * Each selected bill gets one payment for its whole remaining balance (so a partially paid bill
 * is paid off), with the chosen date and method. Bills that are already paid, or aren't the
 * user's, are skipped. Each bill is its own transaction, locked like mark_paid.php.
 */

require_once __DIR__ . '/includes/auth.php';
require_login(); // session check, idle timeout, sign-out after a password change

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/payments.php';

const BULK_PAY_MAX = 100;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    header('Location: bills.php');
    exit;
}

function bulkFailed(string $message): void
{
    $_SESSION['flash_bulk_error'] = $message;
    header('Location: bills.php');
    exit;
}

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

$ids = $_POST['bill_ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}
$ids = array_values(array_unique(array_map('intval', array_filter($ids, fn($id) => is_string($id) && ctype_digit($id)))));
if (!$ids) {
    bulkFailed('Tick at least one bill to pay.');
}
if (count($ids) > BULK_PAY_MAX) {
    bulkFailed('You can pay up to ' . BULK_PAY_MAX . ' bills at once.');
}

$paymentDate = trim((string)($_POST['payment_date'] ?? ''));
$dateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $paymentDate || $paymentDate > date('Y-m-d') || $paymentDate < '2000-01-01') {
    bulkFailed('Please enter a valid payment date that is not in the future.');
}

$methods = ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'gcash' => 'GCash',
            'debit_card' => 'Debit Card', 'credit_card' => 'Credit Card', 'other' => 'Other'];
$method = $_POST['payment_method'] ?? '';
if (!is_string($method) || !isset($methods[$method])) {
    bulkFailed('Please choose a payment method.');
}

$paid = 0;
$total = 0.0;
$lock = $pdo->prepare('SELECT bill_id, bill_name, amount, status FROM bills WHERE bill_id = ? AND user_id = ? FOR UPDATE');
$insert = $pdo->prepare(
    'INSERT INTO payments (bill_id, payment_date, amount_paid, payment_method, notes) VALUES (?, ?, ?, ?, ?)'
);

foreach ($ids as $billId) {
    try {
        $pdo->beginTransaction();
        $lock->execute([$billId, $userId]);
        $bill = $lock->fetch();
        $owedCents = $bill ? toCents($bill['amount']) - toCents(billPaidTotal($pdo, $billId)) : 0;
        if (!$bill || $bill['status'] !== 'unpaid' || $owedCents <= 0) {
            $pdo->rollBack();
            continue; // someone else's, already paid, or nothing left to pay
        }
        $insert->execute([$billId, $paymentDate, $owedCents / 100, $method, 'Paid with "Pay selected"']);
        refreshBillStatus($pdo, $billId);
        $pdo->commit();

        $paid++;
        $total += $owedCents / 100;
        logActivity($pdo, $userId, 'payment_recorded', sprintf(
            '%s — %s via %s (paid with several bills)', $bill['bill_name'], activityMoney($owedCents / 100), $methods[$method]
        ));
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

if ($paid === 0) {
    bulkFailed('None of the selected bills needed paying.');
}
$_SESSION['flash_bulk_paid'] = sprintf('%d bill%s marked as paid · ₱%s recorded.', $paid, $paid === 1 ? '' : 's', number_format($total, 2));
header('Location: bills.php');
exit;
