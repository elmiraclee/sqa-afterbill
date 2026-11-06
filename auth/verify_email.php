<?php
/**
 * AfterBill - Emailed confirmation links:
 *   - verify_email: a new account's address (registration, or resend_verification.php)
 *   - email_change: a new address requested on the Account page; the account keeps its old
 *                   address until this link is opened, so a typo can't lock anyone out.
 *
 * Opening the link does not sign anyone in: the user logs in normally afterwards.
 */

require_once __DIR__ . '/../includes/auth.php';
$signedIn = auth_check();

require_once __DIR__ . '/../includes/activity.php';
require_once __DIR__ . '/../includes/auth_tokens.php';
require_once __DIR__ . '/../partials/auth-shell.php';

$pdo   = getPDO();
$token = (string)($_GET['token'] ?? '');
$title = 'Email Confirmation';
$next  = $signedIn ? ['../account.php', 'Go to My Account'] : ['login.php', 'Log In'];

function show_result(string $heading, string $sub, array $next, int $status = 200): void
{
    http_response_code($status);
    auth_shell_start('Email Confirmation', $heading, $sub);
    echo '<a class="btn-primary" href="' . htmlspecialchars($next[0]) . '" style="text-decoration:none;justify-content:center;">'
        . htmlspecialchars($next[1]) . '</a>';
    auth_shell_end();
    exit;
}

if ($row = token_find($pdo, $token, 'verify_email')) {
    token_consume($pdo, (int)$row['id']);
    $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')
        ->execute([$row['user_id']]);
    logActivity($pdo, (int)$row['user_id'], 'email_verified', $row['email']);
    show_result('Email verified', 'Thanks! ' . $row['email'] . ' is confirmed. You can now log in.', $next);
}

if ($row = token_find($pdo, $token, 'email_change')) {
    $newEmail = (string)$row['data'];
    try {
        $pdo->beginTransaction();
        token_consume($pdo, (int)$row['id']);
        $pdo->prepare('UPDATE users SET email = ?, email_verified_at = NOW() WHERE id = ?')
            ->execute([$newEmail, $row['user_id']]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // UNIQUE key: someone registered this address after the change was requested.
        show_result('Email not changed', 'That address is now used by another account. Your email is still ' . $row['email'] . '.', $next, 409);
    }
    logActivity($pdo, (int)$row['user_id'], 'profile_updated', 'Changed email (' . $row['email'] . ' → ' . $newEmail . ')');
    show_result('Email changed', 'Your AfterBill email is now ' . $newEmail . '. Use it the next time you log in.', $next);
}

show_result('This link has expired', 'Confirmation links work once and expire after 24 hours. Log in or request a new link.',
    $signedIn ? $next : ['resend_verification.php', 'Send a New Verification Link'], 400);
