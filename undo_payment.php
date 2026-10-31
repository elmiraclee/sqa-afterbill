<?php
/**
 * AfterBill - Undo Payment
 * POST-only action endpoint (no HTML).
 *
 * Accepts either:
 *   bill_id     removes every payment recorded for that bill (from bills.php)
 *   payment_id  removes that one payment (from payments.php)
 * In both cases the bill goes back to 'unpaid' once it has no payments left.
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
<<<<<<< HEAD

$returnTo = ($_POST['return'] ?? '') === 'payments' ? 'payments.php' : 'bills.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
=======
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/activity.php';

$returnTo = ($_POST['return'] ?? '') === 'payments' ? 'payments.php' : 'bills.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
>>>>>>> origin/sqa/audit-fixes-and-features
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
        $del = $pdo->prepare('DELETE FROM payments WHERE payment_id = ? AND bill_id = ?');
        $del->execute([(int)$paymentId, $ownedBillId]);
    } else {
        $del = $pdo->prepare('DELETE FROM payments WHERE bill_id = ?');
        $del->execute([$ownedBillId]);
    }

    $remaining = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE bill_id = ?');
    $remaining->execute([$ownedBillId]);
    if ((int)$remaining->fetchColumn() === 0) {
        $upd = $pdo->prepare('UPDATE bills SET status = \'unpaid\' WHERE bill_id = ? AND user_id = ?');
        $upd->execute([$ownedBillId, $userId]);
    }

    $pdo->commit();

    $nameStmt = $pdo->prepare('SELECT bill_name FROM bills WHERE bill_id = ?');
    $nameStmt->execute([$ownedBillId]);
    logActivity($pdo, $userId, 'payment_undone', (string)$nameStmt->fetchColumn());

    header('Location: ' . $returnTo . '?unpaid=1');
    exit;
} catch (PDOException $e) {
    $pdo->rollBack();
    header('Location: ' . $returnTo . '?error=1');
    exit;
}
