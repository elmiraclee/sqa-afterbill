<?php
/**
 * AfterBill - Delete Bill
 * POST-only action endpoint (no HTML) — redirects back to bills.php.
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/activity.php';

// Only ever reached via the confirmed POST form on bills.php.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    header('Location: bills.php');
    exit;
}

$pdo = getPDO();

$billId = $_POST['bill_id'] ?? '';
if (!ctype_digit((string)$billId)) {
    header('Location: bills.php');
    exit;
}
$billId = (int)$billId;

$stmt = $pdo->prepare('SELECT bill_id, bill_name, amount, parent_bill_id FROM bills WHERE bill_id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$billId, $_SESSION['user_id']]);
$bill = $stmt->fetch();

// Not found or not owned by this user — nothing to delete here.
if (!$bill) {
    header('Location: bills.php');
    exit;
}

// A bill with recorded payments represents real payment history — refuse to delete it rather
// than silently cascading the payments away. The user must not lose that record by accident.
$paymentCheck = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE bill_id = ?');
$paymentCheck->execute([$billId]);
if ((int)$paymentCheck->fetchColumn() > 0) {
    header('Location: bills.php?delete_blocked=1');
    exit;
}

try {
    $pdo->beginTransaction();

    // A generated recurring cycle: once deleted, its parent would have no child again and the
    // recurring generator would re-create it on the next page load. Turning off is_recurring on
    // the parent stops that. If this bill has its own next cycle, that child keeps the series
    // going (its parent_bill_id is set to NULL by the FK).
    if ($bill['parent_bill_id'] !== null) {
        $stopParent = $pdo->prepare('UPDATE bills SET is_recurring = 0 WHERE bill_id = ? AND user_id = ?');
        $stopParent->execute([$bill['parent_bill_id'], $_SESSION['user_id']]);
    }

    $delete = $pdo->prepare('DELETE FROM bills WHERE bill_id = ? AND user_id = ?');
    $delete->execute([$billId, $_SESSION['user_id']]);

    $pdo->commit();
    logActivity($pdo, (int)$_SESSION['user_id'], 'bill_deleted',
        $bill['bill_name'] . ' — ' . activityMoney((float)$bill['amount']));
    header('Location: bills.php?deleted=1');
    exit;
} catch (PDOException $e) {
    $pdo->rollBack();
    header('Location: bills.php?delete_blocked=1');
    exit;
}
