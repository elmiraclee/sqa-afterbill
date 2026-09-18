<?php
/**
 * AfterBill - Bill List
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/recurring_bills.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pdo = getPDO();

// Catch up any recurring bills whose cycle has passed before listing, so newly generated
// bills show up immediately.
generateMissedRecurringBills($pdo, (int)$_SESSION['user_id']);

$categories = $pdo->query('SELECT category_id, category_name FROM categories ORDER BY category_id')->fetchAll();
$validCategoryIds = array_column($categories, 'category_id');

$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['unpaid', 'paid'], true)) {
    $statusFilter = '';
}

$categoryFilter = $_GET['category_id'] ?? '';
if (!ctype_digit((string)$categoryFilter) || !in_array((int)$categoryFilter, $validCategoryIds, true)) {
    $categoryFilter = '';
}

$sql = 'SELECT b.bill_id, b.bill_name, b.amount, b.due_date, b.is_recurring, b.status,
               c.category_name,
               (b.status = \'unpaid\' AND b.due_date < CURDATE()) AS is_overdue
        FROM bills b
        JOIN categories c ON c.category_id = b.category_id
        WHERE b.user_id = ?';
$params = [$_SESSION['user_id']];

if ($statusFilter !== '') {
    $sql .= ' AND b.status = ?';
    $params[] = $statusFilter;
}
if ($categoryFilter !== '') {
    $sql .= ' AND b.category_id = ?';
    $params[] = (int)$categoryFilter;
}

$sql .= ' ORDER BY b.due_date ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bills = $stmt->fetchAll();
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

        .filter-group select {
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

    <aside class="sidebar">
        <div class="sidebar-logo">
            <img src="assets/images/logo.png" alt="AfterBill">
        </div>

        <nav class="sidebar-nav">
            <span class="nav-label">Menu</span>

            <a href="dashboard.php" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard
            </a>

            <a href="bills.php" class="nav-item active">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/>
                </svg>
                My Bills
            </a>

            <a href="calendar.php" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                </svg>
                Calendar
            </a>

            <a href="reports.php" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
                </svg>
                Reports
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?= $userInitial ?></div>
                <div class="user-info">
                    <div class="user-name"><?= $userName ?></div>
                </div>
            </div>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2>My Bills</h2>
                <p><?= count($bills) ?> bill<?= count($bills) === 1 ? '' : 's' ?> found</p>
            </div>
            <div class="topbar-right">
                <a href="add_bill.php" class="btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Add Bill
                </a>
                <a href="auth/logout.php" class="btn-logout">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                    </svg>
                    Logout
                </a>
            </div>
        </header>

        <main class="page-body">

            <?php if (isset($_GET['added'])): ?>
                <div class="alert alert-success">Bill added successfully.</div>
            <?php endif; ?>

            <?php if (isset($_GET['paid'])): ?>
                <div class="alert alert-success">Payment recorded. Bill marked as paid.</div>
            <?php endif; ?>

            <?php if (isset($_GET['updated'])): ?>
                <div class="alert alert-success">Bill updated successfully.</div>
            <?php endif; ?>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="alert alert-success">Bill deleted successfully.</div>
            <?php endif; ?>

            <?php if (isset($_GET['delete_blocked'])): ?>
                <div class="alert alert-error">This bill has recorded payments and can't be deleted. Remove its payment history first if you really need to delete it.</div>
            <?php endif; ?>

            <form method="GET" action="" class="filter-bar">
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" onchange="this.form.submit()">
                        <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All</option>
                        <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id" onchange="this.form.submit()">
                        <option value="">All</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['category_id'] ?>"
                                <?= (string)$categoryFilter === (string)$cat['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($statusFilter !== '' || $categoryFilter !== ''): ?>
                    <div class="filter-actions">
                        <a href="bills.php" class="btn-clear">Clear filters</a>
                    </div>
                <?php endif; ?>
            </form>

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
                                        if ($bill['is_overdue']) {
                                            $statusClass = 'overdue';
                                            $statusLabel = 'Overdue';
                                        } elseif ($bill['status'] === 'paid') {
                                            $statusClass = 'paid';
                                            $statusLabel = 'Paid';
                                        } else {
                                            $statusClass = 'unpaid';
                                            $statusLabel = 'Unpaid';
                                        }
                                    ?>
                                    <tr>
                                        <td class="bill-name-cell">
                                            <?= htmlspecialchars($bill['bill_name']) ?>
                                            <?php if ($bill['is_recurring']): ?>
                                                <span class="bill-recurring-tag">Recurring</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($bill['category_name']) ?></td>
                                        <td>₱<?= number_format((float)$bill['amount'], 2) ?></td>
                                        <td><?= htmlspecialchars((new DateTime($bill['due_date']))->format('M j, Y')) ?></td>
                                        <td><span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                                        <td>
                                            <div class="actions-cell">
                                                <?php if ($bill['status'] === 'unpaid'): ?>
                                                    <a href="mark_paid.php?bill_id=<?= (int)$bill['bill_id'] ?>" class="btn-pay">Mark as Paid</a>
                                                <?php endif; ?>
                                                <a href="edit_bill.php?bill_id=<?= (int)$bill['bill_id'] ?>" class="btn-edit">Edit</a>
                                                <form method="POST" action="delete_bill.php" class="delete-form"
                                                    onsubmit="return confirm('Delete ' + <?= htmlspecialchars(json_encode($bill['bill_name']), ENT_QUOTES) ?> + '? This cannot be undone.');">
                                                    <input type="hidden" name="bill_id" value="<?= (int)$bill['bill_id'] ?>">
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
</body>
</html>
