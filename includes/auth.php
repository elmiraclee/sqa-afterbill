<?php
/**
 * AfterBill - Session guard
 *
 * Every protected page starts with require_login() instead of its own session check, so these
 * rules apply everywhere:
 *   - Idle timeout: a session unused for SESSION_IDLE_MINUTES is ended (shared/school computers).
 *   - Password change signs out other devices: the session stores a fingerprint of the password
 *     hash it logged in with; once the password changes, every other session's fingerprint is
 *     stale and that session is ended on its next request. The session that made the change
 *     gets the new fingerprint (auth_login_session) and stays signed in.
 *   - A session whose user was deleted is ended.
 *   - A session signed out from another device (Account → Signed-in devices) is ended
 *     (includes/sessions.php).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/sessions.php';

const SESSION_IDLE_MINUTES = 30;

/** Why the session was ended: 'expired', 'signed_out', 'deleted' (account.php), or null. */
$GLOBALS['auth_end_reason'] = null;

function auth_fingerprint(string $passwordHash): string
{
    return hash('sha256', $passwordHash);
}

function session_idle_expired(int $lastActivity, int $now): bool
{
    return $now - $lastActivity > SESSION_IDLE_MINUTES * 60;
}

/**
 * Marks the current session as signed in. Call after password_verify()/registration/password
 * change. New session id, so a session id planted before login (session fixation) is useless.
 */
function auth_login_session(int $userId, string $fullName, string $passwordHash): void
{
    $previous = session_status() === PHP_SESSION_ACTIVE ? sessionHash() : null;
    session_regenerate_id(true);
    unset($_SESSION['two_factor_pending']);
    $_SESSION['user_id']       = $userId;
    $_SESSION['user_name']     = $fullName;
    $_SESSION['auth_fp']       = auth_fingerprint($passwordHash);
    $_SESSION['last_activity'] = time();

    $pdo = getPDO();
    if ($previous !== null) {
        // The old session id is gone; so is its device row (e.g. after a password change).
        try {
            $pdo->prepare('DELETE FROM user_sessions WHERE session_hash = ?')->execute([$previous]);
        } catch (PDOException $e) {
        }
    }
    sessionTrackStart($pdo, $userId, $_SESSION['auth_fp']);
}

function auth_end_session(string $reason): void
{
    sessionTrackEnd(getPDO());
    $_SESSION = [];
    session_destroy();
    session_start(); // fresh, empty session so the page can still render forms with a CSRF token
    session_regenerate_id(true);
    $GLOBALS['auth_end_reason'] = $reason;
}

/**
 * Starts the session and returns whether a valid user is signed in. Ends the session (and
 * returns false) if it is idle, its password changed, or its user no longer exists.
 */
function auth_check(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if (session_idle_expired((int)($_SESSION['last_activity'] ?? 0), time())) {
        auth_end_session('expired');
        return false;
    }

    try {
        $stmt = getPDO()->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $hash = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $hash = false;
    }
    if ($hash === false || !hash_equals(auth_fingerprint((string)$hash), (string)($_SESSION['auth_fp'] ?? ''))) {
        auth_end_session('signed_out');
        return false;
    }

    if (!sessionTrackCheck(getPDO(), (int)$_SESSION['user_id'], (string)$_SESSION['auth_fp'])) {
        auth_end_session('revoked');
        return false;
    }

    $_SESSION['last_activity'] = time();
    return true;
}

/** For pages in the project root: sends anyone not validly signed in to the login page. */
function require_login(): void
{
    if (auth_check()) {
        return;
    }
    $reason = $GLOBALS['auth_end_reason'];
    header('Location: auth/login.php' . ($reason ? '?reason=' . $reason : ''));
    exit;
}

/** Message for the login page after a session was ended. */
function auth_end_message(?string $reason): string
{
    return match ($reason) {
        'expired'    => 'You were signed out after ' . SESSION_IDLE_MINUTES . ' minutes of inactivity. Please log in again.',
        'signed_out' => 'You were signed out because your password was changed. Please log in again.',
        'deleted'    => 'Your account and all of its data have been permanently deleted.',
        'reset'      => 'Your password has been reset. Log in with your new password.',
        'revoked'    => 'You were signed out from another device. Please log in again.',
        'two_factor_expired' => 'Your login timed out before the code was entered. Please log in again.',
        'two_factor_failed'  => 'Too many wrong codes. Please log in again.',
        default      => '',
    };
}
