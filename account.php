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
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/reminders.php';
require_once __DIR__ . '/includes/two_factor.php';
require_once __DIR__ . '/includes/data_export.php';

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

$reminderErrors = [];
$reminders = reminderSettings($pdo, $userId); // null = migration 004 not run

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reminders') {
    $enabled = ($_POST['reminder_emails'] ?? '') === '1';
    $days = $_POST['reminder_days'] ?? '';

    if (!csrf_valid()) {
        $reminderErrors[] = CSRF_ERROR;
    }
    if ($reminders === null) {
        $reminderErrors[] = 'Email reminders are not set up in this database yet. Run database/migrations/004_categories_payments_reminders.sql.';
    }
    if (!ctype_digit((string)$days) || !in_array((int)$days, REMINDER_DAY_CHOICES, true)) {
        $reminderErrors[] = 'Please choose how many days ahead to be reminded.';
    }

    if (empty($reminderErrors)) {
        try {
            $pdo->prepare('UPDATE users SET reminder_emails = ?, reminder_days = ? WHERE id = ?')
                ->execute([$enabled ? 1 : 0, (int)$days, $userId]);
            if ($enabled !== $reminders['enabled'] || (int)$days !== $reminders['days']) {
                logActivity($pdo, $userId, 'reminders_updated', $enabled
                    ? 'Email reminders on — ' . (int)$days . ' day' . ((int)$days === 1 ? '' : 's') . ' ahead'
                    : 'Email reminders off');
            }
            header('Location: account.php?updated=reminders#reminders');
            exit;
        } catch (PDOException $e) {
            $reminderErrors[] = 'Could not save your reminder settings. Please try again later.';
        }
    }
    // Show what was submitted again.
    if ($reminders !== null) {
        $reminders['enabled'] = $enabled;
    }
}

// ---------------------------------------------------------------------------------------------
// Two-factor login, signed-in devices, data export
// ---------------------------------------------------------------------------------------------
$securityErrors = [];   // shown in the panel named by $securityPanel
$securityPanel = '';
$action = $_POST['action'] ?? '';
$twoFactorReady = true; // migration 005 run?
try {
    $tf = $pdo->prepare('SELECT totp_enabled_at FROM users WHERE id = ?');
    $tf->execute([$userId]);
    $twoFactorOn = $tf->fetchColumn() !== null;
} catch (PDOException $e) {
    $twoFactorReady = false;
    $twoFactorOn = false;
}
$passwordOk = fn() => password_verify((string)($_POST['current_password'] ?? ''), $user['password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action,
        ['2fa_start', '2fa_cancel', '2fa_enable', '2fa_disable', '2fa_recovery', 'revoke_session', 'revoke_others', 'export_data'], true)) {
    $securityPanel = str_starts_with($action, '2fa') ? 'two-factor' : ($action === 'export_data' ? 'export' : 'devices');

    if (!csrf_valid()) {
        $securityErrors[] = CSRF_ERROR;
    } elseif (!$twoFactorReady && $action !== 'export_data') {
        $securityErrors[] = 'This is not set up in this database yet. Run database/migrations/005_more_features.sql.';
    } elseif ($action === '2fa_start' && !$twoFactorOn) {
        // The secret lives only in the session until the user proves their app works.
        $_SESSION['totp_setup'] = ['secret' => totpNewSecret(), 'at' => time()];
        header('Location: account.php#two-factor');
        exit;
    } elseif ($action === '2fa_cancel') {
        unset($_SESSION['totp_setup']);
        header('Location: account.php#two-factor');
        exit;
    } elseif ($action === '2fa_enable' && !$twoFactorOn) {
        $setup = $_SESSION['totp_setup'] ?? null;
        $step = null;
        if (!is_array($setup) || time() - (int)$setup['at'] > 1800) {
            unset($_SESSION['totp_setup']);
            $securityErrors[] = 'The setup expired. Click "Set up two-factor login" to start again.';
        } elseif (!$passwordOk()) {
            $securityErrors[] = 'Current password is incorrect.';
        } elseif (($step = totpMatchStep($setup['secret'], (string)($_POST['code'] ?? ''))) === null) {
            $securityErrors[] = 'That code didn\'t match. Make sure you scanned the code above and type the newest 6-digit code.';
        }
        if (empty($securityErrors)) {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled_at = NOW(), totp_last_step = ? WHERE id = ?')
                ->execute([$setup['secret'], $step, $userId]);
            $_SESSION['flash_recovery_codes'] = recoveryCodesRegenerate($pdo, $userId);
            $pdo->commit();
            unset($_SESSION['totp_setup']);
            logActivity($pdo, $userId, 'two_factor_enabled');
            header('Location: account.php?updated=2fa_on#two-factor');
            exit;
        }
    } elseif ($action === '2fa_disable' && $twoFactorOn) {
        if (!$passwordOk()) {
            $securityErrors[] = 'Current password is incorrect.';
        } elseif (twoFactorVerify($pdo, $userId, (string)($_POST['code'] ?? '')) === null) {
            $securityErrors[] = 'Enter a current code from your authenticator app (or a recovery code) to turn this off.';
        } else {
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);
            logActivity($pdo, $userId, 'two_factor_disabled');
            header('Location: account.php?updated=2fa_off#two-factor');
            exit;
        }
    } elseif ($action === '2fa_recovery' && $twoFactorOn) {
        if (!$passwordOk()) {
            $securityErrors[] = 'Current password is incorrect.';
        } else {
            $_SESSION['flash_recovery_codes'] = recoveryCodesRegenerate($pdo, $userId);
            logActivity($pdo, $userId, 'recovery_codes_regenerated');
            header('Location: account.php?updated=2fa_codes#two-factor');
            exit;
        }
    } elseif ($action === 'revoke_session' || $action === 'revoke_others') {
        $id = $_POST['session_id'] ?? '';
        if ($action === 'revoke_session' && !ctype_digit((string)$id)) {
            $securityErrors[] = 'Unknown device.';
        } else {
            $n = sessionRevoke($pdo, $userId, $action === 'revoke_session' ? (int)$id : null);
            if ($n > 0) {
                logActivity($pdo, $userId, 'sessions_revoked', $n . ' device' . ($n === 1 ? '' : 's') . ' signed out');
            }
            header('Location: account.php?updated=devices&n=' . $n . '#devices');
            exit;
        }
    } elseif ($action === 'export_data') {
        if (!$passwordOk()) {
            $securityErrors[] = 'Current password is incorrect.';
        } else {
            try {
                $zipPath = buildDataExport($pdo, $userId);
                logActivity($pdo, $userId, 'data_exported');
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="afterbill-my-data-' . date('Y-m-d') . '.zip"');
                header('Content-Length: ' . filesize($zipPath));
                header('Cache-Control: private, no-store');
                readfile($zipPath);
                @unlink($zipPath);
                exit;
            } catch (RuntimeException $e) {
                error_log('AfterBill export failed: ' . $e->getMessage());
                $securityErrors[] = 'Could not build your download. Please try again later.';
            }
        }
    }
}

