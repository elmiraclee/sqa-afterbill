<?php
/**
 * AfterBill - Settings
 * Profile, account (password) and preference management.
 */

require_once __DIR__ . '/includes/auth.php';
require_login();

require_once __DIR__ . '/includes/functions.php';

$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pdo = getPDO();

    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $errors = [];

        if (strlen($fullName) < 2) {
            $errors[] = 'Full name must be at least 2 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($username !== '' && !preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3-50 characters (letters, numbers, underscore only).';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                $errors[] = 'That email is already used by another account.';
            }
        }

        if (empty($errors) && $username !== '') {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
            $stmt->execute([$username, $userId]);
            if ($stmt->fetch()) {
                $errors[] = 'That username is already taken.';
            }
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, email = ? WHERE id = ?');
            $stmt->execute([$fullName, $username !== '' ? $username : null, $email, $userId]);
            $_SESSION['user_name'] = $fullName;
            flash_set('success', 'Profile updated successfully.');
        } else {
            foreach ($errors as $err) {
                flash_set('error', $err);
            }
        }
        header('Location: settings.php');
        exit;
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $errors = [];

        $user = get_user($userId);
        if (!$user || !password_verify($current, $user['password'])) {
            $errors[] = 'Current password is incorrect.';
        }
        if (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }

        if (empty($errors)) {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([$hashed, $userId]);
            flash_set('success', 'Password changed successfully.');
        } else {
            foreach ($errors as $err) {
                flash_set('error', $err);
            }
        }
        header('Location: settings.php');
        exit;
    }

    if ($action === 'update_preferences') {
        $currency = $_POST['currency'] ?? 'PHP';
        $reminderDays = (int)($_POST['reminder_days_before'] ?? 3);
        $notify = isset($_POST['notify_in_app']) ? 1 : 0;
        $errors = [];

        if (!array_key_exists($currency, CURRENCIES)) {
            $errors[] = 'Please choose a valid currency.';
        }
        if ($reminderDays < 0 || $reminderDays > 30) {
            $errors[] = 'Reminder days must be between 0 and 30.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('UPDATE users SET currency = ?, reminder_days_before = ?, notify_in_app = ? WHERE id = ?');
            $stmt->execute([$currency, $reminderDays, $notify, $userId]);
            flash_set('success', 'Preferences saved.');
        } else {
            foreach ($errors as $err) {
                flash_set('error', $err);
            }
        }
        header('Location: settings.php');
        exit;
    }
}

$user = get_user($userId);
$userName = $user['full_name'] ?? ($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pageTitle = 'Settings';
$pageSubtitle = 'Manage your profile, account and preferences.';
$activePage = 'settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings – AfterBill</title>
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

            <div class="settings-grid">
                <div class="panel full-width">
                    <div class="panel-header"><span class="panel-title">Profile</span></div>
                    <div class="panel-body padded">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_profile">
                            <div class="form-grid-2">
                                <div class="form-row">
                                    <label for="full_name">Full Name</label>
                                    <input type="text" id="full_name" name="full_name" class="input" value="<?= e($user['full_name']) ?>" required maxlength="120">
                                </div>
                                <div class="form-row">
                                    <label for="username">Username (optional)</label>
                                    <input type="text" id="username" name="username" class="input" value="<?= e($user['username'] ?? '') ?>" maxlength="50" placeholder="e.g. juan_delacruz">
                                </div>
                            </div>
                            <div class="form-row">
                                <label for="email">Email Address</label>
                                <input type="email" id="email" name="email" class="input" value="<?= e($user['email']) ?>" required maxlength="180">
                            </div>
                            <button type="submit" class="btn btn-primary">Save Profile</button>
                        </form>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header"><span class="panel-title">Change Password</span></div>
                    <div class="panel-body padded">
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">
                            <div class="form-row">
                                <label for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password" class="input" required autocomplete="current-password">
                            </div>
                            <div class="form-row">
                                <label for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="input" required minlength="8" autocomplete="new-password">
                            </div>
                            <div class="form-row">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" class="input" required minlength="8" autocomplete="new-password">
                            </div>
                            <button type="submit" class="btn btn-primary">Update Password</button>
                        </form>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header"><span class="panel-title">Preferences</span></div>
                    <div class="panel-body padded">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_preferences">
                            <div class="form-row">
                                <label for="currency">Default Currency</label>
                                <select id="currency" name="currency" class="input">
                                    <?php foreach (CURRENCIES as $code => $symbol): ?>
                                        <option value="<?= e($code) ?>" <?= ($user['currency'] ?? 'PHP') === $code ? 'selected' : '' ?>><?= e($code) ?> (<?= e($symbol) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-row">
                                <label for="reminder_days_before">Remind Me Before Due Date (days)</label>
                                <input type="number" id="reminder_days_before" name="reminder_days_before" class="input" min="0" max="30" value="<?= (int)($user['reminder_days_before'] ?? 3) ?>">
                                <p class="form-hint">Bills due within this many days show up as reminders.</p>
                            </div>
                            <div class="form-row">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="notify_in_app" value="1" <?= !empty($user['notify_in_app']) ? 'checked' : '' ?> style="width:16px; height:16px; accent-color: var(--electric-blue);">
                                    Show in-app reminder notifications
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary">Save Preferences</button>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
