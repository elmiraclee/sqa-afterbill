<?php
/**
 * AfterBill - Attach (or replace / remove) the receipt of an existing payment
 * POST-only action endpoint (no HTML) — redirects back to payments.php.
 *
 *   payment_id + receipt file   attach, replacing any receipt the payment already has
 *   payment_id + remove=1       delete the payment's receipt
 */

require_once __DIR__ . '/includes/auth.php';
require_login(); // session check, idle timeout, sign-out after a password change

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/payments.php';

function backToPayments(string $query): void
{
    header('Location: payments.php?' . $query);
    exit;
}

/** Errors travel in the session, not the URL, so a link can't put made-up text on the page. */
function receiptFailed(string $message): void
{
    $_SESSION['flash_receipt_error'] = $message;
    backToPayments('receipt=error');
}

// A file bigger than post_max_size arrives with $_POST empty, so it fails the CSRF check:
// say "too large" rather than a confusing session error.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    receiptFailed('The receipt is too large. The maximum is 5 MB.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    header('Location: payments.php');
    exit;
}

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

$paymentId = $_POST['payment_id'] ?? '';
if (!ctype_digit((string)$paymentId)) {
    header('Location: payments.php');
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT p.payment_id, p.receipt_file, p.payment_date, b.bill_name
         FROM payments p JOIN bills b ON b.bill_id = p.bill_id
         WHERE p.payment_id = ? AND b.user_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$paymentId, $userId]);
    $payment = $stmt->fetch();
} catch (PDOException $e) {
    receiptFailed('Receipts are not set up in this database yet. Run database/migrations/004_categories_payments_reminders.sql.');
}

// Not found or someone else's: nothing to do.
if (!$payment) {
    header('Location: payments.php');
    exit;
}
$label = $payment['bill_name'] . ' — paid ' . (new DateTime($payment['payment_date']))->format('M j, Y');

if (($_POST['remove'] ?? '') === '1') {
    if ($payment['receipt_file'] !== null) {
        $pdo->prepare('UPDATE payments SET receipt_file = NULL WHERE payment_id = ?')->execute([(int)$payment['payment_id']]);
        deleteReceiptFile($payment['receipt_file']);
        logActivity($pdo, $userId, 'receipt_removed', $label);
    }
    backToPayments('receipt=removed');
}

[$ext, $error] = checkReceiptUpload($_FILES['receipt'] ?? null);
if ($error === null && $ext === null) {
    $error = 'Choose a receipt file to attach.';
}
if ($error !== null) {
    receiptFailed($error);
}

$newFile = storeReceipt($_FILES['receipt']['tmp_name'], $ext);
if ($newFile === null) {
    receiptFailed('The receipt could not be saved. Please try again.');
}

try {
    $pdo->prepare('UPDATE payments SET receipt_file = ? WHERE payment_id = ?')->execute([$newFile, (int)$payment['payment_id']]);
} catch (PDOException $e) {
    deleteReceiptFile($newFile);
    receiptFailed('The receipt could not be saved. Please try again.');
}
// The old file goes only after the row points at the new one.
deleteReceiptFile($payment['receipt_file']);
logActivity($pdo, $userId, 'receipt_attached', $label . ($payment['receipt_file'] !== null ? ' (replaced)' : ''));

backToPayments('receipt=attached');
