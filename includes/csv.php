<?php
/**
 * AfterBill - CSV helpers shared by the payments and bills exports.
 */

/**
 * User-typed text starting with = + - @ (or tab/CR) is run as a formula by Excel/Sheets,
 * e.g. =HYPERLINK(...). Prefixing an apostrophe makes the spreadsheet show it as plain text.
 */
function csvSafe(?string $s): string
{
    if ($s === null || $s === '') {
        return '';
    }
    return strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
}

/** Sends CSV download headers plus a UTF-8 BOM (so Excel reads ₱ and accents correctly). */
function csvStart(string $filename)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    return $out;
}
