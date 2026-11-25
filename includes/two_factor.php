<?php
/**
 * AfterBill - Two-factor login with an authenticator app (TOTP, RFC 6238)
 *
 * Works with Google Authenticator, Microsoft Authenticator, Authy, 1Password, etc.: 6-digit
 * codes, 30-second steps, HMAC-SHA1. A code is accepted for the current step and one step either
 * side (clock drift), and never twice (users.totp_last_step). Recovery codes are one-time,
 * stored only as SHA-256 hashes.
 * Columns/tables: database/migrations/005_more_features.sql
 */

require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;

const TOTP_DIGITS = 6;
const TOTP_PERIOD = 30;
const TOTP_WINDOW = 1;            // steps accepted either side of "now"
const RECOVERY_CODE_COUNT = 8;
const TWO_FACTOR_PENDING_SECONDS = 300; // time allowed to type the code after the password
const TWO_FACTOR_MAX_TRIES = 5;

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Encode(string $bytes): string
{
    $bits = '';
    foreach (str_split($bytes) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32Decode(string $text): string
{
    $text = strtoupper(preg_replace('/[\s=-]+/', '', $text));
    $bits = '';
    foreach (str_split($text) as $c) {
        $v = strpos(BASE32_ALPHABET, $c);
        if ($v === false) {
            return '';
        }
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/** New random 160-bit secret, base32 (32 characters). */
function totpNewSecret(): string
{
    return base32Encode(random_bytes(20));
}

/** The 6-digit code for a 30-second step. */
function totpCode(string $secret, int $step): string
{
    $key = base32Decode($secret);
    $hash = hash_hmac('sha1', pack('J', $step), $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
        | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string)($value % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

function totpCurrentStep(?int $time = null): int
{
    return intdiv($time ?? time(), TOTP_PERIOD);
}

/**
 * The step $code matches (current ± TOTP_WINDOW), or null. Steps at or before $lastStep are
 * refused, so a code that was already used (or an older one) can't be replayed.
 */
function totpMatchStep(string $secret, string $code, ?int $lastStep = null, ?int $time = null): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{' . TOTP_DIGITS . '}$/', $code)) {
        return null;
    }
    $now = totpCurrentStep($time);
    for ($step = $now - TOTP_WINDOW; $step <= $now + TOTP_WINDOW; $step++) {
        if ($lastStep !== null && $step <= $lastStep) {
            continue;
        }
        if (hash_equals(totpCode($secret, $step), $code)) {
            return $step;
        }
    }
    return null;
}

/** otpauth:// link the authenticator app reads from the QR code. */
function totpUri(string $secret, string $email): string
{
    return 'otpauth://totp/' . rawurlencode('AfterBill:' . $email)
        . '?secret=' . $secret . '&issuer=AfterBill&algorithm=SHA1&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}

/** QR code for $uri as an SVG data: URI (allowed by the CSP's img-src data:). */
function totpQrDataUri(string $uri): string
{
    $options = new QROptions([
        'outputType'   => QROutputInterface::MARKUP_SVG,
        'outputBase64' => true,
        'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::M,
        'addQuietzone' => true,
    ]);
    return (new QRCode($options))->render($uri);
}

/** "abcd-efgh" style for showing a secret in groups of 4. */
function totpGroup(string $secret): string
{
    return trim(chunk_split($secret, 4, ' '));
}

// ---- Recovery codes ------------------------------------------------------------------------

function recoveryCodeHash(string $code): string
{
    return hash('sha256', strtolower(preg_replace('/[^0-9a-z]/i', '', $code)));
}

/** Replaces the user's recovery codes with RECOVERY_CODE_COUNT new ones; returns them (shown once). */
function recoveryCodesRegenerate(PDO $pdo, int $userId): array
{
    $codes = [];
    for ($i = 0; $i < RECOVERY_CODE_COUNT; $i++) {
        $raw = bin2hex(random_bytes(5)); // 10 hex characters
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
    }
    $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);
    $ins = $pdo->prepare('INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)');
    foreach ($codes as $code) {
        $ins->execute([$userId, recoveryCodeHash($code)]);
    }
    return $codes;
}

/** Uses up a recovery code if it is valid and unused. Returns whether it was accepted. */
function recoveryCodeUse(PDO $pdo, int $userId, string $code): bool
{
    $stmt = $pdo->prepare(
        'UPDATE user_recovery_codes SET used_at = NOW()
         WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1'
    );
    $stmt->execute([$userId, recoveryCodeHash($code)]);
    return $stmt->rowCount() === 1;
}

function recoveryCodesLeft(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Checks a code typed at login or when turning 2FA off: an authenticator code (recorded so it
 * can't be reused) or, if $allowRecovery, a recovery code (used up). Returns 'totp', 'recovery'
 * or null.
 */
function twoFactorVerify(PDO $pdo, int $userId, string $code, bool $allowRecovery = true): ?string
{
    $stmt = $pdo->prepare('SELECT totp_secret, totp_last_step FROM users WHERE id = ? AND totp_enabled_at IS NOT NULL');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || $row['totp_secret'] === null) {
        return null;
    }
    $last = $row['totp_last_step'] !== null ? (int)$row['totp_last_step'] : null;
    $step = totpMatchStep($row['totp_secret'], $code, $last);
    if ($step !== null) {
        // Conditional update: if two requests race with the same code, only one wins.
        $upd = $pdo->prepare(
            'UPDATE users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)'
        );
        $upd->execute([$step, $userId, $step]);
        return $upd->rowCount() === 1 ? 'totp' : null;
    }
    if ($allowRecovery && preg_match('/^[0-9a-f]{5}-?[0-9a-f]{5}$/i', trim($code)) && recoveryCodeUse($pdo, $userId, trim($code))) {
        return 'recovery';
    }
    return null;
}

/** Whether two-factor login is on for this user (false if migration 005 hasn't run). */
function twoFactorEnabled(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT totp_enabled_at IS NOT NULL FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}
