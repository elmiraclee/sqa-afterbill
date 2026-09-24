<?php
/**
 * AfterBill - Reminders data-access layer.
 */

require_once __DIR__ . '/functions.php';

function reminders_fetch_all(int $userId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT r.*, b.name AS bill_name, b.due_date AS bill_due_date, b.status AS bill_status
         FROM reminders r
         LEFT JOIN bills b ON b.id = r.bill_id AND b.user_id = r.user_id
         WHERE r.user_id = ?
         ORDER BY r.is_completed ASC, r.remind_at ASC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function reminders_find(int $userId, int $reminderId): ?array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM reminders WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$reminderId, $userId]);
    $reminder = $stmt->fetch();
    return $reminder ?: null;
}

function reminders_create(int $userId, array $data): int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'INSERT INTO reminders (user_id, bill_id, title, remind_at) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $data['bill_id'] ?: null,
        $data['title'],
        $data['remind_at'],
    ]);
    return (int)$pdo->lastInsertId();
}

function reminders_update(int $userId, int $reminderId, array $data): bool
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'UPDATE reminders SET title = ?, remind_at = ?, bill_id = ? WHERE id = ? AND user_id = ?'
    );
    return $stmt->execute([
        $data['title'],
        $data['remind_at'],
        $data['bill_id'] ?: null,
        $reminderId,
        $userId,
    ]);
}

function reminders_delete(int $userId, int $reminderId): bool
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM reminders WHERE id = ? AND user_id = ?');
    return $stmt->execute([$reminderId, $userId]);
}

function reminders_set_completed(int $userId, int $reminderId, bool $completed): bool
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('UPDATE reminders SET is_completed = ? WHERE id = ? AND user_id = ?');
    return $stmt->execute([$completed ? 1 : 0, $reminderId, $userId]);
}

/**
 * Computed (not stored) due-date alerts, e.g. "Electricity bill is due in 3 days."
 * Generated fresh from the bills table every time — always reflects live data.
 */
function bills_due_alerts(int $userId, int $daysAhead = 7): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        "SELECT * FROM bills WHERE user_id = ? AND status = 'unpaid'
         AND due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
         ORDER BY due_date ASC"
    );
    $stmt->execute([$userId, $daysAhead]);
    $bills = $stmt->fetchAll();

    $today = new DateTime(date('Y-m-d'));
    $alerts = [];

    foreach ($bills as $bill) {
        $due = new DateTime($bill['due_date']);
        $diff = (int)$today->diff($due)->format('%r%a');

        if ($diff < 0) {
            $message = $bill['name'] . ' is overdue.';
            $level = 'overdue';
        } elseif ($diff === 0) {
            $message = $bill['name'] . ' is due today.';
            $level = 'today';
        } elseif ($diff === 1) {
            $message = $bill['name'] . ' is due tomorrow.';
            $level = 'soon';
        } else {
            $message = $bill['name'] . ' is due in ' . $diff . ' days.';
            $level = 'soon';
        }

        $alerts[] = [
            'bill_id' => $bill['id'],
            'message' => $message,
            'level'   => $level,
            'due_date' => $bill['due_date'],
        ];
    }

    return $alerts;
}
