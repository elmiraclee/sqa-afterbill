<?php
/**
 * AfterBill - Second login step for accounts with two-factor login on
 *
 * Reached from login.php after a correct password. The user types the 6-digit code from their
 * authenticator app, or one of their recovery codes. Limits: 5 minutes after the password,
 * 5 wrong codes (then back to the password), and wrong codes count toward the same per-account
 * lock and per-IP limit as wrong passwords.
 */

require_once __DIR__ . '/../includes/auth.php';

if (auth_check()) {
    header('Location: ../dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/activity.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/two_factor.php';
require_once __DIR__ . '/../partials/auth-shell.php';

function backToLogin(string $reason): void
{
    unset($_SESSION['two_factor_pending']);
    header('Location: login.php?reason=' . $reason);
    exit;
}

$pending = $_SESSION['two_factor_pending'] ?? null;
if (!is_array($pending) || !isset($pending['user_id'], $pending['at'], $pending['fp'])) {
    header('Location: login.php');
    exit;
}
if (time() - (int)$pending['at'] > TWO_FACTOR_PENDING_SECONDS) {
    backToLogin('two_factor_expired');
}

$pdo = getPDO();
$userId = (int)$pending['user_id'];

// The account must still be the one that typed the password: same password, 2FA still on.
$stmt = $pdo->prepare('SELECT full_name, password FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user || !hash_equals(auth_fingerprint($user['password']), (string)$pending['fp']) || !twoFactorEnabled($pdo, $userId)) {
    backToLogin('two_factor_expired');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim((string)($_POST['code'] ?? ''));

    $ipMinutes = rate_limit_minutes_left($pdo, 'login_failed');
    $lockMinutes = loginLockMinutesLeft($pdo, $userId);

    if (!csrf_valid()) {
        $errors[] = CSRF_ERROR;
    } elseif ($ipMinutes > 0) {
        $errors[] = rate_limit_message($ipMinutes);
    } elseif ($lockMinutes > 0) {
        backToLogin('two_factor_failed');
    } elseif ($code === '') {
        $errors[] = 'Enter the 6-digit code from your authenticator app.';
    } else {
        $how = twoFactorVerify($pdo, $userId, $code);
        if ($how !== null) {
            auth_login_session($userId, $user['full_name'], $user['password']);
            logActivity($pdo, $userId, 'login', $how === 'recovery'
                ? 'With a recovery code — ' . recoveryCodesLeft($pdo, $userId) . ' left'
                : 'With authenticator app');
            if ($how === 'recovery') {
                $_SESSION['flash_recovery_used'] = recoveryCodesLeft($pdo, $userId);
                header('Location: ../account.php#two-factor');
            } else {
                header('Location: ../dashboard.php');
            }
            exit;
        }

        logActivity($pdo, $userId, 'login_failed', 'Wrong two-factor code');
        rate_limit_hit($pdo, 'login_failed');
        $_SESSION['two_factor_pending']['tries'] = (int)($pending['tries'] ?? 0) + 1;
        if ($_SESSION['two_factor_pending']['tries'] >= TWO_FACTOR_MAX_TRIES) {
            backToLogin('two_factor_failed');
        }
        $errors[] = 'That code didn\'t work. Check the time on your phone is correct, and use the newest code.';
    }
}

auth_shell_start('Two-factor login', 'Enter your code', 'Open your authenticator app and type the 6-digit code for AfterBill.');
auth_shell_alerts($errors);
?>
                    <form method="POST" action="" novalidate>
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="code">Authentication code</label>
                            <div class="input-wrapper">
                                <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code"
                                    maxlength="20" placeholder="123456" required autofocus>
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">Verify and log in</button>
                    </form>
                    <p style="margin-top:16px;font-size:0.8rem;color:#6B7280;line-height:1.5;">
                        Lost your phone? Type one of your recovery codes (like <code>a1b2c-3d4e5</code>) instead.
                        Each one works once.
                    </p>
<?php
auth_shell_end();
