<?php
/**
 * AfterBill - Account Management
 */

require_once __DIR__ . '/includes/auth.php';
require_login(); // session check, idle timeout, sign-out after a password change

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/auth_tokens.php';

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT id, full_name, email, password, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

// The session points at a user that no longer exists — end it here (logout.php is POST-only).
if (!$user) {
    session_unset();
    session_destroy();
    header('Location: auth/login.php');
    exit;
}

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$profileErrors = [];
$profileNotice = '';
$old = [
    'full_name' => $user['full_name'],
    'email'     => $user['email'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profile') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');

    $old['full_name'] = $fullName;
    $old['email']     = $email;

    if (!csrf_valid()) {
        $profileErrors[] = CSRF_ERROR;
    }

    // Same rules as auth/register.php, plus the column length limits (varchar 120 / 180).
    if (mb_strlen($fullName) < 2) {
        $profileErrors[] = 'Full name must be at least 2 characters.';
    } elseif (mb_strlen($fullName) > 120) {
        $profileErrors[] = 'Full name must be 120 characters or fewer.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $profileErrors[] = 'Please enter a valid email address.';
    } elseif (mb_strlen($email) > 180) {
        $profileErrors[] = 'Email must be 180 characters or fewer.';
    }

    // The email is the login, so changing it needs the current password: otherwise anyone at an
    // unattended, signed-in computer could take the account over. Checked before the uniqueness
    // check, so the form can't be used to probe which emails are registered.
    $emailChanged = strcasecmp($email, $user['email']) !== 0;
    if (empty($profileErrors) && $emailChanged) {
        $profilePassword = $_POST['current_password'] ?? '';
        if ($profilePassword === '') {
            $profileErrors[] = 'Enter your current password to change your email address.';
        } elseif (!password_verify($profilePassword, $user['password'])) {
            $profileErrors[] = 'Current password is incorrect.';
        }
    }

    // Friendly uniqueness check. "id != ?" so keeping your own email is never a conflict; the
    // column collation is case-insensitive, so Name@x.com and name@x.com count as the same.
    if (empty($profileErrors)) {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $check->execute([$email, $userId]);
        if ($check->fetch()) {
            $profileErrors[] = 'That email address is already used by another account.';
        }
    }

    if (empty($profileErrors) && $fullName === $user['full_name'] && $email === $user['email']) {
        $profileNotice = 'No changes to save.';
    } elseif (empty($profileErrors)) {
        try {
            // The name changes now. A new email only takes effect once a link sent TO it is opened
            // (auth/verify_email.php), so the account never switches to an address nobody can read.
            // A different capitalisation of the same address is just saved.
            $saveEmail = $emailChanged ? $user['email'] : $email;
            $update = $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
            $update->execute([$fullName, $saveEmail, $userId]);

            // The sidebar on every page reads the name from the session.
            $_SESSION['user_name'] = $fullName;

            if ($fullName !== $user['full_name']) {
                logActivity($pdo, $userId, 'profile_updated', 'Changed name');
            }
            $query = 'updated=profile';
            if ($emailChanged) {
                send_email_change_emails($pdo, $userId, $fullName, $user['email'], $email);
                $_SESSION['pending_email'] = $email;
                $query = 'updated=email_pending';
            }

            header('Location: account.php?' . $query);
            exit;
        } catch (PDOException $e) {
            // Backstop for two people claiming the same email at the same moment: the UNIQUE
            // key rejects the second UPDATE (MySQL error 1062) even though the check above passed.
            if (($e->errorInfo[1] ?? null) === 1062) {
                $profileErrors[] = 'That email address is already used by another account.';
            } else {
                $profileErrors[] = 'Could not update your profile. Please try again later.';
            }
        }
    }
}

$passwordErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!csrf_valid()) {
        $passwordErrors[] = CSRF_ERROR;
    }

    // Re-authenticate: always verify against the hash as it is in the database right now, even
    // though the user is already logged in, so an unattended session can't change the password.
    $hashStmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $hashStmt->execute([$userId]);
    $storedHash = (string)$hashStmt->fetchColumn();

    if ($currentPassword === '') {
        $passwordErrors[] = 'Current password is required.';
    } elseif (!password_verify($currentPassword, $storedHash)) {
        $passwordErrors[] = 'Current password is incorrect.';
    }

    // Same strength rules as auth/register.php (includes/password_policy.php).
    $passwordErrors = array_merge($passwordErrors,
        password_policy_errors($newPassword, 'New password', [$user['full_name'], $user['email']]));
    if ($newPassword !== $confirmPassword) {
        $passwordErrors[] = 'New passwords do not match.';
    }
    if ($currentPassword !== '' && $newPassword === $currentPassword) {
        $passwordErrors[] = 'New password must be different from your current password.';
    }

    if (empty($passwordErrors)) {
        try {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $update->execute([$newHash, $userId]);

            // New session id + new password fingerprint for THIS browser. Every other session
            // still holds the old fingerprint, so require_login() signs them out (includes/auth.php).
            auth_login_session($userId, (string)$_SESSION['user_name'], $newHash);
            logActivity($pdo, $userId, 'password_changed');

            header('Location: account.php?updated=password');
            exit;
        } catch (PDOException $e) {
            $passwordErrors[] = 'Could not change your password. Please try again later.';
        }
    }
}

$deleteErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    $deletePassword = $_POST['delete_password'] ?? '';

    if (!csrf_valid()) {
        $deleteErrors[] = CSRF_ERROR;
    } elseif ($deletePassword === '') {
        $deleteErrors[] = 'Enter your password to delete your account.';
    } elseif (!password_verify($deletePassword, $user['password'])) {
        $deleteErrors[] = 'Password is incorrect. Your account was not deleted.';
    } elseif (($_POST['confirm_delete'] ?? '') !== 'yes') {
        $deleteErrors[] = 'Tick the box to confirm that you understand this cannot be undone.';
    }

    if (empty($deleteErrors)) {
        try {
            // Everything the user owns goes with the row: bills (→ payments), budgets and the
            // activity log all reference users with ON DELETE CASCADE. Recurring-bill links are
            // cleared first so MySQL never has to cascade through a bill that points at another
            // bill being deleted in the same statement.
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE bills SET parent_bill_id = NULL WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            $pdo->commit();

            auth_end_session('deleted');
            header('Location: auth/login.php?reason=deleted');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $deleteErrors[] = 'Could not delete your account. Please try again later.';
        }
    }
}

