<?php
/**
 * AfterBill - Recurring Bills Generation
 */

require_once __DIR__ . '/recurrence.php';

/** How far back missed cycles are filled in: this month and the 11 before it, or 8 weeks. */
const RECURRING_BACKFILL_MONTHS = 12;
const RECURRING_BACKFILL_WEEKS = 8;

/** True once migration 005 has added bills.repeat_interval / repeat_until. Checked once per request. */
function recurrenceColumnsExist(PDO $pdo): bool
{
    static $exists = null;
    if ($exists === null) {
        try {
            $pdo->query('SELECT repeat_interval, repeat_until FROM bills LIMIT 0');
            $exists = true;
        } catch (PDOException $e) {
            $exists = false;
        }
    }
    return $exists;
}

/**
 * Generates any missing next-cycle bills for the given user's recurring bills.
 *
 * A recurring bill's next cycle is generated once its due_date has passed, regardless of
 * whether it was ever marked paid — "recurring" means the schedule keeps advancing on its own.
 * Each bill can have at most one auto-generated child (enforced by the parent_bill_id UNIQUE
 * constraint), so a bill is only eligible if no child already points back to it. The loop
 * repeats so a user who hasn't visited in months gets fully caught up in one page load.
 * Each new cycle copies its parent's repeat interval and end date; no cycle is created past the
 * end date.
 *
 * Termination: back-fill is limited (see nextRecurringDueDate) to 12 months for month-based
 * schedules and 8 weeks for weekly ones, so a series needs at most 12 + 1 iterations — under the
 * 24 cap — and is fully caught up after ONE load; later loads generate nothing until the next
 * due date passes. Deleting a cycle turns off is_recurring on its parent (delete_bill.php), so a
 * deleted cycle is never re-created.
 */
function generateMissedRecurringBills(PDO $pdo, int $userId): void
{
    $withRepeat = recurrenceColumnsExist($pdo);
    $maxIterations = 24; // safety cap against a runaway loop; normal catch-up needs at most 13

    for ($i = 0; $i < $maxIterations; $i++) {
        $stmt = $pdo->prepare(
            'SELECT b.bill_id, b.bill_name, b.amount, b.due_date, b.category_id, b.parent_bill_id'
            . ($withRepeat ? ', b.repeat_interval, b.repeat_until' : '') . '
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

        $insert = $pdo->prepare($withRepeat
            ? 'INSERT INTO bills (user_id, bill_name, amount, due_date, category_id, parent_bill_id, is_recurring, status, repeat_interval, repeat_until)
               VALUES (?, ?, ?, ?, ?, ?, 1, \'unpaid\', ?, ?)'
            : 'INSERT INTO bills (user_id, bill_name, amount, due_date, category_id, parent_bill_id, is_recurring, status)
               VALUES (?, ?, ?, ?, ?, ?, 1, \'unpaid\')'
        );

        $inserted = 0;
        foreach ($tips as $tip) {
            $tip['repeat_interval'] ??= 'monthly';
            $tip['repeat_until'] ??= null;
            $next = nextRecurringDueDate($pdo, $tip);
            if ($tip['repeat_until'] !== null && $next > $tip['repeat_until']) {
                continue; // the series has ended
            }
            $values = [$userId, $tip['bill_name'], $tip['amount'], $next, $tip['category_id'], $tip['bill_id']];
            if ($withRepeat) {
                array_push($values, $tip['repeat_interval'], $tip['repeat_until']);
            }
            try {
                $insert->execute($values);
                $inserted++;
            } catch (PDOException $e) {
                // uniq_bills_parent_bill_id guards against a duplicate child if this ever runs
                // twice concurrently for the same tip — safe to skip and let the loop continue.
                continue;
            }
        }

        // Nothing new (all ended, or every insert failed): the same tips would just be
        // retried, so stop instead of spinning.
        if ($inserted === 0) {
            break;
        }
    }
}

/**
 * Due date of the cycle after $tip.
 *
 * Weekly: 7 days later. Month-based (monthly / every 3 months / yearly): the same day of the
 * month, 1 / 3 / 12 months later. Plain DATE_ADD(.., INTERVAL 1 MONTH) clamps Jan 31 to Feb 28
 * and every later cycle then stays on the 28th. Instead the intended day is recovered from the
 * chain: a bill that is NOT on the last day of its month is on its intended day; one that IS on
 * the last day may have been clamped, so the parent's intended day is taken if it is later. The
 * result is clamped to the length of the target month only.
 *
 * Old cycles are not back-filled: a bill last dated long ago skips ahead (keeping its rhythm) to
 * the first cycle within the last 12 months (this month and the 11 before it) or, for weekly
 * bills, the last 8 weeks — instead of creating one bill per missed cycle.
 */
function nextRecurringDueDate(PDO $pdo, array $tip): string
{
    $interval = $tip['repeat_interval'] ?? 'monthly';

    if ($interval === 'weekly') {
        $next = (new DateTime($tip['due_date']))->modify('+7 days');
        $floor = (new DateTime('today'))->modify('-' . (RECURRING_BACKFILL_WEEKS * 7) . ' days');
        if ($next < $floor) {
            $weeks = (int)ceil($floor->diff($next)->days / 7);
            $next->modify('+' . (7 * $weeks) . ' days');
        }
        return $next->format('Y-m-d');
    }

    $step = REPEAT_MONTH_STEPS[$interval] ?? 1;

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

    $next = (new DateTime(substr($tip['due_date'], 0, 7) . '-01'))->modify("+$step months");
    // 'today' = midnight, so comparing with $next (also midnight) is by date only.
    $backfillFloor = (new DateTime('today'))->modify('first day of this month')->modify('-' . (RECURRING_BACKFILL_MONTHS - 1) . ' months');
    if ($next < $backfillFloor) {
        // Whole steps, so a quarterly or yearly bill stays in its own months.
        $monthsBehind = ((int)$backfillFloor->format('Y') - (int)$next->format('Y')) * 12
            + (int)$backfillFloor->format('n') - (int)$next->format('n');
        $next->modify('+' . ((int)ceil($monthsBehind / $step) * $step) . ' months');
    }
    $day = min($anchorDay, (int)$next->format('t'));
    return $next->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
}
