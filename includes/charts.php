<?php
/**
 * AfterBill - Chart helpers shared by reports.php and dashboard.php (server-rendered SVG).
 */

function chartCompactAmount(float $n): string
{
    if ($n >= 1000000) {
        return '₱' . rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return '₱' . rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k';
    }
    return '₱' . number_format($n, 0);
}

function chartNiceStep(float $rough): float
{
    $pow = 10 ** floor(log10($rough));
    foreach ([1, 2, 2.5, 5, 10] as $mult) {
        if ($mult * $pow >= $rough) {
            return $mult * $pow;
        }
    }
    return 10 * $pow;
}

/**
 * SVG path for a column whose top corners are rounded (radius $r, shrunk for short columns) and
 * whose bottom sits flat on the baseline.
 */
function chartTopRoundedBar(float $x, float $y, float $w, float $h, float $r = 4): string
{
    $r = min($r, $h, $w / 2);
    return sprintf('M%.2f,%.2f V%.2f Q%.2f,%.2f %.2f,%.2f H%.2f Q%.2f,%.2f %.2f,%.2f V%.2f Z',
        $x, $y + $h, $y + $r, $x, $y, $x + $r, $y, $x + $w - $r, $x + $w, $y, $x + $w, $y + $r, $y + $h);
}
