<?php
/**
 * AfterBill - Email reminders
 *
 * Once a day cron/send_reminders.php calls sendDueReminders(): every verified user with reminders
 * on gets ONE email listing their unpaid bills that are overdue or due within their chosen number
 * of days, plus any budget that is near or over its limit this month. Users with no bills due get
 * nothing (budget lines only ride along with a bill reminder, so nobody is emailed daily about a
 * budget alone). users.last_reminder_on guarantees at most one email
 * per user per day, even if the script runs several times or twice at once.
 * Columns: database/migrations/004_categories_payments_reminders.sql
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/recurring_bills.php';
require_once __DIR__ . '/budgets.php';

const REMINDER_DAY_CHOICES = [1, 3, 7, 14];
const REMINDER_DEFAULT_DAYS = 3;
const REMINDER_MAX_ITEMS = 25; // longer lists end with "...and N more"

/** ['enabled' => bool, 'days' => int], or null if this database doesn't have migration 004. */
function reminderSettings(PDO $pdo, int $userId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT reminder_emails, reminder_days FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    $days = (int)$row['reminder_days'];
    return [
        'enabled' => (bool)$row['reminder_emails'],
        'days'    => in_array($days, REMINDER_DAY_CHOICES, true) ? $days : REMINDER_DEFAULT_DAYS,
    ];
}

/**
 * Unpaid bills overdue or due within $days, oldest first, each with the balance still owed.
 * Row: bill_name, amount, due_date, days_left (negative = overdue), paid_total, balance.
 */
function billsForReminder(PDO $pdo, int $userId, int $days): array
{
    $stmt = $pdo->prepare(
        "SELECT b.bill_name, b.amount, b.due_date, DATEDIFF(b.due_date, CURDATE()) AS days_left,
                (SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p WHERE p.bill_id = b.bill_id) AS paid_total
         FROM bills b
         WHERE b.user_id = ? AND b.status = 'unpaid'
           AND b.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
         ORDER BY b.due_date ASC, b.bill_name ASC"
    );
    $stmt->execute([$userId, $days]);
    $bills = $stmt->fetchAll();
    foreach ($bills as &$b) {
        $b['days_left'] = (int)$b['days_left'];
        $b['balance'] = max(0, round((float)$b['amount'] - (float)$b['paid_total'], 2));
    }
    unset($b);
    return $bills;
}

/** "due today", "due in 3 days", "2 days overdue". */
function reminderWhen(int $daysLeft): string
{
    if ($daysLeft === 0) {
        return 'due today';
    }
    if ($daysLeft === 1) {
        return 'due tomorrow';
    }
    if ($daysLeft > 1) {
        return "due in $daysLeft days";
    }
    $late = -$daysLeft;
    return $late === 1 ? '1 day overdue' : "$late days overdue";
}

/** This month's budgets that are near (>= 80%) or over their limit, most used first. Never throws. */
function budgetAlertsForReminder(PDO $pdo, int $userId): array
{
    try {
        $usage = budgetUsage($pdo, $userId, new DateTime(date('Y-m-01')));
    } catch (PDOException $e) {
        return []; // budgets table missing
    }
    $alerts = array_values(array_filter($usage, fn($u) => in_array($u['status'], ['near', 'over'], true)));
    usort($alerts, fn($a, $b) => $b['pct'] <=> $a['pct']);
    return $alerts;
}

/**
 * The reminder email for $bills (from billsForReminder) and $budgetAlerts (from
 * budgetAlertsForReminder). Returns [subject, plain-text body].
 * Plain text only: bill and category names are user input and are never interpreted as HTML.
 */
