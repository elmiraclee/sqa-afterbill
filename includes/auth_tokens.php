<?php
/**
 * AfterBill - Emailed one-time links (verify email, reset password, confirm email change)
 *
 * The link carries a random 256-bit token; the database stores only its SHA-256 hash. A token
 * works once, expires, and requesting a new one cancels the user's older ones of the same kind.
 * Table: database/migrations/003_email_verification_password_reset.sql
 */

require_once __DIR__ . '/mailer.php';

const TOKEN_LIFETIME_MINUTES = [
    'verify_email'   => 24 * 60,
    'reset_password' => 30,
    'email_change'   => 24 * 60,
];

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Creates a token (cancelling older unused ones of the same purpose) and returns it. */
function token_create(PDO $pdo, int $userId, string $purpose, ?string $data = null): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL')
        ->execute([$userId, $purpose]);
    $pdo->prepare(
        'INSERT INTO auth_tokens (user_id, purpose, token_hash, data, expires_at)
         VALUES (?, ?, ?, ?, NOW() + INTERVAL ' . (int)TOKEN_LIFETIME_MINUTES[$purpose] . ' MINUTE)'
    )->execute([$userId, $purpose, token_hash($token), $data]);
    return $token;
}

/**
 * The token's row (plus the user's name/email), or null if it is unknown, used, expired, or
 * for another purpose.
 */
function token_find(PDO $pdo, string $token, string $purpose): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT t.id, t.user_id, t.data, u.full_name, u.email, u.password
         FROM auth_tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.purpose = ? AND t.used_at IS NULL AND t.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([token_hash($token), $purpose]);
    return $stmt->fetch() ?: null;
}

/** Marks a token used. Returns false if it was already used (e.g. a double click). */
function token_consume(PDO $pdo, int $tokenId): bool
{
    $stmt = $pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
    $stmt->execute([$tokenId]);
    return $stmt->rowCount() === 1;
}

// ---------------------------------------------------------------------------------------------
// The emails
// ---------------------------------------------------------------------------------------------

function send_verification_email(PDO $pdo, int $userId, string $name, string $email): bool
{
    $link = app_url('auth/verify_email.php?token=' . token_create($pdo, $userId, 'verify_email'));
    return send_mail($email, $name, 'Verify your AfterBill email address',
        "Hi {$name},\n\n"
        . "Welcome to AfterBill! Please confirm this is your email address by opening the link below:\n\n"
        . "{$link}\n\n"
        . "The link works once and expires in 24 hours.\n\n"
        . "If you didn't create an AfterBill account, you can ignore this email.\n\n— AfterBill");
}

function send_password_reset_email(PDO $pdo, int $userId, string $name, string $email): bool
{
    $link = app_url('auth/reset_password.php?token=' . token_create($pdo, $userId, 'reset_password'));
    return send_mail($email, $name, 'Reset your AfterBill password',
        "Hi {$name},\n\n"
        . "Someone (hopefully you) asked to reset the password for your AfterBill account.\n"
        . "To choose a new password, open the link below:\n\n"
        . "{$link}\n\n"
        . "The link works once and expires in " . TOKEN_LIFETIME_MINUTES['reset_password'] . " minutes.\n\n"
        . "If you didn't ask for this, ignore this email. Your password stays the same.\n\n— AfterBill");
}

function send_email_change_emails(PDO $pdo, int $userId, string $name, string $oldEmail, string $newEmail): bool
{
    $link = app_url('auth/verify_email.php?token=' . token_create($pdo, $userId, 'email_change', $newEmail));
    $sent = send_mail($newEmail, $name, 'Confirm your new AfterBill email address',
        "Hi {$name},\n\n"
        . "You asked to change your AfterBill email address to this one ({$newEmail}).\n"
        . "To confirm, open the link below:\n\n"
        . "{$link}\n\n"
        . "Until you do, your account keeps using {$oldEmail}. The link expires in 24 hours.\n\n"
        . "If you didn't ask for this, you can ignore this email.\n\n— AfterBill");
    // Heads-up to the current address, so a takeover attempt doesn't go unnoticed.
    send_mail($oldEmail, $name, 'Your AfterBill email address is being changed',
        "Hi {$name},\n\n"
        . "Someone signed in to your AfterBill account asked to change its email address to {$newEmail}.\n"
        . "Nothing changes unless that address confirms it.\n\n"
        . "If this wasn't you, log in and change your password right away.\n\n— AfterBill");
    return $sent;
}
