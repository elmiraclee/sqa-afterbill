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
 */
function generateMissedRecurringBills(PDO $pdo, int $userId): void
{
    $maxIterations = 24; // safety cap (~2 years of monthly cycles) against a runaway loop

    for ($i = 0; $i < $maxIterations; $i++) {
        $stmt = $pdo->prepare(
            'SELECT b.bill_id, b.bill_name, b.amount, b.due_date, b.category_id
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
             VALUES (?, ?, ?, DATE_ADD(?, INTERVAL 1 MONTH), ?, ?, 1, \'unpaid\')'
        );

        foreach ($tips as $tip) {
            try {
                $insert->execute([
                    $userId,
                    $tip['bill_name'],
                    $tip['amount'],
                    $tip['due_date'],
                    $tip['category_id'],
                    $tip['bill_id'],
                ]);
            } catch (PDOException $e) {
                // uniq_bills_parent_bill_id guards against a duplicate child if this ever runs
                // twice concurrently for the same tip — safe to skip and let the loop continue.
                continue;
            }
        }
    }
}
