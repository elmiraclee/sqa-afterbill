<?php
/**
 * AfterBill - "Download my data": everything stored about one user, as a ZIP file
 *
 *   README.txt         what each file is
 *   profile.json       name, email, dates, settings (never the password hash or 2FA secret)
 *   bills.csv, payments.csv, budgets.csv, categories.csv, activity_log.csv
 *   receipts/          every uploaded receipt, named <payment id>-<bill>.<ext>
 *
 * CSV text is escaped against spreadsheet formulas like the other exports (includes/csv.php).
 */

require_once __DIR__ . '/csv.php';
require_once __DIR__ . '/payments.php';

/** Rows as CSV text (UTF-8 with BOM, so Excel shows ₱ correctly). */
function exportCsv(array $header, array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $header);
    foreach ($rows as $row) {
        fputcsv($fh, array_map(fn($v) => is_string($v) ? csvSafe($v) : $v, array_values($row)));
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

/** Safe-ish ASCII file name part. */
function exportSlug(string $s): string
{
    $s = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');
    return $s !== '' ? substr($s, 0, 40) : 'bill';
}

/**
 * Builds the ZIP in a temporary file and returns its path (the caller sends and deletes it).
 * Throws RuntimeException if the ZIP can't be created.
 */
function buildDataExport(PDO $pdo, int $userId): string
{
    $q = function (string $sql) use ($pdo, $userId): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    };
    $optional = function (string $sql) use ($q): array {
        try {
            return $q($sql);
        } catch (PDOException $e) {
            return []; // a table from a migration that hasn't been run
        }
    };

    $user = $q('SELECT * FROM users WHERE id = ?')[0] ?? null;
    if (!$user) {
        throw new RuntimeException('User not found');
    }
    $profile = [
        'full_name'        => $user['full_name'],
        'email'            => $user['email'],
        'member_since'     => $user['created_at'],
        'email_verified'   => $user['email_verified_at'],
        'email_reminders'  => isset($user['reminder_emails']) ? (bool)$user['reminder_emails'] : null,
        'reminder_days'    => isset($user['reminder_days']) ? (int)$user['reminder_days'] : null,
        'two_factor_login' => !empty($user['totp_enabled_at']),
        'exported_at'      => date('c'),
    ];

    $bills = $q(
        'SELECT b.bill_id, b.bill_name, c.category_name, b.amount, b.due_date, b.status, b.is_recurring,
                b.parent_bill_id, b.created_at
         FROM bills b JOIN categories c ON c.category_id = b.category_id
         WHERE b.user_id = ? ORDER BY b.due_date, b.bill_id'
    );
    $repeat = $optional('SELECT bill_id, repeat_interval, repeat_until FROM bills WHERE user_id = ?');
    $repeatById = array_column($repeat, null, 'bill_id');
    foreach ($bills as &$b) {
        $b['repeats'] = $b['is_recurring'] ? ($repeatById[$b['bill_id']]['repeat_interval'] ?? 'monthly') : '';
        $b['repeat_until'] = $repeatById[$b['bill_id']]['repeat_until'] ?? '';
        unset($b['is_recurring']);
    }
    unset($b);

    try {
        $payments = $q(
            'SELECT p.payment_id, p.bill_id, b.bill_name, p.payment_date, p.amount_paid, p.payment_method, p.notes,
                    p.receipt_file, p.created_at
             FROM payments p JOIN bills b ON b.bill_id = p.bill_id
             WHERE b.user_id = ? ORDER BY p.payment_date, p.payment_id'
        );
    } catch (PDOException $e) {
        $payments = $q(
            'SELECT p.payment_id, p.bill_id, b.bill_name, p.payment_date, p.amount_paid, p.payment_method, p.notes,
                    NULL AS receipt_file, p.created_at
             FROM payments p JOIN bills b ON b.bill_id = p.bill_id
             WHERE b.user_id = ? ORDER BY p.payment_date, p.payment_id'
        );
    }
    $budgets = $optional(
        'SELECT c.category_name, bu.monthly_limit, bu.updated_at
         FROM budgets bu JOIN categories c ON c.category_id = bu.category_id WHERE bu.user_id = ? ORDER BY c.category_name'
    );
    $categories = $optional('SELECT category_id, category_name FROM categories WHERE user_id = ? ORDER BY category_name');
    $activity = $optional(
        'SELECT created_at, action, details, ip_address FROM activity_log WHERE user_id = ? ORDER BY created_at, log_id'
    );

    $path = tempnam(sys_get_temp_dir(), 'afterbill-export-');
    $zip = new ZipArchive();
    if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create the ZIP file');
    }

    // Receipts: copied under readable names; the CSV says which file belongs to which payment.
    $receiptCount = 0;
    foreach ($payments as &$p) {
        $file = $p['receipt_file'];
        $p['receipt_file'] = '';
        $src = receiptPath($file);
        if ($src !== null && is_file($src)) {
            $name = 'receipts/' . $p['payment_id'] . '-' . exportSlug($p['bill_name']) . '.' . pathinfo($src, PATHINFO_EXTENSION);
            $zip->addFile($src, $name);
            $p['receipt_file'] = $name;
            $receiptCount++;
        }
    }
    unset($p);

    $zip->addFromString('profile.json', json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->addFromString('bills.csv', exportCsv(
        ['Bill ID', 'Bill', 'Category', 'Amount', 'Due Date', 'Status', 'Previous Cycle ID', 'Created', 'Repeats', 'Repeats Until'], $bills));
    $zip->addFromString('payments.csv', exportCsv(
        ['Payment ID', 'Bill ID', 'Bill', 'Payment Date', 'Amount Paid', 'Method', 'Notes', 'Receipt File', 'Recorded'], $payments));
    $zip->addFromString('budgets.csv', exportCsv(['Category', 'Monthly Limit', 'Last Changed'], $budgets));
    $zip->addFromString('categories.csv', exportCsv(['Category ID', 'Your Custom Category'], $categories));
    $zip->addFromString('activity_log.csv', exportCsv(['When', 'Action', 'Details', 'IP Address'], $activity));
    $zip->addFromString('README.txt', implode("\r\n", [
        'AfterBill — your data',
        'Exported ' . date('F j, Y g:i A') . ' for ' . $user['email'],
        '',
        'profile.json      Your name, email and settings (your password is never included)',
        'bills.csv         ' . count($bills) . ' bills',
        'payments.csv      ' . count($payments) . ' payments',
        'budgets.csv       ' . count($budgets) . ' monthly budgets',
        'categories.csv    ' . count($categories) . ' custom categories',
        'activity_log.csv  ' . count($activity) . ' account events',
        'receipts/         ' . $receiptCount . ' receipt files (see the Receipt File column in payments.csv)',
        '',
        'CSV files open in Excel, Google Sheets or LibreOffice. Text that starts with = + - or @',
        'has an apostrophe in front so spreadsheets show it as text instead of running it.',
    ]));

    if (!$zip->close()) {
        @unlink($path);
        throw new RuntimeException('Cannot write the ZIP file');
    }
    return $path;
}
