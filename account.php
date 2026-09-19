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

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT id, full_name, email, created_at FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

// The session points at a user that no longer exists — end it cleanly via the existing logout.
if (!$user) {
    header('Location: auth/logout.php');
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

            <a href="bills.php" class="nav-item">
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

            <a href="reminders.php" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/>
                </svg>
                Reminders
            </a>

            <span class="nav-label" style="margin-top:10px;">Account</span>

            <a href="account.php" class="nav-item active">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                </svg>
                Account
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
                <h2>Account</h2>
                <p>Manage your personal details.</p>
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
