<?php
/**
 * AfterBill - My Bills
 * Full CRUD for bills: add, edit, delete, mark paid/unpaid, search, filter, sort.
 */

require_once __DIR__ . '/includes/auth.php';
require_login();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bills_repo.php';

$userId = current_user_id();
$user = get_user($userId);
$userName = $user['full_name'] ?? ($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));
$currency = $user['currency'] ?? 'PHP';

function bills_redirect_back(): void
{
    $allowedPages = ['bills.php', 'calendar.php', 'dashboard.php'];
    $target = $_POST['redirect_to'] ?? 'bills.php';
    if (!in_array($target, $allowedPages, true)) {
        $target = 'bills.php';
    }
    $qs = $_POST['redirect_qs'] ?? '';
    header('Location: ' . $target . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

function validate_bill_input(array $post): array
{
    $errors = [];
    $data = [
        'name'        => trim($post['name'] ?? ''),
        'category'    => $post['category'] ?? '',
        'amount'      => $post['amount'] ?? '',
        'due_date'    => $post['due_date'] ?? '',
        'frequency'   => $post['frequency'] ?? 'One-time',
        'notes'       => trim($post['notes'] ?? ''),
        'reminder_at' => trim($post['reminder_at'] ?? ''),
    ];

    if ($data['name'] === '') {
        $errors[] = 'Bill name cannot be empty.';
    } elseif (strlen($data['name']) > 150) {
        $errors[] = 'Bill name is too long.';
    }

    if (!in_array($data['category'], BILL_CATEGORIES, true)) {
        $errors[] = 'Please choose a valid category.';
    }

    if (!is_numeric($data['amount']) || (float)$data['amount'] <= 0) {
        $errors[] = 'Amount must be a valid positive number.';
    } else {
        $data['amount'] = round((float)$data['amount'], 2);
    }

    $dueDateObj = DateTime::createFromFormat('Y-m-d', $data['due_date']);
    if (!$dueDateObj || $dueDateObj->format('Y-m-d') !== $data['due_date']) {
        $errors[] = 'Due date must be a valid date.';
    }

    if (!in_array($data['frequency'], BILL_FREQUENCIES, true)) {
        $errors[] = 'Please choose a valid billing frequency.';
    }

    if ($data['reminder_at'] !== '') {
        $reminderObj = DateTime::createFromFormat('Y-m-d\TH:i', $data['reminder_at']);
        if (!$reminderObj) {
            $errors[] = 'Reminder date/time is invalid.';
        } else {
            $data['reminder_at'] = $reminderObj->format('Y-m-d H:i:s');
        }
    }

    return [$errors, $data];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        [$errors, $data] = validate_bill_input($_POST);

        if (!empty($errors)) {
            foreach ($errors as $err) {
                flash_set('error', $err);
            }
        } else {
            if ($action === 'create') {
                bills_create($userId, $data);
                flash_set('success', 'Bill "' . $data['name'] . '" was added.');
            } else {
                $billId = (int)($_POST['bill_id'] ?? 0);
                if (bills_find($userId, $billId)) {
                    bills_update($userId, $billId, $data);
                    flash_set('success', 'Bill "' . $data['name'] . '" was updated.');
                } else {
                    flash_set('error', 'Bill not found.');
                }
            }
        }
        bills_redirect_back();
    }

    if ($action === 'delete') {
        $billId = (int)($_POST['bill_id'] ?? 0);
        $bill = bills_find($userId, $billId);
        if ($bill) {
            bills_delete($userId, $billId);
            flash_set('success', 'Bill "' . $bill['name'] . '" was deleted.');
        } else {
            flash_set('error', 'Bill not found.');
        }
        bills_redirect_back();
    }

    if ($action === 'mark_paid' || $action === 'mark_unpaid') {
        $billId = (int)($_POST['bill_id'] ?? 0);
        $bill = bills_find($userId, $billId);
        if ($bill) {
            bills_set_status($userId, $billId, $action === 'mark_paid' ? 'paid' : 'unpaid');
            flash_set('success', 'Bill "' . $bill['name'] . '" marked as ' . ($action === 'mark_paid' ? 'Paid' : 'Unpaid') . '.');
        } else {
            flash_set('error', 'Bill not found.');
        }
        bills_redirect_back();
    }
}

$filters = [
    'search'   => trim($_GET['q'] ?? ''),
    'category' => $_GET['category'] ?? '',
    'status'   => $_GET['status'] ?? '',
    'sort'     => $_GET['sort'] ?? 'due_date_asc',
];

$bills = bills_fetch_all($userId, $filters);
$redirectQs = http_build_query(array_filter([
    'q'        => $filters['search'],
    'category' => $filters['category'],
    'status'   => $filters['status'],
    'sort'     => $filters['sort'] !== 'due_date_asc' ? $filters['sort'] : '',
]));

$autoOpenAdd = ($_GET['action'] ?? '') === 'add';

$pageTitle = 'My Bills';
$pageSubtitle = 'Manage and track every bill in one place.';
$activePage = 'bills';
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
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app">

    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <?php include __DIR__ . '/partials/topbar.php'; ?>

        <main class="page-body">
            <?php include __DIR__ . '/partials/alerts.php'; ?>

            <div class="toolbar">
                <form method="GET" class="toolbar-grow search-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                    </svg>
                    <input type="text" name="q" class="input" placeholder="Search bills by name or notes..." value="<?= e($filters['search']) ?>">
                    <?php foreach (['category', 'status', 'sort'] as $k): if (!empty($filters[$k])): ?>
                        <input type="hidden" name="<?= $k ?>" value="<?= e($filters[$k]) ?>">
                    <?php endif; endforeach; ?>
                </form>

                <form method="GET" style="display:contents;">
                    <input type="hidden" name="q" value="<?= e($filters['search']) ?>">
                    <select name="category" class="input" style="width:auto;" onchange="this.form.submit()">
                        <option value="">All Categories</option>
                        <?php foreach (BILL_CATEGORIES as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= $filters['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status" class="input" style="width:auto;" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <option value="upcoming" <?= $filters['status'] === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                        <option value="paid" <?= $filters['status'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="overdue" <?= $filters['status'] === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                    </select>

                    <select name="sort" class="input" style="width:auto;" onchange="this.form.submit()">
                        <option value="due_date_asc" <?= $filters['sort'] === 'due_date_asc' ? 'selected' : '' ?>>Due Date ↑</option>
                        <option value="due_date_desc" <?= $filters['sort'] === 'due_date_desc' ? 'selected' : '' ?>>Due Date ↓</option>
                        <option value="amount_asc" <?= $filters['sort'] === 'amount_asc' ? 'selected' : '' ?>>Amount ↑</option>
                        <option value="amount_desc" <?= $filters['sort'] === 'amount_desc' ? 'selected' : '' ?>>Amount ↓</option>
                        <option value="name_asc" <?= $filters['sort'] === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
                        <option value="name_desc" <?= $filters['sort'] === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
                    </select>
                </form>

                <button type="button" class="btn btn-primary" data-open-modal="billModal" onclick="openAddBillModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Add Bill
                </button>
            </div>

            <div class="panel">
                <div class="table-wrap">
                    <?php if (empty($bills)): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/>
                            </svg>
                            <p>No bills match your filters yet.<br>Try adjusting filters, or add a new bill.</p>
                        </div>
                    <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Bill</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Due Date</th>
                                <th>Frequency</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bills as $bill):
                                $display = bill_display_status($bill['status'], $bill['due_date']);
                                $due = new DateTime($bill['due_date']);
                            ?>
                            <tr>
                                <td>
                                    <div class="cell-name"><?= e($bill['name']) ?></div>
                                    <?php if (!empty($bill['notes'])): ?>
                                        <div class="cell-muted"><?= e(mb_strimwidth($bill['notes'], 0, 60, '…')) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="category-pill"><?= category_icon($bill['category']) ?> <?= e($bill['category']) ?></span></td>
                                <td class="cell-name"><?= format_money((float)$bill['amount'], $currency) ?></td>
                                <td><?= $due->format('M j, Y') ?></td>
                                <td class="cell-muted"><?= e($bill['frequency']) ?></td>
                                <td><span class="bill-status <?= status_badge_class($display) ?>"><?= status_label($display) ?></span></td>
                                <td>
                                    <div class="cell-actions">
                                        <?php if ($display === 'paid'): ?>
                                            <form method="POST" style="display:contents;">
                                                <input type="hidden" name="action" value="mark_unpaid">
                                                <input type="hidden" name="bill_id" value="<?= (int)$bill['id'] ?>">
                                                <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
                                                <button type="submit" class="icon-btn" title="Mark as Unpaid">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display:contents;">
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="bill_id" value="<?= (int)$bill['id'] ?>">
                                                <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
                                                <button type="submit" class="icon-btn" title="Mark as Paid">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <button type="button" class="icon-btn" title="Edit"
                                            onclick='openEditBillModal(<?= json_encode($bill, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                        </button>

                                        <form method="POST" data-confirm="Delete this bill? This cannot be undone." style="display:contents;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="bill_id" value="<?= (int)$bill['id'] ?>">
                                            <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
                                            <button type="submit" class="icon-btn danger" title="Delete">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<?php
$redirectTo = 'bills.php';
include __DIR__ . '/partials/bill-modal.php';
?>

<script src="assets/js/app.js"></script>
<?php if ($autoOpenAdd): ?>
<script>document.addEventListener('DOMContentLoaded', function () { openAddBillModal(); });</script>
<?php endif; ?>
</body>
</html>
