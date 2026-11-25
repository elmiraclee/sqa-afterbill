<?php
/**
 * AfterBill - Payments: partial payments and receipt files
 *
 * A bill can have several payments. It is 'paid' once its payments add up to its amount, and
 * 'unpaid' (partially paid when some money is in) until then. refreshBillStatus() is the single
 * place that rule lives; call it after anything that changes a bill's payments or amount.
 *
 * Receipts are stored in storage/receipts/ under a random name (storage/ is never served by
 * Apache) and are only ever sent to their owner through receipt.php.
 */

const RECEIPT_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
// Detected content type => stored extension. Anything else is rejected.
const RECEIPT_TYPES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];
const RECEIPT_MIME_BY_EXT = [
    'jpg'  => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
];

/** Money is compared in whole centavos so 0.1 + 0.2 style float error never decides "paid". */
function toCents($amount): int
{
    return (int)round((float)$amount * 100);
}

/** Total paid so far on a bill. */
function billPaidTotal(PDO $pdo, int $billId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE bill_id = ?');
    $stmt->execute([$billId]);
    return (float)$stmt->fetchColumn();
}

/**
 * Sets the bill's status from its payments: 'paid' when they cover the amount, else 'unpaid'
 * (no payments = unpaid). Returns the status the bill now has. Callers that merely edit a bill
 * should only call this when the bill has payments, so a bill that was marked paid before
 * payments were tracked isn't flipped back.
 */
function refreshBillStatus(PDO $pdo, int $billId): string
{
    $stmt = $pdo->prepare(
        'SELECT b.amount, b.status, COUNT(p.payment_id) AS n, COALESCE(SUM(p.amount_paid), 0) AS paid
         FROM bills b LEFT JOIN payments p ON p.bill_id = b.bill_id
         WHERE b.bill_id = ? GROUP BY b.bill_id'
    );
    $stmt->execute([$billId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'unpaid';
    }
    if ((int)$row['n'] === 0) {
        // Every payment was removed: there is nothing left covering the bill.
        $status = 'unpaid';
    } else {
        $status = toCents($row['paid']) >= toCents($row['amount']) ? 'paid' : 'unpaid';
    }
    if ($status !== $row['status']) {
        $pdo->prepare('UPDATE bills SET status = ? WHERE bill_id = ?')->execute([$status, $billId]);
    }
    return $status;
}

function receiptDir(): string
{
    return __DIR__ . '/../storage/receipts';
}

/** Full path of a stored receipt, or null if the name isn't one this app generated. */
function receiptPath(?string $fileName): ?string
{
    if ($fileName === null || !preg_match('/^[0-9a-f]{32}\.(jpg|png|webp|pdf)$/', $fileName)) {
        return null;
    }
    return receiptDir() . '/' . $fileName;
}

/**
 * Checks an optional upload from $_FILES. Returns [extension|null, error|null]:
 * [null, null] = nothing uploaded, ['png', null] = valid, [null, 'message'] = rejected.
 * The type is detected from the file's contents, never trusted from the browser or file name.
 */
function checkReceiptUpload(?array $file): array
{
    if ($file === null || !isset($file['error']) || is_array($file['error'])) {
        return [null, null];
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'The receipt is too large. The maximum is 5 MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, 'The receipt could not be uploaded. Please try again.'];
    }
    $size = filesize($file['tmp_name']);
    if ($size === false || $size === 0) {
        return [null, 'The receipt file is empty.'];
    }
    if ($size > RECEIPT_MAX_BYTES) {
        return [null, 'The receipt is too large. The maximum is 5 MB.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(RECEIPT_TYPES[$mime])) {
        return [null, 'Receipts must be a JPG, PNG, WebP image or a PDF.'];
    }
    // An image must actually decode as one (a renamed script with a fake header fails here).
    if ($mime !== 'application/pdf' && @getimagesize($file['tmp_name']) === false) {
        return [null, 'The receipt image appears to be damaged.'];
    }
    return [RECEIPT_TYPES[$mime], null];
}

/** Moves a checked upload into storage under a new random name. Returns the name, or null on failure. */
function storeReceipt(string $tmpName, string $ext): ?string
{
    $dir = receiptDir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        error_log('AfterBill: cannot create receipt folder ' . $dir);
        return null;
    }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmpName, $dir . '/' . $name)) {
        error_log('AfterBill: cannot store receipt in ' . $dir);
        return null;
    }
    @chmod($dir . '/' . $name, 0640);
    return $name;
}

/** Deletes a stored receipt file; quietly does nothing for null/unknown names or missing files. */
function deleteReceiptFile(?string $fileName): void
{
    $path = receiptPath($fileName);
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}

/**
 * Totals for the user's bills due between $from and $to (Y-m-d, inclusive), counting money
 * actually paid: a paid bill counts in full; a partially paid bill splits into what was paid and
 * what is still owed. Used by the dashboard, reports and the PDF report so they always agree.
 *
 * With $byMonth = false returns one row; with true, rows keyed 'YYYY-MM'. Each row:
 *   count, paid_count (fully paid), partial_count, unpaid_count (nothing paid yet),
 *   total, paid (money in), unpaid (money still owed) — total = paid + unpaid.
 */
function billTotals(PDO $pdo, int $userId, string $from, string $to, bool $byMonth = false): array
{
    $stmt = $pdo->prepare(
        'SELECT ' . ($byMonth ? "DATE_FORMAT(b.due_date, '%Y-%m')" : "'all'") . " AS ym,
                COUNT(*) AS cnt,
                SUM(b.status = 'paid') AS paid_cnt,
                SUM(b.status = 'unpaid' AND COALESCE(p.paid, 0) > 0) AS partial_cnt,
                COALESCE(SUM(b.amount), 0) AS total,
                COALESCE(SUM(CASE WHEN b.status = 'paid' THEN b.amount
                                  ELSE LEAST(COALESCE(p.paid, 0), b.amount) END), 0) AS paid
         FROM bills b
         LEFT JOIN (SELECT bill_id, SUM(amount_paid) AS paid FROM payments GROUP BY bill_id) p
                ON p.bill_id = b.bill_id
         WHERE b.user_id = ? AND b.due_date BETWEEN ? AND ?
         GROUP BY ym"
    );
    $stmt->execute([$userId, $from, $to]);

    $empty = ['count' => 0, 'paid_count' => 0, 'partial_count' => 0, 'unpaid_count' => 0,
              'total' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0];
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $row = [
            'count'         => (int)$r['cnt'],
            'paid_count'    => (int)$r['paid_cnt'],
            'partial_count' => (int)$r['partial_cnt'],
            'unpaid_count'  => (int)$r['cnt'] - (int)$r['paid_cnt'] - (int)$r['partial_cnt'],
            'total'         => round((float)$r['total'], 2),
            'paid'          => round((float)$r['paid'], 2),
        ];
        $row['unpaid'] = round($row['total'] - $row['paid'], 2);
        $rows[$r['ym']] = $row;
    }
    if ($byMonth) {
        return $rows;
    }
    // One row ('all'), or none when there are no bills in the range.
    return $rows['all'] ?? $empty;
}
