<?php
/**
 * AfterBill - Reminders
 * User-created reminders (optionally linked to a bill), plus a live
 * "Automatic Alerts" feed computed from upcoming/overdue bills.
 */

require_once __DIR__ . '/includes/auth.php';
require_login();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/reminders_repo.php';
require_once __DIR__ . '/includes/bills_repo.php';

$userId = current_user_id();
$user = get_user($userId);
$userName = $user['full_name'] ?? ($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));
$currency = $user['currency'] ?? 'PHP';
$reminderDaysAhead = (int)($user['reminder_days_before'] ?? 3);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $title = trim($_POST['title'] ?? '');
        $remindAt = trim($_POST['remind_at'] ?? '');
        $billId = (int)($_POST['bill_id'] ?? 0);
        $errors = [];

        if ($title === '') {
            $errors[] = 'Reminder title cannot be empty.';
        }

        $remindObj = DateTime::createFromFormat('Y-m-d\TH:i', $remindAt);
        if (!$remindObj) {
            $errors[] = 'Please provide a valid reminder date/time.';
        }

        if ($billId > 0 && !bills_find($userId, $billId)) {
            $errors[] = 'Selected bill was not found.';
            $billId = 0;
        }

        if (empty($errors)) {
            $data = [
                'title' => $title,
                'remind_at' => $remindObj->format('Y-m-d H:i:s'),
                'bill_id' => $billId ?: null,
            ];
            if ($action === 'create') {
                reminders_create($userId, $data);
                flash_set('success', 'Reminder created.');
            } else {
                $reminderId = (int)($_POST['reminder_id'] ?? 0);
                if (reminders_find($userId, $reminderId)) {
                    reminders_update($userId, $reminderId, $data);
                    flash_set('success', 'Reminder updated.');
                } else {
                    flash_set('error', 'Reminder not found.');
                }
            }
        } else {
            foreach ($errors as $err) {
                flash_set('error', $err);
            }
        }
        header('Location: reminders.php');
        exit;
    }

    if ($action === 'delete') {
        $reminderId = (int)($_POST['reminder_id'] ?? 0);
        if (reminders_find($userId, $reminderId)) {
            reminders_delete($userId, $reminderId);
            flash_set('success', 'Reminder deleted.');
        }
        header('Location: reminders.php');
        exit;
    }

    if ($action === 'toggle_complete') {
        $reminderId = (int)($_POST['reminder_id'] ?? 0);
        $completed = ($_POST['completed'] ?? '0') === '1';
        if (reminders_find($userId, $reminderId)) {
            reminders_set_completed($userId, $reminderId, $completed);
        }
        header('Location: reminders.php');
        exit;
    }
}

$reminders = reminders_fetch_all($userId);
$alerts = bills_due_alerts($userId, $reminderDaysAhead > 0 ? $reminderDaysAhead : 7);
$userBills = bills_fetch_all($userId, ['sort' => 'due_date_asc']);