$memberSince = (new DateTime($user['created_at']))->format('F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account – AfterBill</title>
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

        .page-body { padding: 32px; flex: 1; display: flex; flex-direction: column; align-items: center; gap: 20px; }

        .form-panel {
            background: var(--white);
            border-radius: 14px;
            border: 1px solid var(--border);
            width: 100%;
            max-width: 560px;
            padding: 28px 32px;
        }

        .form-panel h1 { font-size: 1.15rem; font-weight: 700; color: var(--deep-navy); margin-bottom: 4px; }
        .form-panel > p { font-size: 0.85rem; color: var(--text-muted); margin-bottom: 22px; }

        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.88rem;
            font-family: inherit;
            color: var(--text-dark);
            background: var(--white);
        }

        .form-group input:focus { outline: none; border-color: var(--electric-blue); }

        /* Live password rules (includes/password_policy.php + assets/js/password-rules.js) */
        .pw-rules { list-style: none; margin-top: 8px; display: grid; gap: 3px; }
        .pw-rules li { font-size: 0.75rem; color: var(--text-muted); padding-left: 18px; position: relative; }
        .pw-rules li::before { content: '○'; position: absolute; left: 2px; }
        .pw-rules li.met { color: #059669; }
        .pw-rules li.met::before { content: '✓'; font-weight: 700; }

        .form-actions { display: flex; gap: 12px; }

        .danger-panel { border-color: #FECACA; }
        .danger-panel h1 { color: #B91C1C; }
        .confirm-check { display: flex; gap: 8px; align-items: flex-start; font-size: 0.82rem; color: var(--text-dark); margin-bottom: 18px; cursor: pointer; }
        .confirm-check input { margin-top: 2px; }
        .btn-danger {
            padding: 10px 20px; border: none; border-radius: 8px; background: var(--danger); color: #fff;
            font-size: 0.88rem; font-weight: 600; font-family: inherit; cursor: pointer;
        }
        .btn-danger:hover { background: #DC2626; }

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

        .member-since { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 0.78rem; color: var(--text-muted); }

        .alert { padding: 12px 16px; border-radius: 8px; font-size: 0.82rem; margin-bottom: 18px; }
        .alert-success { background: #ECFDF5; color: #047857; }
        .alert-error { background: #FEF2F2; color: #B91C1C; }
        .alert-error ul { margin: 4px 0 0 18px; }
        .alert-info { background: #EFF6FF; color: #1D4ED8; }

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

    <?php $activePage = 'account'; include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2>Account</h2>
                <p>Manage your personal details.</p>
            </div>
            <div class="topbar-right">
                <?php require __DIR__ . '/partials/logout-button.php'; ?>
            </div>
        </header>

        <main class="page-body">

            <div class="form-panel">
                <h1>Profile</h1>
                <p>Update your name and email address.</p>

                <?php if (($_GET['updated'] ?? '') === 'profile'): ?>
                    <div class="alert alert-success">Profile updated successfully.</div>
                <?php elseif (($_GET['updated'] ?? '') === 'email_pending'): ?>
                    <div class="alert alert-success">
                        Almost done: we sent a confirmation link to
                        <strong><?= htmlspecialchars($_SESSION['pending_email'] ?? 'your new address') ?></strong>.
                        Your email changes once you open it. Until then, keep using <?= htmlspecialchars($user['email']) ?>.
                    </div>
                <?php endif; ?>

                <?php if ($profileNotice !== ''): ?>
                    <div class="alert alert-info"><?= htmlspecialchars($profileNotice) ?></div>
                <?php endif; ?>

                <?php if (!empty($profileErrors)): ?>
                    <div class="alert alert-error">
                        <strong>Please fix the following:</strong>
                        <ul>
                            <?php foreach ($profileErrors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" novalidate>
                    <input type="hidden" name="action" value="profile">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" maxlength="120"
                            value="<?= htmlspecialchars($old['full_name']) ?>" required autocomplete="name">
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" maxlength="180"
                            value="<?= htmlspecialchars($old['email']) ?>" required autocomplete="email">
                    </div>

                    <div class="form-group">
                        <label for="profile_current_password">Current Password <span style="font-weight:400;color:var(--text-muted);">(only needed to change your email)</span></label>
                        <input type="password" id="profile_current_password" name="current_password"
                            autocomplete="current-password">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            Save Changes
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                    </div>
                </form>

                <div class="member-since">Member since <?= htmlspecialchars($memberSince) ?></div>
            </div>

            <div class="form-panel">
                <h1>Change Password</h1>
                <p>Enter your current password to set a new one.</p>

                <?php if (($_GET['updated'] ?? '') === 'password'): ?>
                    <div class="alert alert-success">Password changed successfully.</div>
                <?php endif; ?>

                <?php if (!empty($passwordErrors)): ?>
                    <div class="alert alert-error">
                        <strong>Please fix the following:</strong>
                        <ul>
                            <?php foreach ($passwordErrors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" novalidate>
                    <input type="hidden" name="action" value="password">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password"
                            required autocomplete="current-password">
                    </div>

                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password"
                            required minlength="8" maxlength="72" autocomplete="new-password">
                        <?= password_rules_checklist('new_password') ?>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                            required minlength="8" maxlength="72" autocomplete="new-password">
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            Change Password
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                            </svg>
                        </button>
                    </div>
                </form>
            </div>

            <div class="form-panel danger-panel" id="delete-account">
                <h1>Delete Account</h1>
                <p>Permanently delete your account and everything in it: all bills, payments, budgets and your activity log. This cannot be undone.</p>

                <?php if (!empty($deleteErrors)): ?>
                    <div class="alert alert-error">
                        <ul>
                            <?php foreach ($deleteErrors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="#delete-account" novalidate
                    data-confirm="Delete your AfterBill account and all of its data permanently? This cannot be undone.">
                    <input type="hidden" name="action" value="delete_account">
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label for="delete_password">Password</label>
                        <input type="password" id="delete_password" name="delete_password"
                            required autocomplete="current-password">
                    </div>

                    <label class="confirm-check">
                        <input type="checkbox" name="confirm_delete" value="yes" required>
                        I understand my account and all of its data will be permanently deleted.
                    </label>

                    <div class="form-actions">
                        <button type="submit" class="btn-danger">Delete My Account</button>
                    </div>
                </form>
            </div>

        </main>
    </div>
</div>
    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
    <script src="assets/js/password-rules.js?v=<?= filemtime(__DIR__ . '/assets/js/password-rules.js') ?>"></script>
</body>
</html>
