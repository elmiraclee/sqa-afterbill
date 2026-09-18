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

// Only ever reached via the confirmed POST form on bills.php.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$stmt = $pdo->prepare('SELECT bill_id FROM bills WHERE bill_id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$billId, $_SESSION['user_id']]);

// Not found or not owned by this user — nothing to delete here.
if (!$stmt->fetch()) {
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
    $delete = $pdo->prepare('DELETE FROM bills WHERE bill_id = ? AND user_id = ?');
    $delete->execute([$billId, $_SESSION['user_id']]);
    header('Location: bills.php?deleted=1');
    exit;
} catch (PDOException $e) {
    header('Location: bills.php?delete_blocked=1');
    exit;
}
