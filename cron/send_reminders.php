<?php
/**
 * AfterBill - Daily email reminders (run from the command line, once a day)
 *
 *   php cron/send_reminders.php             send today's reminders
 *   php cron/send_reminders.php --dry-run   show who would get what, send nothing
 *   php cron/send_reminders.php --user=12   only that account (e.g. to test)
 *
 * Safe to run more than once a day: each user gets at most one reminder per day.
 *
 * Scheduling:
 *   macOS / Linux (crontab -e), every day at 7:00:
 *     0 7 * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/sqa-afterbill/cron/send_reminders.php >> /tmp/afterbill-reminders.log 2>&1
 *   Windows (Task Scheduler > Create Basic Task > Daily > Start a program):
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: C:\xampp\htdocs\sqa-afterbill\cron\send_reminders.php
 *   Shared hosting: add the same command as a daily cron job in the control panel.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/reminders.php';

$options = getopt('', ['dry-run', 'user:']);
$dryRun = isset($options['dry-run']);
$onlyUser = null;
if (isset($options['user'])) {
    if (!ctype_digit((string)$options['user'])) {
        fwrite(STDERR, "--user must be a user id (a number)\n");
        exit(2);
    }
    $onlyUser = (int)$options['user'];
}

try {
    $stats = sendDueReminders(getPDO(), $onlyUser, $dryRun);
} catch (PDOException $e) {
    fwrite(STDERR, date('c') . ' Reminders failed: ' . $e->getMessage() . "\n"
        . "(Has database/migrations/004_categories_payments_reminders.sql been run?)\n");
    exit(1);
}

foreach ($stats['emails'] as $mail) {
    echo ($dryRun ? '[dry run] ' : '') . "{$mail['to']}: {$mail['subject']}\n";
    if ($dryRun) {
        echo preg_replace('/^/m', '    ', $mail['body']) . "\n";
    }
}
printf(
    "%s %s: %d checked, %d sent, %d with nothing due, %d failed\n",
    date('c'),
    $dryRun ? 'Dry run' : 'Reminders',
    $stats['checked'],
    $dryRun ? count($stats['emails']) : $stats['sent'],
    $stats['nothing_due'],
    $stats['failed']
);
exit($stats['failed'] > 0 ? 1 : 0);