$totpSetup = !$twoFactorOn && is_array($_SESSION['totp_setup'] ?? null) ? $_SESSION['totp_setup'] : null;
$recoveryCodes = $_SESSION['flash_recovery_codes'] ?? null; // shown exactly once
unset($_SESSION['flash_recovery_codes']);
$recoveryUsedLeft = $_SESSION['flash_recovery_used'] ?? null;
unset($_SESSION['flash_recovery_used']);
$recoveryLeft = $twoFactorOn ? recoveryCodesLeft($pdo, $userId) : 0;
$devices = sessionList($pdo, $userId, (string)$_SESSION['auth_fp']);

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
            // Receipt files aren't rows, so note them before the payments disappear.
            try {
                $files = $pdo->prepare(
                    'SELECT p.receipt_file FROM payments p JOIN bills b ON b.bill_id = p.bill_id
                     WHERE b.user_id = ? AND p.receipt_file IS NOT NULL'
                );
                $files->execute([$userId]);
                $receiptFiles = $files->fetchAll(PDO::FETCH_COLUMN);
            } catch (PDOException $e) {
                $receiptFiles = []; // migration 004 not run: no receipts
            }

            // Everything the user owns goes with the row: bills (→ payments), budgets, custom
            // categories and the activity log all reference users with ON DELETE CASCADE.
            // Recurring-bill links are cleared first so MySQL never has to cascade through a bill
            // that points at another bill being deleted in the same statement. Bills and budgets
            // are deleted explicitly before the user, because they also point at the user's
            // custom categories, and the cascade order between those tables isn't guaranteed.
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE bills SET parent_bill_id = NULL WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM bills WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM budgets WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            $pdo->commit();

            foreach ($receiptFiles as $file) {
                deleteReceiptFile($file);
            }

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
        .form-group input[type="password"],
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
        .alert-warning { background: #FFFBEB; color: #B45309; }

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

            <?php include __DIR__ . '/partials/account-security.php'; ?>

            <?php if ($reminders !== null): ?>
            <div class="form-panel" id="reminders">
                <h1>Email Reminders</h1>
                <p>Get one email a day listing bills that are overdue or coming up, sent to <?= htmlspecialchars($user['email']) ?>. No email on days when nothing is due.</p>

                <?php if (($_GET['updated'] ?? '') === 'reminders'): ?>
                    <div class="alert alert-success">Reminder settings saved.</div>
                <?php endif; ?>

                <?php if (!empty($reminderErrors)): ?>
                    <div class="alert alert-error">
                        <ul>
                            <?php foreach ($reminderErrors as $err): ?>
                                <li><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="#reminders" novalidate>
                    <input type="hidden" name="action" value="reminders">
                    <?= csrf_field() ?>

                    <label class="confirm-check">
                        <input type="checkbox" name="reminder_emails" value="1" <?= $reminders['enabled'] ? 'checked' : '' ?>>
                        Email me about unpaid bills
                    </label>

                    <div class="form-group">
                        <label for="reminder_days">Remind me about bills due within</label>
                        <select id="reminder_days" name="reminder_days">
                            <?php foreach (REMINDER_DAY_CHOICES as $choice): ?>
                                <option value="<?= $choice ?>" <?= $reminders['days'] === $choice ? 'selected' : '' ?>>
                                    <?= $choice ?> day<?= $choice === 1 ? '' : 's' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Save Reminder Settings</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="form-panel danger-panel" id="delete-account">
                <h1>Delete Account</h1>
                <p>Permanently delete your account and everything in it: all bills, payments and receipts, budgets, custom categories and your activity log. Want a copy first? Use <a href="#export">Download My Data</a>. This cannot be undone.</p>

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
