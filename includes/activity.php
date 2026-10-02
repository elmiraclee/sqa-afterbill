<?php
/**
 * AfterBill - Activity Log
 *
 * logActivity() records one user action (see ACTIVITY_TYPES) in activity_log. It never throws:
 * a failed log write must not break the action being logged, so errors are swallowed.
 * Table: database/migrations/001_budgets_activity_log.sql
 */

// action code => [label, badge color, filter group]
const ACTIVITY_TYPES = [
    'register'         => ['Account created',   'green', 'auth'],
    'login'            => ['Logged in',         'blue',  'auth'],
    'login_failed'     => ['Failed login',      'red',   'auth'],
    'logout'           => ['Logged out',        'gray',  'auth'],
    'bill_added'       => ['Bill added',        'blue',  'bills'],
    'bill_updated'     => ['Bill updated',      'amber', 'bills'],
    'bill_deleted'     => ['Bill deleted',      'red',   'bills'],
    'payment_recorded' => ['Payment recorded',  'green', 'payments'],
    'payment_undone'   => ['Payment undone',    'amber', 'payments'],
    'profile_updated'  => ['Profile updated',   'blue',  'account'],
    'password_changed' => ['Password changed',  'amber', 'account'],
    'budget_set'       => ['Budget set',        'blue',  'budgets'],
    'budget_removed'   => ['Budget removed',    'gray',  'budgets'],
];

const ACTIVITY_GROUPS = [
    'auth'     => 'Sign-in',
    'bills'    => 'Bills',
    'payments' => 'Payments',
    'account'  => 'Account',
    'budgets'  => 'Budgets',
];

function logActivity(PDO $pdo, int $userId, string $action, string $details = ''): void
{
    if (!isset(ACTIVITY_TYPES[$action])) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO activity_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)'
        );
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $stmt->execute([
            $userId,
            $action,
            $details !== '' ? mb_substr($details, 0, 255) : null,
            $ip !== '' ? $ip : null,
        ]);
    } catch (PDOException $e) {
        // Logging is best-effort (e.g. migration not run yet) — never block the real action.
    }
}

const LOGIN_MAX_FAILURES = 5;
const LOGIN_LOCK_MINUTES = 15;

/**
 * Brute-force guard: minutes left on an account's login lock, or 0 if it may log in.
 * Locked after LOGIN_MAX_FAILURES failed logins within LOGIN_LOCK_MINUTES with no successful
 * login in between; it unlocks LOGIN_LOCK_MINUTES after the most recent failure. Never throws
 * (no activity_log table = no lock).
 */
function loginLockMinutesLeft(PDO $pdo, int $userId): int
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS failures, MAX(created_at) AS last_failure
             FROM activity_log
             WHERE user_id = ? AND action = 'login_failed'
               AND created_at >= NOW() - INTERVAL " . LOGIN_LOCK_MINUTES . " MINUTE
               AND created_at > COALESCE(
                   (SELECT MAX(created_at) FROM activity_log WHERE user_id = ? AND action = 'login'),
                   '1000-01-01')"
        );
        $stmt->execute([$userId, $userId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return 0;
    }
    if ((int)$row['failures'] < LOGIN_MAX_FAILURES) {
        return 0;
    }
    $unlockAt = (new DateTime($row['last_failure']))->modify('+' . LOGIN_LOCK_MINUTES . ' minutes');
    return max(1, (int)ceil(($unlockAt->getTimestamp() - time()) / 60));
}

/** "₱1,500.00" — the money format every page uses, for log details. */
function activityMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}
