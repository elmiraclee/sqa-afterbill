<?php
/**
 * AfterBill - Undo Payment
 * POST-only action endpoint (no HTML).
 *
 * Accepts either:
 *   bill_id     removes every payment recorded for that bill (from bills.php)
 *   payment_id  removes that one payment (from payments.php)
 * Afterwards the bill's status is worked out again from the payments that are left
 * (includes/payments.php): paid only while they still cover the amount. Receipt files of the
 * removed payments are deleted.
 */

require_once __DIR__ . '/includes/auth.php';
require_login(); // session check, idle timeout, sign-out after a password change

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/payments.php';

$returnTo = ($_POST['return'] ?? '') === 'payments' ? 'payments.php' : 'bills.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    header('Location: ' . $returnTo);
    exit;
}

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

$billId = $_POST['bill_id'] ?? '';
$paymentId = $_POST['payment_id'] ?? '';

// Resolve the bill (and ownership) from whichever id was sent.
if (ctype_digit((string)$paymentId)) {
    $stmt = $pdo->prepare(
        'SELECT b.bill_id FROM payments p
         JOIN bills b ON b.bill_id = p.bill_id
         WHERE p.payment_id = ? AND b.user_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$paymentId, $userId]);
} elseif (ctype_digit((string)$billId)) {
    $stmt = $pdo->prepare('SELECT bill_id FROM bills WHERE bill_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([(int)$billId, $userId]);
} else {
    header('Location: ' . $returnTo);
    exit;
}

$ownedBillId = $stmt->fetchColumn();
if ($ownedBillId === false) {
    header('Location: ' . $returnTo);
    exit;
}
$ownedBillId = (int)$ownedBillId;

try {
    $pdo->beginTransaction();

    if (ctype_digit((string)$paymentId)) {
        $where = 'payment_id = ? AND bill_id = ?';
        $whereParams = [(int)$paymentId, $ownedBillId];
    } else {
        $where = 'bill_id = ?';
        $whereParams = [$ownedBillId];
    }

    try {
        $files = $pdo->prepare("SELECT receipt_file FROM payments WHERE $where AND receipt_file IS NOT NULL");
        $files->execute($whereParams);
        $receiptFiles = $files->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $receiptFiles = []; // migration 004 not run: there are no receipts
    }

    $pdo->prepare("DELETE FROM payments WHERE $where")->execute($whereParams);

    refreshBillStatus($pdo, $ownedBillId);

    $pdo->commit();

    // Files go only once the rows are gone for good.
    foreach ($receiptFiles as $file) {
        deleteReceiptFile($file);
    }

    $nameStmt = $pdo->prepare('SELECT bill_name FROM bills WHERE bill_id = ?');
    $nameStmt->execute([$ownedBillId]);
    logActivity($pdo, $userId, 'payment_undone', (string)$nameStmt->fetchColumn());

    header('Location: ' . $returnTo . '?unpaid=1');
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: ' . $returnTo . '?error=1');
    exit;
}
