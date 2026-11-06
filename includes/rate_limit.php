<?php
/**
 * AfterBill - Per-IP rate limiting
 *
 * The per-account lockout (loginLockMinutesLeft in includes/activity.php) stops guessing one
 * account's password, but not trying one common password against many emails, or mass-creating
 * accounts. These limits count events per IP address across all accounts.
 * Table: database/migrations/002_rate_limits.sql. Never throws: without the table, no limit.
 */

// action => [max events, window in minutes]
const RATE_LIMITS = [
    'login_failed'  => [20, 15],  // failed logins from one IP, any email (known or not)
    'register'      => [10, 60],  // registration attempts from one IP
    'email_request' => [5, 60],   // forgot-password / resend-verification emails from one IP
];

function rate_limit_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Minutes until this IP may try `action` again, or 0 if it is not limited. */
function rate_limit_minutes_left(PDO $pdo, string $action): int
{
    [$max, $window] = RATE_LIMITS[$action];
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS hits, MIN(created_at) AS oldest FROM rate_limits
             WHERE ip = ? AND action = ? AND created_at >= NOW() - INTERVAL ' . (int)$window . ' MINUTE'
        );
        $stmt->execute([rate_limit_ip(), $action]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return 0;
    }
    if ((int)$row['hits'] < $max) {
        return 0;
    }
    // Sliding window: a slot frees up when the oldest counted event leaves the window.
    $freeAt = (new DateTime($row['oldest']))->modify('+' . (int)$window . ' minutes');
    return max(1, (int)ceil(($freeAt->getTimestamp() - time()) / 60));
}

function rate_limit_hit(PDO $pdo, string $action): void
{
    try {
        $pdo->prepare('INSERT INTO rate_limits (ip, action) VALUES (?, ?)')->execute([rate_limit_ip(), $action]);
        // Keep the table small; rows older than the longest window are never counted again.
        $pdo->exec('DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL 1 DAY');
    } catch (PDOException $e) {
        // Best-effort, like logActivity().
    }
}

function rate_limit_message(int $minutes): string
{
    return 'Too many attempts from your network. Please wait ' . $minutes
        . ' minute' . ($minutes === 1 ? '' : 's') . ' and try again.';
}