function buildReminderEmail(string $name, array $bills, int $days, array $budgetAlerts = []): array
{
    $overdue = array_values(array_filter($bills, fn($b) => $b['days_left'] < 0));
    $upcoming = array_values(array_filter($bills, fn($b) => $b['days_left'] >= 0));

    $parts = [];
    if ($overdue) {
        $parts[] = count($overdue) . ' overdue';
    }
    if ($upcoming) {
        $parts[] = count($upcoming) . ' due soon';
    }
    $overBudget = count(array_filter($budgetAlerts, fn($u) => $u['status'] === 'over'));
    if ($overBudget) {
        $parts[] = $overBudget . ' over budget';
    }
    $subject = 'AfterBill reminder: ' . implode(', ', $parts);

    $line = function (array $b): string {
        // Bill names are single-line in practice; strip line breaks so one can't fake extra lines.
        $billName = preg_replace('/[\r\n]+/', ' ', $b['bill_name']);
        $money = '₱' . number_format($b['balance'], 2);
        if ((float)$b['paid_total'] > 0) {
            $money .= ' left of ₱' . number_format((float)$b['amount'], 2);
        }
        $date = (new DateTime($b['due_date']))->format('M j, Y');
        return "  - $billName: $money, " . reminderWhen($b['days_left']) . " ($date)";
    };

    $body = 'Hi ' . preg_replace('/[\r\n]+/', ' ', $name) . ",\n\n";
    $body .= "Here are your bills that need attention.\n";
    $shown = 0;
    foreach (['OVERDUE' => $overdue, 'DUE IN THE NEXT ' . $days . ' DAY' . ($days === 1 ? '' : 'S') => $upcoming] as $title => $list) {
        if (!$list) {
            continue;
        }
        $body .= "\n$title\n";
        foreach ($list as $b) {
            if ($shown >= REMINDER_MAX_ITEMS) {
                break 2;
            }
            $body .= $line($b) . "\n";
            $shown++;
        }
    }
    if (count($bills) > $shown) {
        $body .= '  ...and ' . (count($bills) - $shown) . " more\n";
    }

    $total = array_sum(array_column($bills, 'balance'));
    $body .= "\nTotal still to pay: ₱" . number_format($total, 2) . "\n";

    if ($budgetAlerts) {
        $body .= "\nBUDGETS THIS MONTH (" . strtoupper(date('F Y')) . ")\n";
        foreach ($budgetAlerts as $u) {
            $cat = preg_replace('/[\r\n]+/', ' ', $u['category_name']);
            $line = sprintf('  - %s: ₱%s of ₱%s (%s%%)', $cat, number_format($u['spent'], 2),
                number_format($u['limit'], 2), number_format($u['pct'], 0));
            $line .= $u['status'] === 'over'
                ? ', over by ₱' . number_format($u['spent'] - $u['limit'], 2)
                : ', near the limit';
            $body .= $line . "\n";
        }
        $body .= 'See your budgets: ' . app_url('budgets.php') . "\n";
    }
    $body .= "\n";
    $body .= 'Open your bills: ' . app_url('bills.php') . "\n\n";
    $body .= "You get this email because bill reminders are turned on for your AfterBill account.\n";
    $body .= 'Change how far ahead you are reminded, or turn reminders off: ' . app_url('account.php#reminders') . "\n";

    return [$subject, $body];
}

/**
 * Sends today's reminder emails. $onlyUserId limits the run to one account (tests, manual
 * re-send). $dryRun builds the emails and reports them without sending or recording anything.
 * Returns ['checked' => n, 'sent' => n, 'nothing_due' => n, 'failed' => n, 'emails' => [...]]
 * where emails lists [to, subject] (and body on a dry run).
 */
function sendDueReminders(PDO $pdo, ?int $onlyUserId = null, bool $dryRun = false): array
{
    $stats = ['checked' => 0, 'sent' => 0, 'nothing_due' => 0, 'failed' => 0, 'emails' => []];

    $sql = 'SELECT id, full_name, email, reminder_days, last_reminder_on FROM users
            WHERE reminder_emails = 1 AND email_verified_at IS NOT NULL
              AND (last_reminder_on IS NULL OR last_reminder_on < CURDATE())';
    $params = [];
    if ($onlyUserId !== null) {
        $sql .= ' AND id = ?';
        $params[] = $onlyUserId;
    }
    $users = $pdo->prepare($sql . ' ORDER BY id');
    $users->execute($params);

    // Claims today's reminder for one user. Only one run can win, so nobody gets it twice.
    $claim = $pdo->prepare(
        'UPDATE users SET last_reminder_on = CURDATE()
         WHERE id = ? AND (last_reminder_on IS NULL OR last_reminder_on < CURDATE())'
    );
    $release = $pdo->prepare('UPDATE users SET last_reminder_on = ? WHERE id = ? AND last_reminder_on = CURDATE()');

    foreach ($users->fetchAll() as $user) {
        $stats['checked']++;
        $userId = (int)$user['id'];
        $days = in_array((int)$user['reminder_days'], REMINDER_DAY_CHOICES, true) ? (int)$user['reminder_days'] : REMINDER_DEFAULT_DAYS;

        // Same catch-up every page does, so this month's recurring bills are included even if
        // the user hasn't logged in since they became due.
        generateMissedRecurringBills($pdo, $userId);
        $bills = billsForReminder($pdo, $userId, $days);
        if (!$bills) {
            $stats['nothing_due']++;
            continue;
        }

        [$subject, $body] = buildReminderEmail($user['full_name'], $bills, $days, budgetAlertsForReminder($pdo, $userId));

        if ($dryRun) {
            $stats['emails'][] = ['to' => $user['email'], 'subject' => $subject, 'body' => $body];
            continue;
        }

        $claim->execute([$userId]);
        if ($claim->rowCount() === 0) {
            continue; // another run already sent today's email
        }

        if (send_mail($user['email'], $user['full_name'], $subject, $body)) {
            $stats['sent']++;
            $stats['emails'][] = ['to' => $user['email'], 'subject' => $subject];
        } else {
            // Give the day back so the next run can try again.
            $release->execute([$user['last_reminder_on'], $userId]);
            $stats['failed']++;
        }
    }

    return $stats;
}
