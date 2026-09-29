<?php
/**
 * AfterBill - Edit Bill
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pdo = getPDO();
$categories = $pdo->query('SELECT category_id, category_name FROM categories ORDER BY category_id')->fetchAll();
$validCategoryIds = array_column($categories, 'category_id');

$billId = $_GET['bill_id'] ?? $_POST['bill_id'] ?? '';
if (!ctype_digit((string)$billId)) {
    header('Location: bills.php');
    exit;
}
$billId = (int)$billId;

$stmt = $pdo->prepare(
    'SELECT bill_id, bill_name, amount, due_date, category_id, is_recurring, status
     FROM bills WHERE bill_id = ? AND user_id = ? LIMIT 1'
);
$stmt->execute([$billId, $_SESSION['user_id']]);
$bill = $stmt->fetch();

// Not found or not owned by this user — nothing to edit here.
if (!$bill) {
    header('Location: bills.php');
    exit;
}

$errors = [];
$old = [
    'bill_name'    => $bill['bill_name'],
    'amount'       => number_format((float)$bill['amount'], 2, '.', ''),
    'due_date'     => $bill['due_date'],
    'category_id'  => (string)$bill['category_id'],
    'is_recurring' => (bool)$bill['is_recurring'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $billName    = trim($_POST['bill_name'] ?? '');
    $amount      = trim($_POST['amount'] ?? '');
    $dueDate     = trim($_POST['due_date'] ?? '');
    $categoryId  = $_POST['category_id'] ?? '';
    $isRecurring = isset($_POST['is_recurring']);

    $old['bill_name']    = $billName;
    $old['amount']       = $amount;
    $old['due_date']     = $dueDate;
    $old['category_id']  = $categoryId;
    $old['is_recurring'] = $isRecurring;

    if (strlen($billName) < 2 || strlen($billName) > 100) {
        $errors[] = 'Bill name must be between 2 and 100 characters.';
    }
    if (!is_numeric($amount) || (float)$amount <= 0 || (float)$amount > 99999999.99) {
        $errors[] = 'Please enter a valid amount greater than 0.';
    }
    $dueDateObj = DateTime::createFromFormat('Y-m-d', $dueDate);
    if (!$dueDateObj || $dueDateObj->format('Y-m-d') !== $dueDate) {
        $errors[] = 'Please enter a valid due date.';
    }
    if (!ctype_digit((string)$categoryId) || !in_array((int)$categoryId, $validCategoryIds, true)) {
        $errors[] = 'Please select a valid category.';
    }

    if (empty($errors)) {
        try {
            $update = $pdo->prepare(
                'UPDATE bills
                 SET bill_name = ?, amount = ?, due_date = ?, category_id = ?, is_recurring = ?
                 WHERE bill_id = ? AND user_id = ?'
            );
            $update->execute([
                $billName,
                (float)$amount,
                $dueDate,
                (int)$categoryId,
                $isRecurring ? 1 : 0,
                $billId,
                $_SESSION['user_id'],
            ]);

            header('Location: bills.php?updated=1');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Failed to update the bill. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Bill – AfterBill</title>
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
        .form-panel > p { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 22px; }

        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="date"],
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.88rem;
            font-family: inherit;
            color: var(--text-dark);
            background: var(--white);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--electric-blue);
        }

        .form-check { display: flex; align-items: center; gap: 8px; margin-bottom: 22px; }
        .form-check label { font-size: 0.85rem; color: var(--text-dark); }

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
                <h2>Edit Bill</h2>
                <p>Update the details of this bill.</p>
            </div>
            <div class="topbar-right">
                <a href="auth/logout.php" class="btn-logout">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                    </svg>
                    Logout
                </a>
            </div>
        </header>

        <main class="page-body">
            <div class="form-panel">
                <h1>Edit Bill</h1>
                <p>Update the details below.</p>

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

                <form method="POST" action="" novalidate>
                    <input type="hidden" name="bill_id" value="<?= (int)$billId ?>">

                    <div class="form-group">
                        <label for="bill_name">Bill Name</label>
                        <input type="text" id="bill_name" name="bill_name" placeholder="e.g. Meralco Electric Bill"
                            value="<?= htmlspecialchars($old['bill_name']) ?>" required maxlength="100">
                    </div>

                    <div class="form-group">
                        <label for="amount">Amount (₱)</label>
                        <input type="number" id="amount" name="amount" placeholder="0.00" step="0.01" min="0.01"
                            value="<?= htmlspecialchars($old['amount']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="due_date">Due Date</label>
                        <input type="date" id="due_date" name="due_date"
                            value="<?= htmlspecialchars($old['due_date']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="category_id">Category</label>
                        <select id="category_id" name="category_id" required>
                            <option value="">Select a category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['category_id'] ?>"
                                    <?= (string)$old['category_id'] === (string)$cat['category_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" id="is_recurring" name="is_recurring" value="1"
                            <?= $old['is_recurring'] ? 'checked' : '' ?>>
                        <label for="is_recurring">This bill repeats every month</label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            Save Changes
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
