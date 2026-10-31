<?php
/**
 * AfterBill - Budgets
 *
 * A budget is a monthly spending limit for one category. "Spent" is the sum of the user's bills
 * DUE in that month in that category, paid + unpaid — the same definition reports.php uses for
 * monthly expenses. Table: database/migrations/001_budgets_activity_log.sql
 */

const BUDGET_NEAR_LIMIT_PCT = 80;

/**
 * Per-category usage for the month starting at $monthStart (a DateTime on the 1st).
 * Returns every category (budgeted or not), keyed by category_id:
 *   ['category_name', 'limit' => float|null, 'spent' => float, 'count' => int, 'pct' => float|null, 'status']
 * status: 'none' (no budget), 'ok', 'near' (>= 80%), 'over' (> 100%).
 */
function budgetUsage(PDO $pdo, int $userId, DateTime $monthStart): array
{
    $monthEnd = (clone $monthStart)->modify('last day of this month');

    $stmt = $pdo->prepare(
        'SELECT c.category_id, c.category_name, bu.monthly_limit,
                COALESCE(s.spent, 0) AS spent, COALESCE(s.cnt, 0) AS cnt
         FROM categories c
         LEFT JOIN budgets bu ON bu.category_id = c.category_id AND bu.user_id = ?
         LEFT JOIN (
             SELECT category_id, SUM(amount) AS spent, COUNT(*) AS cnt
             FROM bills
             WHERE user_id = ? AND due_date BETWEEN ? AND ?
             GROUP BY category_id
         ) s ON s.category_id = c.category_id
         ORDER BY c.category_name'
    );
    $stmt->execute([$userId, $userId, $monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')]);

    $usage = [];
    foreach ($stmt->fetchAll() as $row) {
        $limit = $row['monthly_limit'] !== null ? (float)$row['monthly_limit'] : null;
        $spent = (float)$row['spent'];
        $pct = $limit ? $spent / $limit * 100 : null;
        $status = 'none';
        if ($limit !== null) {
            $status = $pct > 100 ? 'over' : ($pct >= BUDGET_NEAR_LIMIT_PCT ? 'near' : 'ok');
        }
        $usage[(int)$row['category_id']] = [
            'category_name' => $row['category_name'],
            'limit'         => $limit,
            'spent'         => $spent,
            'count'         => (int)$row['cnt'],
            'pct'           => $pct,
            'status'        => $status,
        ];
    }
    return $usage;
}

/**
 * Warning text if the category of a bill due on $dueDate is over its budget for that month,
 * else null. Used right after a bill is added or edited. Never throws.
 */
function budgetWarningFor(PDO $pdo, int $userId, int $categoryId, string $dueDate): ?string
{
    try {
        $monthStart = new DateTime(substr($dueDate, 0, 7) . '-01');
        $row = budgetUsage($pdo, $userId, $monthStart)[$categoryId] ?? null;
    } catch (Exception $e) {
        return null;
    }
    if (!$row || $row['status'] !== 'over') {
        return null;
    }
    return sprintf(
        '%s is now over budget for %s: ₱%s due of a ₱%s limit.',
        $row['category_name'],
        $monthStart->format('F Y'),
        number_format($row['spent'], 2),
        number_format($row['limit'], 2)
    );
}

/** Number of categories over budget in the current month (sidebar badge). Never throws. */
function budgetsOverCount(PDO $pdo, int $userId): int
{
    try {
        $usage = budgetUsage($pdo, $userId, new DateTime(date('Y-m-01')));
    } catch (PDOException $e) {
        return 0;
    }
    return count(array_filter($usage, fn($u) => $u['status'] === 'over'));
}
