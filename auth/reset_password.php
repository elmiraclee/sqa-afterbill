<?php
/**
 * AfterBill - Reset password from an emailed link (auth/forgot_password.php).
 *
 * GET shows the form (the link alone changes nothing, so email scanners that open links are
 * harmless); POST sets the password and uses up the token. Changing the hash also signs out
 * every existing session (includes/auth.php fingerprint). Opening the link proves the user owns
 * the address, so an unverified account becomes verified.
 */

require_once __DIR__ . '/../includes/auth.php';
auth_check(); // start the session (a signed-in user may also reset)

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/activity.php';
require_once __DIR__ . '/../includes/password_policy.php';
require_once __DIR__ . '/../includes/auth_tokens.php';
require_once __DIR__ . '/../partials/auth-shell.php';

$pdo    = getPDO();
$token  = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$row    = token_find($pdo, $token, 'reset_password');
$errors = [];

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!csrf_valid()) {
        $errors[] = CSRF_ERROR;
    }
    $errors = array_merge($errors, password_policy_errors($password, 'Password', [$row['full_name'], $row['email']]));
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (empty($errors) && password_verify($password, $row['password'])) {
        $errors[] = 'Choose a password you haven\'t used for this account before.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            if (!token_consume($pdo, (int)$row['id'])) {
                throw new RuntimeException('token already used');
            }
            $pdo->prepare('UPDATE users SET password = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
            $pdo->commit();

            logActivity($pdo, (int)$row['user_id'], 'password_reset');
            // Any session in THIS browser is now stale too; start clean.
            if (isset($_SESSION['user_id'])) {
                auth_end_session('reset');
            }
            header('Location: login.php?reason=reset');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $row = null; // fall through to "link no longer valid"
        }
    }
}

if (!$row) {
    http_response_code(400);
    auth_shell_start('Reset Password', 'This link has expired', 'Reset links work once and expire after ' . TOKEN_LIFETIME_MINUTES['reset_password'] . ' minutes.');
    ?>
                    <a class="btn-primary" href="forgot_password.php" style="text-decoration:none;justify-content:center;">Send a New Link</a>
    <?php
    auth_shell_end();
    exit;
}

auth_shell_start('Reset Password', 'Choose a new password', 'For ' . $row['email']);
auth_shell_alerts($errors);
?>
                    <form method="POST" action="reset_password.php" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                        <div class="form-group">
                            <label for="password">New Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="password" name="password" required minlength="8" maxlength="72"
                                    autocomplete="new-password">
                            </div>
                            <?= password_rules_checklist('password') ?>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <div class="input-wrapper">
                                <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                                    maxlength="72" autocomplete="new-password">
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">Save New Password</button>
                    </form>
<?php
auth_shell_end(['password-rules.js']);
