<?php
/**
 * AfterBill - Bill List
 */

require_once __DIR__ . '/includes/auth.php';
require_login(); // session check, idle timeout, sign-out after a password change

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/recurring_bills.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/csv.php';
require_once __DIR__ . '/includes/xlsx.php';
require_once __DIR__ . '/includes/categories.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pdo = getPDO();

// Catch up any recurring bills whose cycle has passed before listing, so newly generated
// bills show up immediately.
generateMissedRecurringBills($pdo, (int)$_SESSION['user_id']);

$categories = userCategories($pdo, (int)$_SESSION['user_id']); // built-ins + this user's own
$validCategoryIds = array_column($categories, 'category_id');

$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['unpaid', 'paid', 'partial'], true)) {
    $statusFilter = '';
}

$categoryFilter = $_GET['category_id'] ?? '';
if (!ctype_digit((string)$categoryFilter) || !in_array((int)$categoryFilter, $validCategoryIds, true)) {
    $categoryFilter = '';
}

$search = trim($_GET['q'] ?? '');
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}

$sql = 'SELECT b.bill_id, b.bill_name, b.amount, b.due_date, b.is_recurring, b.status, '
     . (recurrenceColumnsExist($pdo) ? 'b.repeat_interval, b.repeat_until,' : "'monthly' AS repeat_interval, NULL AS repeat_until,") . '
               c.category_name,
               (b.status = \'unpaid\' AND b.due_date < CURDATE()) AS is_overdue,
               (SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p WHERE p.bill_id = b.bill_id) AS paid_total
        FROM bills b
        JOIN categories c ON c.category_id = b.category_id
        WHERE b.user_id = ?';
$params = [$_SESSION['user_id']];

if ($statusFilter === 'partial') {
    // Partially paid: still unpaid, but some money is already in.
    $sql .= ' AND b.status = \'unpaid\' AND EXISTS (SELECT 1 FROM payments p WHERE p.bill_id = b.bill_id)';
} elseif ($statusFilter !== '') {
    $sql .= ' AND b.status = ?';
    $params[] = $statusFilter;
}
if ($categoryFilter !== '') {
    $sql .= ' AND b.category_id = ?';
    $params[] = (int)$categoryFilter;
}
if ($search !== '') {
    $sql .= ' AND b.bill_name LIKE ?';
    $params[] = '%' . addcslashes($search, '%_\\') . '%';
}

$sql .= ' ORDER BY b.due_date ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bills = $stmt->fetchAll();

// CSV export of exactly what the current filters show (same idea as payments.php).
if (($_GET['export'] ?? '') === 'csv') {
    $out = csvStart('afterbill-bills-' . date('Y-m-d') . '.csv');
    fputcsv($out, ['Bill', 'Category', 'Amount', 'Due Date', 'Status', 'Recurring']);
    foreach ($bills as $b) {
        fputcsv($out, [
            csvSafe($b['bill_name']),
            csvSafe($b['category_name']),
            number_format((float)$b['amount'], 2, '.', ''),
            $b['due_date'],
            $b['is_overdue'] ? 'Overdue'
                : ($b['status'] === 'unpaid' && (float)$b['paid_total'] > 0 ? 'Partially paid' : ucfirst($b['status'])),
            $b['is_recurring'] ? repeatLabel($b['repeat_interval'], $b['repeat_until']) : 'No',
        ]);
    }
    fclose($out);
    exit;
}

// Styled Excel export of exactly what the current filters show.
if (($_GET['export'] ?? '') === 'xlsx') {
    $rows = [];
    $sum = ['amount' => 0.0, 'paid' => 0.0];
    foreach ($bills as $b) {
        $paid = $b['status'] === 'paid' ? (float)$b['amount'] : min((float)$b['paid_total'], (float)$b['amount']);
        $status = $b['is_overdue'] ? 'Overdue'
            : ($b['status'] === 'unpaid' && $paid > 0 ? 'Partially paid' : ucfirst($b['status']));
        $rows[] = [
            $b['bill_name'], $b['category_name'], (float)$b['amount'], $paid, round((float)$b['amount'] - $paid, 2),
            $b['due_date'], $status, $b['is_recurring'] ? repeatLabel($b['repeat_interval'], $b['repeat_until']) : '—',
        ];
        $sum['amount'] += (float)$b['amount'];
        $sum['paid'] += $paid;
    }
    $filters = array_filter([
        $statusFilter !== '' ? 'Status: ' . ($statusFilter === 'partial' ? 'Partially paid' : ucfirst($statusFilter)) : '',
        $categoryFilter !== '' ? 'Category: ' . (array_column($categories, 'category_name', 'category_id')[(int)$categoryFilter] ?? '') : '',
        $search !== '' ? 'Search: "' . $search . '"' : '',
    ]);
    xlsxDownload(
        'afterbill-bills-' . date('Y-m-d') . '.xlsx',
        'My Bills',
        'AfterBill — My Bills',
        ($_SESSION['user_name'] ?? '') . ' · exported ' . date('F j, Y g:i A') . ' · ' . count($rows) . ' bill' . (count($rows) === 1 ? '' : 's')
            . ($filters ? ' · ' . implode(' · ', $filters) : ''),
        [
            ['label' => 'Bill', 'type' => 'strong', 'width' => 32],
            ['label' => 'Category', 'type' => 'text', 'width' => 18],
            ['label' => 'Amount', 'type' => 'money', 'width' => 15],
            ['label' => 'Paid', 'type' => 'money', 'width' => 15],
            ['label' => 'Balance', 'type' => 'money', 'width' => 15],
            ['label' => 'Due Date', 'type' => 'date', 'width' => 15],
            ['label' => 'Status', 'type' => 'status', 'width' => 16],
            ['label' => 'Repeats', 'type' => 'text', 'width' => 22],
        ],
        $rows,
        ['Total', null, round($sum['amount'], 2), round($sum['paid'], 2), round($sum['amount'] - $sum['paid'], 2), null, null, null]
    );
}

$listTotal = array_sum(array_map(fn($b) => (float)$b['amount'], $bills));
$hasFilters = $statusFilter !== '' || $categoryFilter !== '' || $search !== '';
$exportQuery = http_build_query(array_filter([
    'status'      => $statusFilter,
    'category_id' => $categoryFilter,
    'q'           => $search,
    'export'      => 'csv',
], fn($v) => $v !== ''));
$xlsxQuery = str_replace('export=csv', 'export=xlsx', $exportQuery);

// One-time results of "Pay selected" (bulk_pay.php).
$bulkPaid = $_SESSION['flash_bulk_paid'] ?? null;
$bulkError = $_SESSION['flash_bulk_error'] ?? null;
unset($_SESSION['flash_bulk_paid'], $_SESSION['flash_bulk_error']);
$payableCount = count(array_filter($bills, fn($b) => $b['status'] === 'unpaid'));

// One-time warning set by add_bill.php / edit_bill.php when a bill pushes its category over budget.
$budgetWarning = $_SESSION['flash_budget_warning'] ?? null;
unset($_SESSION['flash_budget_warning']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bills – AfterBill</title>
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

        .topbar-right { display: flex; align-items: center; gap: 12px; }

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

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 18px;
            background: var(--electric-blue);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            font-family: inherit;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.18s;
        }

        .btn-primary:hover { background: var(--electric-blue-hover); }
        .btn-primary svg { width: 15px; height: 15px; }

        .page-body { padding: 32px; flex: 1; }

        .alert { padding: 12px 16px; border-radius: 8px; font-size: 0.82rem; margin-bottom: 18px; }
        .alert-success { background: #ECFDF5; color: #047857; }
        .alert-error { background: #FEF2F2; color: #B91C1C; }

        .filter-bar {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 14px;
            margin-bottom: 20px;
        }

        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 0.75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.4px; }

        .filter-group select,
        .filter-group input[type="search"] {
            padding: 8px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.85rem;
            font-family: inherit;
            color: var(--text-dark);
            background: var(--white);
            min-width: 160px;
        }

        .filter-actions { margin-left: auto; }

        .btn-clear {
            display: inline-flex;
            align-items: center;
            padding: 9px 16px;
            background: none;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
        }

        .btn-clear:hover { border-color: var(--text-muted); color: var(--text-dark); }

        .panel {
            background: var(--white);
            border-radius: 14px;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        table { width: 100%; border-collapse: collapse; }

        thead th {
            text-align: left;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            padding: 14px 22px;
            border-bottom: 1px solid var(--border);
        }

        tbody td {
            padding: 14px 22px;
            font-size: 0.85rem;
            color: var(--text-dark);
            border-bottom: 1px solid var(--border);
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #F9FAFB; }

        .bill-name-cell { font-weight: 600; }
        .bill-recurring-tag {
            display: inline-block;
            margin-left: 6px;
            font-size: 0.68rem;
            font-weight: 600;
            color: var(--electric-blue);
            background: rgba(59,130,246,0.1);
            padding: 2px 7px;
            border-radius: 999px;
            vertical-align: middle;
        }

        .status-badge {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 999px;
        }

        .status-badge.paid    { background: #ECFDF5; color: #047857; }
        .status-badge.unpaid  { background: #FFFBEB; color: #B45309; }
        .status-badge.overdue { background: #FEF2F2; color: #B91C1C; }
        .status-badge.partial { background: #EFF6FF; color: #1D4ED8; white-space: nowrap; }
        .btn-excel { border-color: #10B981 !important; color: #047857 !important; }
        .btn-excel:hover { background: #ECFDF5 !important; }
        .btn-csv { font-size: 0.78rem; font-weight: 600; color: var(--text-muted); text-decoration: none; padding: 6px 4px; }
        .btn-csv:hover { color: var(--electric-blue); text-decoration: underline; }
        .bulk-bar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 16px;
            padding: 12px 16px; background: var(--white); border: 1px solid var(--border); border-radius: 12px;
            font-size: 0.82rem;
        }
        .bulk-bar .bulk-title { font-weight: 700; color: var(--deep-navy); }
        .bulk-bar label { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); font-weight: 600; }
        .bulk-bar input, .bulk-bar select {
            padding: 6px 8px; border: 1.5px solid var(--border); border-radius: 7px; font-family: inherit; font-size: 0.8rem;
        }
        .bulk-bar .btn-primary { padding: 8px 16px; }
        .bulk-bar .bulk-hint { color: var(--text-muted); font-size: 0.75rem; }
        th.check-col, td.check-col { width: 36px; padding-right: 0; }
        .check-col input { width: 16px; height: 16px; cursor: pointer; }
        .bill-balance { display: block; margin-top: 3px; font-size: 0.72rem; color: var(--text-muted); white-space: nowrap; }

        .btn-pay {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            background: none;
            border: 1.5px solid var(--electric-blue);
            border-radius: 7px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--electric-blue);
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-pay:hover { background: var(--electric-blue); color: #fff; }

        .actions-cell { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

        .btn-edit {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            background: none;
            border: 1.5px solid var(--border);
            border-radius: 7px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-edit:hover { border-color: var(--text-muted); color: var(--text-dark); }

        .delete-form { display: inline-block; margin: 0; }

        .btn-delete {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            background: none;
            border: 1.5px solid var(--danger);
            border-radius: 7px;
            font-size: 0.75rem;
            font-weight: 600;
            font-family: inherit;
            color: var(--danger);
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-delete:hover { background: var(--danger); color: #fff; }

        .empty-state { padding: 48px 22px; text-align: center; color: var(--text-muted); }
        .empty-state svg { width: 44px; height: 44px; margin: 0 auto 12px; opacity: 0.35; }
        .empty-state p { font-size: 0.85rem; margin-bottom: 16px; }

        .table-scroll { overflow-x: auto; }

        @media (max-width: 680px) {
            .sidebar { transform: translateX(-100%); }
            .main { margin-left: 0; }
            .page-body { padding: 20px; }
            .topbar { padding: 0 20px; }
            .filter-actions { margin-left: 0; }
        }
    </style>
</head>
<body>
<div class="app">

    <?php $activePage = 'bills'; include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2>My Bills</h2>
                <p><?= count($bills) ?> bill<?= count($bills) === 1 ? '' : 's' ?> found · ₱<?= number_format($listTotal, 2) ?> total</p>
            </div>
            <div class="topbar-right">
                <a href="add_bill.php" class="btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Add Bill
                </a>
                <?php if ($bills): ?>
                    <a href="bills.php?<?= htmlspecialchars($xlsxQuery) ?>" class="btn-edit btn-excel" title="Download the bills shown below as a formatted Excel file">Export Excel</a>
                    <a href="bills.php?<?= htmlspecialchars($exportQuery) ?>" class="btn-csv" title="Plain CSV, for importing into other apps">CSV</a>
                <?php endif; ?>
                <?php require __DIR__ . '/partials/logout-button.php'; ?>
            </div>
        </header>

        <main class="page-body">

            <?php if (isset($_GET['added'])): ?>
                <div class="alert alert-success">Bill added successfully.</div>
            <?php endif; ?>

            <?php if ($budgetWarning !== null): ?>
                <div class="alert alert-error">⚠ <?= htmlspecialchars($budgetWarning) ?> <a href="budgets.php">View budgets</a></div>
            <?php endif; ?>

            <?php if (isset($_GET['paid'])): ?>
                <div class="alert alert-success">Payment recorded. Bill marked as paid.</div>
            <?php endif; ?>

            <?php if ($bulkPaid !== null): ?>
                <div class="alert alert-success"><?= htmlspecialchars($bulkPaid) ?></div>
            <?php endif; ?>
            <?php if ($bulkError !== null): ?>
                <div class="alert alert-error"><?= htmlspecialchars($bulkError) ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['partial'])): ?>
                <div class="alert alert-success">Partial payment recorded. The rest of the bill is still due.</div>
            <?php endif; ?>

            <?php if (isset($_GET['updated'])): ?>
                <div class="alert alert-success">Bill updated successfully.</div>
            <?php endif; ?>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="alert alert-success">Bill deleted successfully.</div>
            <?php endif; ?>

            <?php if (isset($_GET['unpaid'])): ?>
                <div class="alert alert-success">Payments undone. Bill is unpaid again.</div>
            <?php endif; ?>

            <?php if (isset($_GET['delete_blocked'])): ?>
                <div class="alert alert-error">This bill has recorded payments and can't be deleted. Use "Undo Payment" first if you really need to delete it.</div>
            <?php endif; ?>

            <form method="GET" action="" class="filter-bar">
                <div class="filter-group">
                    <label for="q">Search</label>
                    <input type="search" id="q" name="q" placeholder="Bill name…" value="<?= htmlspecialchars($search) ?>">
                </div>

                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" data-autosubmit>
                        <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
                        <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partially paid</option>
                        <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" data-autosubmit>
                        <option value="">All</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['category_id'] ?>"
                                <?= (string)$categoryFilter === (string)$cat['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn-primary">Search</button>

                <?php if ($hasFilters): ?>
                    <div class="filter-actions">
                        <a href="bills.php" class="btn-clear">Clear filters</a>
                    </div>
                <?php endif; ?>
            </form>

            <?php if ($payableCount > 0): ?>
                <form method="POST" action="bulk_pay.php" id="bulk-pay" class="bulk-bar"
                      data-confirm="Pay the full remaining balance of every ticked bill?">
                    <?= csrf_field() ?>
                    <span class="bulk-title">Pay selected bills</span>
                    <label>Paid on
                        <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                    </label>
                    <label>Method
                        <select name="payment_method" required>
                            <option value="">Choose…</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="gcash">GCash</option>
                            <option value="debit_card">Debit Card</option>
                            <option value="credit_card">Credit Card</option>
                            <option value="other">Other</option>
                        </select>
                    </label>
                    <button type="submit" class="btn-primary">Pay selected</button>
                    <span class="bulk-hint">Tick bills below. Each one is paid in full (its remaining balance).</span>
                </form>
            <?php endif; ?>

            <div class="panel">
                <?php if (empty($bills)): ?>
                    <div class="empty-state">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                        </svg>
                        <p>No bills match these filters.<br>Try adjusting your filters or add a new bill.</p>
                        <a href="add_bill.php" class="btn-primary">Add Bill</a>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th class="check-col">
                                        <?php if ($payableCount > 0): ?>
                                            <input type="checkbox" data-check-all="bill_ids[]" form="bulk-pay" aria-label="Select all unpaid bills">
                                        <?php endif; ?>
                                    </th>
                                    <th>Bill Name</th>
                                    <th>Category</th>
                                    <th>Amount</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bills as $bill): ?>
                                    <?php
                                        $paidTotal = (float)$bill['paid_total'];
                                        $isPartial = $bill['status'] === 'unpaid' && $paidTotal > 0;
                                        if ($bill['is_overdue']) {
                                            $statusClass = 'overdue';
                                            $statusLabel = 'Overdue';
                                        } elseif ($bill['status'] === 'paid') {
                                            $statusClass = 'paid';
                                            $statusLabel = 'Paid';
                                        } elseif ($isPartial) {
                                            $statusClass = 'partial';
                                            $statusLabel = 'Partially paid';
                                        } else {
                                            $statusClass = 'unpaid';
                                            $statusLabel = 'Unpaid';
                                        }
                                    ?>
                                    <tr>
                                        <td class="check-col">
                                            <?php if ($bill['status'] === 'unpaid'): ?>
                                                <input type="checkbox" name="bill_ids[]" value="<?= (int)$bill['bill_id'] ?>" form="bulk-pay"
                                                       aria-label="Select <?= htmlspecialchars($bill['bill_name']) ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td class="bill-name-cell">
                                            <?= htmlspecialchars($bill['bill_name']) ?>
                                            <?php if ($bill['is_recurring']): ?>
                                                <span class="bill-recurring-tag" title="Repeats: <?= htmlspecialchars(repeatLabel($bill['repeat_interval'], $bill['repeat_until'])) ?>"><?= htmlspecialchars(REPEAT_INTERVALS[$bill['repeat_interval']] ?? 'Recurring') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($bill['category_name']) ?></td>
                                        <td>
                                            ₱<?= number_format((float)$bill['amount'], 2) ?>
                                            <?php if ($isPartial): ?>
                                                <span class="bill-balance">₱<?= number_format($paidTotal, 2) ?> paid · ₱<?= number_format(max(0, (float)$bill['amount'] - $paidTotal), 2) ?> left</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((new DateTime($bill['due_date']))->format('M j, Y')) ?></td>
                                        <td><span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                                        <td>
                                            <div class="actions-cell">
                                                <?php if ($bill['status'] === 'unpaid'): ?>
                                                    <a href="mark_paid.php?bill_id=<?= (int)$bill['bill_id'] ?>" class="btn-pay"><?= $isPartial ? 'Add Payment' : 'Mark as Paid' ?></a>
                                                <?php else: ?>
                                                    <form method="POST" action="undo_payment.php" class="delete-form"
                                                        data-confirm="<?= htmlspecialchars('Undo the payments for "' . $bill['bill_name'] . '"? All of its payment records and receipts will be removed and the bill set back to unpaid.') ?>">
                                                        <input type="hidden" name="bill_id" value="<?= (int)$bill['bill_id'] ?>">
                                                        <input type="hidden" name="return" value="bills">
                                                        <?= csrf_field() ?>
                                                        <button type="submit" class="btn-edit" style="cursor:pointer;font-family:inherit;">Undo Payment</button>
                                                    </form>
                                                <?php endif; ?>
                                                <a href="edit_bill.php?bill_id=<?= (int)$bill['bill_id'] ?>" class="btn-edit">Edit</a>
                                                <form method="POST" action="delete_bill.php" class="delete-form"
                                                    data-confirm="<?= htmlspecialchars('Delete "' . $bill['bill_name'] . '"? This cannot be undone.') ?>">
                                                    <input type="hidden" name="bill_id" value="<?= (int)$bill['bill_id'] ?>">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn-delete">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
