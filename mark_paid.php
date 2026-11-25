<?php
/**
 * AfterBill - Record a Payment (full or partial), with an optional receipt
 *
 * The bill turns 'paid' once its payments add up to its amount (includes/payments.php); a smaller
 * payment leaves it partially paid with the rest still owed.
 */

require_once __DIR__ . '/includes/auth.php';
require_login(); // session check, idle timeout, sign-out after a password change

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/payments.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pdo = getPDO();

$billId = $_GET['bill_id'] ?? $_POST['bill_id'] ?? '';
if (!ctype_digit((string)$billId)) {
    header('Location: bills.php');
    exit;
}
$billId = (int)$billId;

$stmt = $pdo->prepare(
    'SELECT bill_id, bill_name, amount, status FROM bills WHERE bill_id = ? AND user_id = ? LIMIT 1'
);
$stmt->execute([$billId, $_SESSION['user_id']]);
$bill = $stmt->fetch();

// Not found, not owned by this user, or already paid — nothing to do here.
if (!$bill || $bill['status'] !== 'unpaid') {
    header('Location: bills.php');
    exit;
}

$paidSoFar = billPaidTotal($pdo, $billId);
$balanceCents = toCents($bill['amount']) - toCents($paidSoFar);
// Payments already cover it (e.g. the amount was lowered): fix the status instead of taking more.
if ($balanceCents <= 0) {
    refreshBillStatus($pdo, $billId);
    header('Location: bills.php');
    exit;
}
$balance = $balanceCents / 100;

$paymentMethods = ['cash', 'bank_transfer', 'gcash', 'debit_card', 'credit_card', 'other'];
$paymentMethodLabels = [
    'cash'          => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'gcash'         => 'GCash',
    'debit_card'    => 'Debit Card',
    'credit_card'   => 'Credit Card',
    'other'         => 'Other',
];