$pageTitle = 'Reminders';
$pageSubtitle = 'Stay ahead of every due date.';
$activePage = 'reminders';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reminders – AfterBill</title>
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

            <div class="content-grid">
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title">Automatic Bill Alerts</span>
                        <span class="panel-badge amber"><?= count($alerts) ?> active</span>
                    </div>
                    <?php if (empty($alerts)): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p>No bills are due soon or overdue.<br>You're all caught up!</p>
                        </div>
                    <?php else: ?>
                        <div class="alert-list">
                            <?php foreach ($alerts as $alert): ?>
                                <div class="reminder-alert <?= e($alert['level']) ?>">
                                    <span class="dot"></span>
                                    <span><?= e($alert['message']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title">My Reminders</span>
                        <button type="button" class="btn btn-primary btn-sm" data-open-modal="reminderModal" onclick="openAddReminderModal()">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                            Add Reminder
                        </button>
                    </div>
                    <?php if (empty($reminders)): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
                            </svg>
                            <p>No reminders yet. Create one to get notified before a bill is due.</p>
                        </div>
                    <?php else: ?>
                        <div class="alert-list">
                            <?php foreach ($reminders as $reminder):
                                $remindAtObj = new DateTime($reminder['remind_at']);
                            ?>
                            <div class="reminder-alert <?= $reminder['is_completed'] ? '' : 'soon' ?>" style="justify-content:space-between; <?= $reminder['is_completed'] ? 'opacity:0.55;' : '' ?>">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <span class="dot" style="background: <?= $reminder['is_completed'] ? '#9CA3AF' : 'var(--electric-blue)' ?>;"></span>
                                    <div>
                                        <div style="font-weight:600; <?= $reminder['is_completed'] ? 'text-decoration:line-through;' : '' ?>"><?= e($reminder['title']) ?></div>
                                        <div style="font-size:0.75rem; color:var(--text-muted);">
                                            <?= $remindAtObj->format('M j, Y g:i A') ?>
                                            <?php if (!empty($reminder['bill_name'])): ?> · linked to <?= e($reminder['bill_name']) ?><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="cell-actions">
                                    <form method="POST" style="display:contents;">
                                        <input type="hidden" name="action" value="toggle_complete">
                                        <input type="hidden" name="reminder_id" value="<?= (int)$reminder['id'] ?>">
                                        <input type="hidden" name="completed" value="<?= $reminder['is_completed'] ? '0' : '1' ?>">
                                        <button type="submit" class="icon-btn" title="<?= $reminder['is_completed'] ? 'Mark as pending' : 'Mark as completed' ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        </button>
                                    </form>
                                    <button type="button" class="icon-btn" title="Edit"
                                        onclick='openEditReminderModal(<?= json_encode($reminder, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                    </button>
                                    <form method="POST" data-confirm="Delete this reminder?" style="display:contents;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="reminder_id" value="<?= (int)$reminder['id'] ?>">
                                        <button type="submit" class="icon-btn danger" title="Delete">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Add / Edit Reminder Modal -->
<div class="modal-overlay" id="reminderModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="reminderModalTitle">Add Reminder</h3>
            <button type="button" class="modal-close" data-close-modal>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" id="reminderForm">
            <input type="hidden" name="action" value="create" id="reminderFormAction">
            <input type="hidden" name="reminder_id" value="" id="reminderIdField">
            <div class="modal-body">
                <div class="form-row">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" class="input" placeholder="e.g. Pay electricity bill" required maxlength="150">
                </div>
                <div class="form-row">
                    <label for="remind_at">Reminder Date/Time</label>
                    <input type="datetime-local" id="remind_at" name="remind_at" class="input" required>
                </div>
                <div class="form-row">
                    <label for="bill_id">Link to a Bill (optional)</label>
                    <select id="bill_id" name="bill_id" class="input">
                        <option value="">None</option>
                        <?php foreach ($userBills as $b): ?>
                            <option value="<?= (int)$b['id'] ?>">
                                <?= e($b['name']) ?> — due <?= (new DateTime($b['due_date']))->format('M j, Y') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Cancel</button>
                <button type="submit" class="btn btn-primary">Save Reminder</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/app.js"></script>
<script>
    function openAddReminderModal() {
        document.getElementById('reminderModalTitle').textContent = 'Add Reminder';
        document.getElementById('reminderForm').reset();
        document.getElementById('reminderFormAction').value = 'create';
        document.getElementById('reminderIdField').value = '';
        document.getElementById('reminderModal').classList.add('open');
    }

    function openEditReminderModal(reminder) {
        document.getElementById('reminderModalTitle').textContent = 'Edit Reminder';
        document.getElementById('reminderFormAction').value = 'update';
        document.getElementById('reminderIdField').value = reminder.id;
        document.getElementById('title').value = reminder.title;
        document.getElementById('remind_at').value = reminder.remind_at ? reminder.remind_at.replace(' ', 'T').substring(0, 16) : '';
        document.getElementById('bill_id').value = reminder.bill_id || '';
        document.getElementById('reminderModal').classList.add('open');
    }
</script>
</body>
</html>
