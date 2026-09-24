<?php
/**
 * AfterBill - Bills data-access layer.
 * All queries are scoped to the given $userId so a user can never
 * read/modify another user's bills.
 */

require_once __DIR__ . '/functions.php';

function bills_fetch_all(int $userId, array $filters = []): array
{
    $pdo = getPDO();

    $where = ['user_id = ?'];
    $params = [$userId];

    if (!empty($filters['search'])) {
        $where[] = '(name LIKE ? OR notes LIKE ?)';
        $like = '%' . $filters['search'] . '%';
        $params[] = $like;
        $params[] = $like;
    }

    if (!empty($filters['category']) && in_array($filters['category'], BILL_CATEGORIES, true)) {
        $where[] = 'category = ?';
        $params[] = $filters['category'];
    }

    if (!empty($filters['status'])) {
        if ($filters['status'] === 'paid') {
            $where[] = "status = 'paid'";
        } elseif ($filters['status'] === 'upcoming') {
            $where[] = "status = 'unpaid' AND due_date >= CURDATE()";
        } elseif ($filters['status'] === 'overdue') {
            $where[] = "status = 'unpaid' AND due_date < CURDATE()";
        }
    }

    if (!empty($filters['month'])) {
        $where[] = "DATE_FORMAT(due_date, '%Y-%m') = ?";
        $params[] = $filters['month'];
    }

    $sortMap = [
        'due_date_asc'  => 'due_date ASC',
        'due_date_desc' => 'due_date DESC',
        'amount_asc'    => 'amount ASC',
        'amount_desc'   => 'amount DESC',
        'name_asc'      => 'name ASC',
        'name_desc'     => 'name DESC',
    ];
    $orderBy = $sortMap[$filters['sort'] ?? ''] ?? 'due_date ASC';

    $sql = 'SELECT * FROM bills WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function bills_find(int $userId, int $billId): ?array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM bills WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$billId, $userId]);
    $bill = $stmt->fetch();
    return $bill ?: null;
}

function bills_create(int $userId, array $data): int
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'INSERT INTO bills (user_id, name, category, amount, due_date, frequency, status, notes, reminder_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $data['name'],
        $data['category'],
        $data['amount'],
        $data['due_date'],
        $data['frequency'],
        $data['status'] ?? 'unpaid',
        $data['notes'] !== '' ? $data['notes'] : null,
        $data['reminder_at'] !== '' ? $data['reminder_at'] : null,
    ]);

    return (int)$pdo->lastInsertId();
}

function bills_update(int $userId, int $billId, array $data): bool
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'UPDATE bills SET name = ?, category = ?, amount = ?, due_date = ?, frequency = ?, notes = ?, reminder_at = ?
         WHERE id = ? AND user_id = ?'
    );
    return $stmt->execute([
        $data['name'],
        $data['category'],
        $data['amount'],
        $data['due_date'],
        $data['frequency'],
        $data['notes'] !== '' ? $data['notes'] : null,
        $data['reminder_at'] !== '' ? $data['reminder_at'] : null,
        $billId,
        $userId,
    ]);
}

function bills_delete(int $userId, int $billId): bool
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM bills WHERE id = ? AND user_id = ?');
    return $stmt->execute([$billId, $userId]);
}

function bills_set_status(int $userId, int $billId, string $status): bool
{
    $pdo = getPDO();
    $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;
    $stmt = $pdo->prepare('UPDATE bills SET status = ?, paid_at = ? WHERE id = ? AND user_id = ?');
    return $stmt->execute([$status, $paidAt, $billId, $userId]);
}

/**
 * Dashboard summary numbers, all computed live from the bills table.
 */
function bills_dashboard_stats(int $userId): array
{
    $pdo = getPDO();

    $stmt = $pdo->prepare('SELECT status, amount, due_date, paid_at FROM bills WHERE user_id = ?');
    $stmt->execute([$userId]);
    $bills = $stmt->fetchAll();

    $stats = [
        'total_bills'      => count($bills),
        'paid_this_month'  => 0.0,
        'upcoming_count'   => 0,
        'upcoming_amount'  => 0.0,
        'overdue_count'    => 0,
        'overdue_amount'   => 0.0,
        'total_amount'     => 0.0,
        'total_paid'       => 0.0,
        'total_unpaid'     => 0.0,
    ];

    $currentMonth = date('Y-m');

    foreach ($bills as $bill) {
        $amount = (float)$bill['amount'];
        $stats['total_amount'] += $amount;

        if ($bill['status'] === 'paid') {
            $stats['total_paid'] += $amount;
            if ($bill['paid_at'] && substr($bill['paid_at'], 0, 7) === $currentMonth) {
                $stats['paid_this_month'] += $amount;
            }
        } else {
            $stats['total_unpaid'] += $amount;
            if ($bill['due_date'] < date('Y-m-d')) {
                $stats['overdue_count']++;
                $stats['overdue_amount'] += $amount;
            } else {
                $stats['upcoming_count']++;
                $stats['upcoming_amount'] += $amount;
            }
        }
    }

    return $stats;
}

function bills_upcoming(int $userId, int $limit = 5): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        "SELECT * FROM bills WHERE user_id = ? AND status = 'unpaid'
         ORDER BY due_date ASC LIMIT " . (int)$limit
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function bills_for_month(int $userId, int $year, int $month): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        "SELECT * FROM bills WHERE user_id = ? AND YEAR(due_date) = ? AND MONTH(due_date) = ?
         ORDER BY due_date ASC"
    );
    $stmt->execute([$userId, $year, $month]);
    return $stmt->fetchAll();
}
