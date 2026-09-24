<?php
/**
 * AfterBill - Reports data-access + aggregation layer.
 */

require_once __DIR__ . '/functions.php';

function reports_fetch_bills(int $userId, array $f): array
{
    $pdo = getPDO();
    $where = ['user_id = ?'];
    $params = [$userId];

    if (!empty($f['date_from'])) {
        $where[] = 'due_date >= ?';
        $params[] = $f['date_from'];
    }
    if (!empty($f['date_to'])) {
        $where[] = 'due_date <= ?';
        $params[] = $f['date_to'];
    }
    if (empty($f['date_from']) && empty($f['date_to'])) {
        if (!empty($f['year'])) {
            $where[] = 'YEAR(due_date) = ?';
            $params[] = (int)$f['year'];
        }
        if (!empty($f['month'])) {
            $where[] = 'MONTH(due_date) = ?';
            $params[] = (int)$f['month'];
        }
    }
    if (!empty($f['category']) && in_array($f['category'], BILL_CATEGORIES, true)) {
        $where[] = 'category = ?';
        $params[] = $f['category'];
    }
    if (!empty($f['status'])) {
        if ($f['status'] === 'paid') {
            $where[] = "status = 'paid'";
        } elseif ($f['status'] === 'upcoming') {
            $where[] = "status = 'unpaid' AND due_date >= CURDATE()";
        } elseif ($f['status'] === 'overdue') {
            $where[] = "status = 'unpaid' AND due_date < CURDATE()";
        }
    }

    $sql = 'SELECT * FROM bills WHERE ' . implode(' AND ', $where) . ' ORDER BY due_date ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function reports_available_years(int $userId): array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT DISTINCT YEAR(due_date) AS y FROM bills WHERE user_id = ? ORDER BY y DESC');
    $stmt->execute([$userId]);
    $years = array_column($stmt->fetchAll(), 'y');
    if (empty($years)) {
        $years = [(int)date('Y')];
    }
    return $years;
}

function reports_summarize(array $bills): array
{
    $summary = [
        'total_bills'    => count($bills),
        'total_amount'   => 0.0,
        'total_paid'     => 0.0,
        'total_unpaid'   => 0.0,
        'paid_count'     => 0,
        'upcoming_count' => 0,
        'upcoming_amount'=> 0.0,
        'overdue_count'  => 0,
        'overdue_amount' => 0.0,
        'by_category'    => [],
        'by_month'       => [],
    ];

    foreach ($bills as $bill) {
        $amount = (float)$bill['amount'];
        $summary['total_amount'] += $amount;

        $display = bill_display_status($bill['status'], $bill['due_date']);
        if ($display === 'paid') {
            $summary['total_paid'] += $amount;
            $summary['paid_count']++;
        } elseif ($display === 'overdue') {
            $summary['total_unpaid'] += $amount;
            $summary['overdue_count']++;
            $summary['overdue_amount'] += $amount;
        } else {
            $summary['total_unpaid'] += $amount;
            $summary['upcoming_count']++;
            $summary['upcoming_amount'] += $amount;
        }

        $cat = $bill['category'];
        $summary['by_category'][$cat] = ($summary['by_category'][$cat] ?? 0) + $amount;

        $monthKey = substr($bill['due_date'], 0, 7);
        $summary['by_month'][$monthKey] = ($summary['by_month'][$monthKey] ?? 0) + $amount;
    }

    arsort($summary['by_category']);
    ksort($summary['by_month']);

    return $summary;
}
