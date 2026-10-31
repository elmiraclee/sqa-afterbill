<?php
/**
 * AfterBill - Recurring Bills Generation
 */

/**
 * Generates any missing next-cycle bills for the given user's recurring bills.
 *
 * A recurring bill's next cycle is generated once its due_date has passed, regardless of
 * whether it was ever marked paid — "recurring" means the schedule keeps advancing on its own.
 * Each bill can have at most one auto-generated child (enforced by the parent_bill_id UNIQUE
 * constraint), so a bill is only eligible if no child already points back to it. The loop
 * repeats so a user who hasn't visited in months gets fully caught up in one page load.
 *
 * Termination: back-fill is limited to the last 12 months (see nextRecurringDueDate), so any
 * series needs at most 13 iterations — under the 24 cap — and is fully caught up after ONE
 * load; later loads generate nothing until the next due date passes. Deleting a cycle turns
 * off is_recurring on its parent (delete_bill.php), so a deleted cycle is never re-created.
 */
function generateMissedRecurringBills(PDO $pdo, int $userId): void
{
    $maxIterations = 24; // safety cap against a runaway loop; normal catch-up needs at most 13

    for ($i = 0; $i < $maxIterations; $i++) {
        $stmt = $pdo->prepare(
            'SELECT b.bill_id, b.bill_name, b.amount, b.due_date, b.category_id, b.parent_bill_id
             FROM bills b
             WHERE b.user_id = ?
               AND b.is_recurring = 1
               AND b.due_date < CURDATE()
               AND NOT EXISTS (SELECT 1 FROM bills c WHERE c.parent_bill_id = b.bill_id)'
        );
        $stmt->execute([$userId]);
        $tips = $stmt->fetchAll();

        if (empty($tips)) {
            break;
        }

        $insert = $pdo->prepare(
            'INSERT INTO bills (user_id, bill_name, amount, due_date, category_id, parent_bill_id, is_recurring, status)
             VALUES (?, ?, ?, ?, ?, ?, 1, \'unpaid\')'
        );

        $inserted = 0;
        foreach ($tips as $tip) {
            try {
                $insert->execute([
                    $userId,
                    $tip['bill_name'],
                    $tip['amount'],
                    nextRecurringDueDate($pdo, $tip),
                    $tip['category_id'],
                    $tip['bill_id'],
                ]);
                $inserted++;
            } catch (PDOException $e) {
                // uniq_bills_parent_bill_id guards against a duplicate child if this ever runs
                // twice concurrently for the same tip — safe to skip and let the loop continue.
                continue;
            }
        }

        // Every insert failed: the same tips would just be retried, so stop instead of spinning.
        if ($inserted === 0) {
            break;
        }
    }
}

/**
 * Due date of the cycle after $tip, keeping the series' intended day of the month.
 *
 * Plain DATE_ADD(.., INTERVAL 1 MONTH) clamps Jan 31 to Feb 28 and every later cycle then stays
 * on the 28th. Instead the intended day is recovered from the chain: a bill that is NOT on the
 * last day of its month is on its intended day; one that IS on the last day may have been
 * clamped, so the parent's intended day is taken if it is later. The result is clamped to the
 * length of the next month only.
 *
 * Cycles older than 12 months are not back-filled: a bill last dated years ago jumps straight
 * to the month 12 months back, instead of creating one bill per missed month.
 */
function nextRecurringDueDate(PDO $pdo, array $tip): string
{
    $parentStmt = $pdo->prepare('SELECT due_date, parent_bill_id FROM bills WHERE bill_id = ?');

    $anchorDay = (int)substr($tip['due_date'], 8, 2);
    $current = $tip;
    for ($depth = 0; $depth < 240 && $current['parent_bill_id'] !== null; $depth++) {
        $date = new DateTime($current['due_date']);
        if ((int)$date->format('j') !== (int)$date->format('t')) {
            break; // not month-end, so not clamped: this bill's day is the intended one
        }
        $parentStmt->execute([$current['parent_bill_id']]);
        $parent = $parentStmt->fetch();
        if (!$parent) {
            break;
        }
        $anchorDay = max($anchorDay, (int)substr($parent['due_date'], 8, 2));
        $current = $parent;
    }

    $next = (new DateTime($tip['due_date']))->modify('first day of next month');
    $backfillFloor = (new DateTime('today'))->modify('first day of -12 months');
    if ($next < $backfillFloor) {
        $next = $backfillFloor;
    }
    $day = min($anchorDay, (int)$next->format('t'));
    return $next->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
}
