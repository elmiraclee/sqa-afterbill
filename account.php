<?php
/**
 * AfterBill - Account Management
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/activity.php';

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT id, full_name, email, created_at FROM users WHERE id = ? LIMIT 1');
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
            $update = $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
            $update->execute([$fullName, $email, $userId]);

            // The sidebar on every page reads the name from the session.
            $_SESSION['user_name'] = $fullName;

            $changes = [];
            if ($fullName !== $user['full_name']) {
                $changes[] = 'name';
            }
            if ($email !== $user['email']) {
                $changes[] = 'email (' . $user['email'] . ' → ' . $email . ')';
            }
            logActivity($pdo, $userId, 'profile_updated', 'Changed ' . implode(' and ', $changes));

            header('Location: account.php?updated=profile');
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

    // Same minimum as auth/register.php. The 72-byte cap is bcrypt's limit: anything longer
    // would be silently truncated by password_hash(), so it is rejected instead.
    if (strlen($newPassword) < 8) {
        $passwordErrors[] = 'New password must be at least 8 characters.';
    } elseif (strlen($newPassword) > 72) {
        $passwordErrors[] = 'New password must be 72 characters or fewer.';
    }
    if ($newPassword !== $confirmPassword) {
        $passwordErrors[] = 'New passwords do not match.';
    }
    if ($currentPassword !== '' && $newPassword === $currentPassword) {
        $passwordErrors[] = 'New password must be different from your current password.';
    }

    if (empty($passwordErrors)) {
        try {
            $update = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

            // New credentials, new session id (also invalidates the old id for this browser).
            session_regenerate_id(true);
            logActivity($pdo, $userId, 'password_changed');

            header('Location: account.php?updated=password');
            exit;
        } catch (PDOException $e) {
            $passwordErrors[] = 'Could not change your password. Please try again later.';
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

        </main>
    </div>
</div>
</body>
</html>
