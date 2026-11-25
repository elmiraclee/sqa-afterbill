<?php
/**
 * AfterBill - View a payment receipt
 * GET receipt.php?payment_id=N — sends the file only to the user who owns the payment's bill.
 * Images open in the browser; PDFs download (a PDF shown inline could run its own scripts).
 */

require_once __DIR__ . '/includes/auth.php';
require_login(); // session check, idle timeout, sign-out after a password change

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/payments.php';

function receiptNotFound(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Receipt not found.';
    exit;
}

$paymentId = $_GET['payment_id'] ?? '';
if (!ctype_digit((string)$paymentId)) {
    receiptNotFound();
}

try {
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT p.receipt_file, p.payment_date, b.bill_name
         FROM payments p JOIN bills b ON b.bill_id = p.bill_id
         WHERE p.payment_id = ? AND b.user_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$paymentId, (int)$_SESSION['user_id']]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    $row = false; // migration 004 not run: no receipts exist
}

// Someone else's payment looks exactly like one that doesn't exist.
$path = $row ? receiptPath($row['receipt_file']) : null;
if ($path === null || !is_file($path)) {
    receiptNotFound();
}

$ext = pathinfo($path, PATHINFO_EXTENSION);
$downloadName = 'receipt-' . preg_replace('/[^A-Za-z0-9]+/', '-', $row['bill_name']) . '-' . $row['payment_date'] . '.' . $ext;
$downloadName = trim(preg_replace('/-+/', '-', $downloadName), '-');

header('Content-Type: ' . RECEIPT_MIME_BY_EXT[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($ext === 'pdf' ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