$errors = [];
$old = [
    'payment_date'   => date('Y-m-d'),
    'amount_paid'    => number_format($balance, 2, '.', ''),
    'payment_method' => '',
    'notes'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentDate   = trim($_POST['payment_date'] ?? '');
    $amountPaid    = trim($_POST['amount_paid'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? '';
    $notes         = trim($_POST['notes'] ?? '');

    $old['payment_date']   = $paymentDate;
    $old['amount_paid']    = $amountPaid;
    $old['payment_method'] = $paymentMethod;
    $old['notes']          = $notes;

    if (!csrf_valid()) {
        $errors[] = CSRF_ERROR;
    }

    $paymentDateObj = DateTime::createFromFormat('Y-m-d', $paymentDate);
    if (!$paymentDateObj || $paymentDateObj->format('Y-m-d') !== $paymentDate) {
        $errors[] = 'Please enter a valid payment date.';
    } elseif ($paymentDate > date('Y-m-d')) {
        $errors[] = 'Payment date cannot be in the future.';
    }

    if (!is_numeric($amountPaid) || toCents($amountPaid) < 1 || (float)$amountPaid > 99999999.99) {
        $errors[] = 'Please enter a valid amount paid greater than 0.';
    } elseif (toCents($amountPaid) > $balanceCents) {
        $errors[] = 'Amount paid can\'t be more than the ₱' . number_format($balance, 2)
            . ' still owed. If a fee was added, edit the bill amount first.';
    }

    if (!in_array($paymentMethod, $paymentMethods, true)) {
        $errors[] = 'Please select a valid payment method.';
    }

    if (strlen($notes) > 255) {
        $errors[] = 'Notes must be 255 characters or fewer.';
    }

    // Optional receipt. A form bigger than post_max_size arrives with $_POST and $_FILES empty,
    // which the checks above already report (no CSRF token, no date...).
    [$receiptExt, $receiptError] = checkReceiptUpload($_FILES['receipt'] ?? null);
    if ($receiptError !== null) {
        $errors[] = $receiptError;
    }

    $receiptFile = null;
    if (empty($errors) && $receiptExt !== null) {
        $receiptFile = storeReceipt($_FILES['receipt']['tmp_name'], $receiptExt);
        if ($receiptFile === null) {
            $errors[] = 'The receipt could not be saved. Please try again, or record the payment without it.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock the bill and re-check the balance inside the transaction, so two payments
            // submitted at the same moment can't together pay more than is owed.
            $lock = $pdo->prepare('SELECT amount, status FROM bills WHERE bill_id = ? AND user_id = ? FOR UPDATE');
            $lock->execute([$bill['bill_id'], $_SESSION['user_id']]);
            $locked = $lock->fetch();
            $owedCents = $locked ? toCents($locked['amount']) - toCents(billPaidTotal($pdo, $billId)) : 0;
            if (!$locked || $locked['status'] !== 'unpaid' || toCents($amountPaid) > $owedCents) {
                $pdo->rollBack();
                deleteReceiptFile($receiptFile);
                header('Location: bills.php');
                exit;
            }

            $values = [
                $bill['bill_id'],
                $paymentDate,
                round((float)$amountPaid, 2),
                $paymentMethod,
                $notes !== '' ? $notes : null,
            ];
            // receipt_file only when there is one, so payments keep working on a database that
            // hasn't had migration 004 yet (a receipt upload there fails and is reported).
            if ($receiptFile !== null) {
                $values[] = $receiptFile;
            }
            $insert = $pdo->prepare(
                'INSERT INTO payments (bill_id, payment_date, amount_paid, payment_method, notes'
                . ($receiptFile !== null ? ', receipt_file) VALUES (?, ?, ?, ?, ?, ?)' : ') VALUES (?, ?, ?, ?, ?)')
            );
            $insert->execute($values);

            $newStatus = refreshBillStatus($pdo, $billId);

            $pdo->commit();

            $leftCents = $owedCents - toCents($amountPaid);
            logActivity($pdo, (int)$_SESSION['user_id'], 'payment_recorded', sprintf(
                '%s — %s via %s%s%s',
                $bill['bill_name'],
                activityMoney((float)$amountPaid),
                $paymentMethodLabels[$paymentMethod],
                $newStatus === 'paid' ? '' : ', ' . activityMoney($leftCents / 100) . ' left',
                $receiptFile !== null ? ' · receipt attached' : ''
            ));

            header('Location: bills.php?' . ($newStatus === 'paid' ? 'paid=1' : 'partial=1'));
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            deleteReceiptFile($receiptFile);
            $errors[] = 'Failed to record the payment. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $paidSoFar > 0 ? 'Add Payment' : 'Mark as Paid' ?> – AfterBill</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --deep-navy: #17233C;
            --electric-blue: #3B82F6;
            --electric-blue-hover: #2563EB;
            --white: #FFFFFF;
            --light-gray: #F3F4F6;
            --text-dark: #1F2937;
            --text-muted: #6B7280;
            --border: #E5E7EB;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --sidebar-width: 240px;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .app { display: flex; min-height: 100vh; }

        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--deep-navy) 0%, #0F172A 100%);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
            overflow: hidden;
        }

        .sidebar-logo {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }

        .sidebar-logo img { height: 52px; width: auto; display: block; filter: brightness(0) invert(1); opacity: 0.9; }

        .sidebar-nav { flex: 1; padding: 16px 12px; display: flex; flex-direction: column; gap: 2px; }

        .nav-label {
            font-size: 0.65rem;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.3);
            padding: 10px 10px 6px;
            font-weight: 600;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            color: rgba(255,255,255,0.65);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background 0.18s, color 0.18s;
        }

        .nav-item:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.9); }
        .nav-item.active { background: rgba(59, 130, 246, 0.2); color: #fff; }
        .nav-item svg { width: 18px; height: 18px; flex-shrink: 0; }

        .sidebar-footer { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.07); }

        .user-card { display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: 10px; }

        .user-avatar {
            width: 34px; height: 34px;
            background: var(--electric-blue);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem; font-weight: 700; color: #fff;
            flex-shrink: 0;
        }

        .user-info { flex: 1; min-width: 0; }
        .user-name { font-size: 0.82rem; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .main { margin-left: var(--sidebar-width); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

        .topbar {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .topbar-left h2 { font-size: 1.1rem; font-weight: 700; color: var(--deep-navy); }
        .topbar-left p { font-size: 0.8rem; color: var(--text-muted); }

        .btn-logout {
            display: flex; align-items: center; gap: 6px;
            padding: 8px 16px;
            background: none;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.82rem; font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            transition: border-color 0.18s, color 0.18s, background 0.18s;
        }

        .btn-logout:hover { border-color: var(--danger); color: var(--danger); background: #FEF2F2; }
        .btn-logout svg { width: 15px; height: 15px; }

        .page-body { padding: 32px; flex: 1; display: flex; justify-content: center; }

        .form-panel {
            background: var(--white);
            border-radius: 14px;
            border: 1px solid var(--border);
            width: 100%;
            max-width: 560px;
            padding: 28px 32px;
            height: fit-content;
        }

        .form-panel h1 { font-size: 1.15rem; font-weight: 700; color: var(--deep-navy); margin-bottom: 4px; }
        .form-panel > p { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 18px; }

        .bill-summary {
            background: var(--light-gray);
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .bill-summary .bill-name { font-size: 0.88rem; font-weight: 600; color: var(--text-dark); }
        .bill-summary .bill-amount { font-size: 0.95rem; font-weight: 700; color: var(--deep-navy); }
        .bill-progress { margin: -12px 0 22px; font-size: 0.8rem; color: var(--text-muted); }
        .bill-progress strong { color: #B45309; }
        .field-hint { display: block; margin-top: 6px; font-size: 0.75rem; color: var(--text-muted); }
        .form-group input[type="file"] { width: 100%; font-size: 0.82rem; font-family: inherit; }

        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="date"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.88rem;
            font-family: inherit;
            color: var(--text-dark);
            background: var(--white);
        }

        .form-group textarea { resize: vertical; min-height: 70px; }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--electric-blue);
        }

        .form-actions { display: flex; gap: 12px; }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 22px;
            background: var(--electric-blue);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background 0.18s;
        }

        .btn-primary:hover { background: var(--electric-blue-hover); }
        .btn-primary svg { width: 16px; height: 16px; }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 22px;
            background: none;
            color: var(--text-muted);
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
        }

        .btn-secondary:hover { border-color: var(--text-muted); color: var(--text-dark); }

        .alert { padding: 12px 16px; border-radius: 8px; font-size: 0.82rem; margin-bottom: 18px; }
        .alert-error { background: #FEF2F2; color: #B91C1C; }
        .alert-error ul { margin: 4px 0 0 18px; }

        @media (max-width: 680px) {
            .sidebar { transform: translateX(-100%); }
            .main { margin-left: 0; }
            .page-body { padding: 20px; }
            .topbar { padding: 0 20px; }
            .form-panel { padding: 22px; }
        }
    </style>
</head>
<body>
<div class="app">

    <?php $activePage = 'bills'; include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2><?= $paidSoFar > 0 ? 'Add Payment' : 'Mark as Paid' ?></h2>
                <p>Record a full or partial payment for this bill.</p>
            </div>
            <div class="topbar-right">
                <?php require __DIR__ . '/partials/logout-button.php'; ?>
            </div>
        </header>

        <main class="page-body">
            <div class="form-panel">
                <h1>Record Payment</h1>
                <p>Pay the full balance, or enter a smaller amount to pay part of it now.</p>

                <div class="bill-summary">
                    <span class="bill-name"><?= htmlspecialchars($bill['bill_name']) ?></span>
                    <span class="bill-amount">₱<?= number_format((float)$bill['amount'], 2) ?></span>
                </div>

                <?php if ($paidSoFar > 0): ?>
                    <div class="bill-progress">
                        ₱<?= number_format($paidSoFar, 2) ?> paid so far · <strong>₱<?= number_format($balance, 2) ?> left to pay</strong>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <strong>Please fix the following:</strong>
                        <ul>
                            <?php foreach ($errors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="bill_id" value="<?= (int)$bill['bill_id'] ?>">

                    <div class="form-group">
                        <label for="payment_date">Payment Date</label>
                        <input type="date" id="payment_date" name="payment_date" max="<?= date('Y-m-d') ?>"
                            value="<?= htmlspecialchars($old['payment_date']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="amount_paid">Amount Paid (₱)</label>
                        <input type="number" id="amount_paid" name="amount_paid" step="0.01" min="0.01"
                            max="<?= number_format($balance, 2, '.', '') ?>"
                            value="<?= htmlspecialchars($old['amount_paid']) ?>" required>
                        <span class="field-hint">Balance: ₱<?= number_format($balance, 2) ?>. A smaller amount records a partial payment.</span>
                    </div>

                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <select id="payment_method" name="payment_method" required>
                            <option value="">Select a payment method</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?= htmlspecialchars($method) ?>"
                                    <?= $old['payment_method'] === $method ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($paymentMethodLabels[$method]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes (optional)</label>
                        <textarea id="notes" name="notes" maxlength="255"><?= htmlspecialchars($old['notes']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="receipt">Receipt (optional)</label>
                        <input type="file" id="receipt" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf">
                        <span class="field-hint">JPG, PNG, WebP or PDF, up to 5 MB. Only you can see it.</span>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            Confirm Payment
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                        <a href="bills.php" class="btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>
</body>
</html>
