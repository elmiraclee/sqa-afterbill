<?php
/**
 * AfterBill - Send a new verification link to an unverified account.
 * Same message whether or not the email exists / needs verifying (no account probing).
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
$email   = trim((string)($_GET['email'] ?? ''));

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
            $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE email = ? AND email_verified_at IS NULL LIMIT 1');
            $stmt->execute([$email]);
            if ($user = $stmt->fetch()) {
                send_verification_email($pdo, (int)$user['id'], $user['full_name'], $user['email']);
            }
        } catch (PDOException $e) {
            error_log('AfterBill resend_verification: ' . $e->getMessage());
        }
        $success = 'If ' . $email . ' has an account waiting for verification, we\'ve sent it a new link. Check your spam folder too.';
    }
}

auth_shell_start('Verify Email', 'Verify your email', 'We\'ll send a new confirmation link to your inbox.');
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
                        <button type="submit" class="btn-primary">Send Verification Link</button>
                    </form>
<?php
auth_shell_end();
