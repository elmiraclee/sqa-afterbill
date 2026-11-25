<?php
/**
 * AfterBill - Signed-in devices
 *
 * Every signed-in browser has a row in user_sessions (only a SHA-256 hash of its session id is
 * stored). auth_check() refuses a session whose row was deleted, so "Sign out" on the Account
 * page ends that browser's session on its next request. Everything here is best-effort: without
 * the table (migration 005 not run) tracking is simply off.
 */

const SESSION_SEEN_EVERY = 60; // seconds between last_seen_at updates

function sessionHash(): string
{
    return hash('sha256', session_id());
}

/** Records the current (just signed-in) session. Called by auth_login_session(). */
function sessionTrackStart(PDO $pdo, int $userId, string $authFp): void
{
    try {
        $pdo->prepare(
            'INSERT INTO user_sessions (user_id, session_hash, auth_fp, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), auth_fp = VALUES(auth_fp), last_seen_at = NOW()'
        )->execute([
            $userId,
            sessionHash(),
            $authFp,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ]);
        $_SESSION['tracked'] = true;
        $_SESSION['seen_at'] = time();
        // Housekeeping: sessions idle this long are dead anyway (includes/auth.php idle timeout).
        $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ? AND last_seen_at < NOW() - INTERVAL 1 DAY')->execute([$userId]);
    } catch (PDOException $e) {
        // migration 005 not run
    }
}

/**
 * On every signed-in request: false if this session was signed out from another device.
 * Sessions from before tracking existed are registered instead of being thrown out.
 */
function sessionTrackCheck(PDO $pdo, int $userId, string $authFp): bool
{
    try {
        $stmt = $pdo->prepare('SELECT id FROM user_sessions WHERE session_hash = ? AND user_id = ? LIMIT 1');
        $stmt->execute([sessionHash(), $userId]);
        $id = $stmt->fetchColumn();
    } catch (PDOException $e) {
        return true; // no table: tracking off
    }
    if ($id === false) {
        if (!empty($_SESSION['tracked'])) {
            return false; // this browser was signed out remotely
        }
        sessionTrackStart($pdo, $userId, $authFp);
        return true;
    }
    if (time() - (int)($_SESSION['seen_at'] ?? 0) >= SESSION_SEEN_EVERY) {
        $pdo->prepare('UPDATE user_sessions SET last_seen_at = NOW() WHERE id = ?')->execute([(int)$id]);
        $_SESSION['seen_at'] = time();
    }
    return true;
}

/** Forgets the current session's row (logout). */
function sessionTrackEnd(PDO $pdo): void
{
    try {
        $pdo->prepare('DELETE FROM user_sessions WHERE session_hash = ?')->execute([sessionHash()]);
    } catch (PDOException $e) {
    }
}

/**
 * The user's live sessions, newest activity first: signed in with the current password and
 * active within the idle timeout. Row: id, ip_address, user_agent, created_at, last_seen_at, current.
 */
function sessionList(PDO $pdo, int $userId, string $authFp): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT id, session_hash, ip_address, user_agent, created_at, last_seen_at FROM user_sessions
             WHERE user_id = ? AND auth_fp = ? AND last_seen_at >= NOW() - INTERVAL ' . (int)SESSION_IDLE_MINUTES . ' MINUTE
             ORDER BY last_seen_at DESC'
        );
        $stmt->execute([$userId, $authFp]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    $mine = sessionHash();
    foreach ($rows as &$r) {
        $r['current'] = hash_equals($r['session_hash'], $mine);
        unset($r['session_hash']);
    }
    unset($r);
    // This browser first.
    usort($rows, fn($a, $b) => $b['current'] <=> $a['current']);
    return $rows;
}

/** Signs out one other session (by row id) or, with $id null, every session except this one. Returns how many. */
function sessionRevoke(PDO $pdo, int $userId, ?int $id): int
{
    try {
        if ($id === null) {
            $stmt = $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ? AND session_hash != ?');
            $stmt->execute([$userId, sessionHash()]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM user_sessions WHERE id = ? AND user_id = ? AND session_hash != ?');
            $stmt->execute([$id, $userId, sessionHash()]);
        }
        return $stmt->rowCount();
    } catch (PDOException $e) {
        return 0;
    }
}

/** "Chrome on Windows", "Safari on iPhone", ... from a User-Agent string. Display only. */
function sessionDeviceLabel(?string $ua): string
{
    if ($ua === null || $ua === '') {
        return 'Unknown device';
    }
    $browser = match (true) {
        str_contains($ua, 'Edg/')                                => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'Firefox/')                            => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
        str_contains($ua, 'Safari/')                             => 'Safari',
        str_contains($ua, 'curl/')                               => 'curl',
        default                                                  => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'iPhone')    => 'iPhone',
        str_contains($ua, 'iPad')      => 'iPad',
        str_contains($ua, 'Android')   => 'Android',
        str_contains($ua, 'Windows')   => 'Windows',
        str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
        str_contains($ua, 'Linux')     => 'Linux',
        default                        => '',
    };
    return $os !== '' ? "$browser on $os" : $browser;
}
