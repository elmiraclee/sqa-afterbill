<?php
/**
 * AfterBill - Forgot password: emails a one-time reset link.
 *
 * Always shows the same message whether or not the email has an account, so this page can't be
 * used to find out who is registered. Rate-limited per IP (includes/rate_limit.php).
 */

require_once __DIR__ . '/../includes/auth.php';

if (auth_check()) {
    header('Location: ../dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/auth_tokens.php';
require_once __DIR__ . '/../partials/auth-shell.php';

$errors  = [];
$success = '';
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pdo = getPDO();

    if (!csrf_valid()) {
        $errors[] = CSRF_ERROR;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (($wait = rate_limit_minutes_left($pdo, 'email_request')) > 0) {
        $errors[] = rate_limit_message($wait);
    } else {
        rate_limit_hit($pdo, 'email_request');
        try {
            $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($user = $stmt->fetch()) {
                send_password_reset_email($pdo, (int)$user['id'], $user['full_name'], $user['email']);
            }
        } catch (PDOException $e) {
            error_log('AfterBill forgot_password: ' . $e->getMessage());
        }
        $success = 'If an account uses ' . $email . ', we\'ve sent it a link to reset the password. '
            . 'It expires in ' . TOKEN_LIFETIME_MINUTES['reset_password'] . ' minutes. Check your spam folder too.';
        $email = '';
    }
}

auth_shell_start('Forgot Password', 'Forgot your password?', 'Enter your email and we\'ll send you a link to choose a new one.');
auth_shell_alerts($errors, $success);
?>
                    <form method="POST" action="" novalidate>
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="input-wrapper">
                                <input type="email" id="email" name="email" placeholder="you@example.com"
                                    value="<?= htmlspecialchars($email) ?>" required autocomplete="email">
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">Send Reset Link</button>
                    </form>
<?php
auth_shell_end();
