<?php
/**
 * AfterBill - Monthly report as a PDF download
 * GET report_pdf.php?month=YYYY-MM (default: this month). Same figures as reports.php.
 *
 * Rendered with dompdf from plain HTML built here. Every user value is escaped, and dompdf may not
 * fetch anything remote or read files outside this app (isRemoteEnabled off, chroot).
 */

require_once __DIR__ . '/includes/auth.php';
require_login(); // session check, idle timeout, sign-out after a password change

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/recurring_bills.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];
generateMissedRecurringBills($pdo, $userId);

$monthParam = $_GET['month'] ?? date('Y-m');
if (!is_string($monthParam) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$monthStart = new DateTime($monthParam . '-01');
$from = $monthStart->format('Y-m-d');
$to = $monthStart->format('Y-m-t');

$totals = billTotals($pdo, $userId, $from, $to);

$catStmt = $pdo->prepare(
    'SELECT c.category_id, c.category_name, COUNT(*) AS cnt, SUM(b.amount) AS total
     FROM bills b JOIN categories c ON c.category_id = b.category_id
     WHERE b.user_id = ? AND b.due_date BETWEEN ? AND ?
     GROUP BY c.category_id, c.category_name
     ORDER BY total DESC, c.category_name'
);
$catStmt->execute([$userId, $from, $to]);
$categories = $catStmt->fetchAll();

$billStmt = $pdo->prepare(
    "SELECT b.bill_name, b.amount, b.due_date, b.status, c.category_name,
            (SELECT COALESCE(SUM(p.amount_paid), 0) FROM payments p WHERE p.bill_id = b.bill_id) AS paid_total
     FROM bills b JOIN categories c ON c.category_id = b.category_id
     WHERE b.user_id = ? AND b.due_date BETWEEN ? AND ?
     ORDER BY b.due_date, b.bill_name"
);
$billStmt->execute([$userId, $from, $to]);
$bills = $billStmt->fetchAll();

// The 6 months ending with the report month, for the small column chart.
$histStart = (clone $monthStart)->modify('-5 months');
$histRows = billTotals($pdo, $userId, $histStart->format('Y-m-d'), $to, true);
$history = [];
$histMax = 0.0;
for ($i = 0; $i < 6; $i++) {
    $m = (clone $histStart)->modify("+$i months");
    $t = $histRows[$m->format('Y-m')] ?? ['paid' => 0.0, 'unpaid' => 0.0, 'total' => 0.0];
    $history[] = ['label' => $m->format('M'), 'paid' => $t['paid'], 'owed' => $t['unpaid'], 'total' => $t['total'], 'current' => $i === 5];
    $histMax = max($histMax, $t['total']);
}

// Same category colours as the Reports page donut (keyed by id, so a category keeps its colour).
$palette = ['#3B82F6', '#06B6D4', '#8B5CF6', '#EC4899', '#64748B', '#92400E', '#D946EF'];
$catColor = fn($id) => $palette[((int)$id - 1) % count($palette)];

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$peso = fn($n) => '₱' . number_format((float)$n, 2);
$compact = function (float $n): string {
    if ($n >= 1000000) return '₱' . rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    if ($n >= 1000) return '₱' . rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'k';
    return '₱' . number_format($n, 0);
};
$today = date('Y-m-d');
$paidPct = $totals['total'] > 0 ? $totals['paid'] / $totals['total'] * 100 : 0;
$logo = __DIR__ . '/assets/images/logo-white.png';

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 0 0 46px 0; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #1F2937; margin: 0; }

    /* Header band — same navy as the app sidebar, blue accent line under it */
    .band { background: #17233C; color: #fff; padding: 26px 40px 22px; }
    .band table { width: 100%; border-collapse: collapse; }
    .band td { vertical-align: middle; padding: 0; }
    .band img { height: 22px; }
    .band .kicker { text-align: right; font-size: 8px; letter-spacing: 2px; color: #93C5FD; font-weight: bold; }
    .band .month { text-align: right; font-size: 20px; font-weight: bold; color: #fff; margin-top: 2px; }
    .accent { height: 4px; background: #3B82F6; }
    .meta { padding: 10px 40px 0; color: #6B7280; font-size: 8.5px; }

    .content { padding: 4px 40px 0; }
    h2 { font-size: 11.5px; color: #17233C; margin: 20px 0 8px; padding-bottom: 5px; border-bottom: 1px solid #E5E7EB; }
    h2 span { color: #6B7280; font-weight: normal; font-size: 8.5px; }

    /* Stat cards */
    .cards { width: 100%; border-collapse: collapse; margin-top: 14px; }
    .cards td.gap { width: 2%; padding: 0; border: none; }
    .card { border: 1px solid #E5E7EB; border-radius: 8px; padding: 11px 12px 10px; width: 23.5%; vertical-align: top; background: #fff; }
    .card .lbl { font-size: 7.5px; letter-spacing: 1px; color: #6B7280; font-weight: bold; text-transform: uppercase; }
    .card .val { font-size: 15px; font-weight: bold; color: #17233C; margin-top: 4px; }
    .card .sub { font-size: 8px; color: #6B7280; margin-top: 2px; }
    .card.blue { border-top: 3px solid #3B82F6; } .card.green { border-top: 3px solid #10B981; }
    .card.amber { border-top: 3px solid #F59E0B; } .card.navy { border-top: 3px solid #17233C; }
    .meter { height: 6px; background: #F3F4F6; border-radius: 3px; margin-top: 7px; }
    .meter div { height: 6px; background: #10B981; border-radius: 3px; }

    /* Two-column middle: categories + history chart */
    .split { width: 100%; border-collapse: collapse; }
    .split > tbody > tr > td { vertical-align: top; padding: 0; }
    .cat { width: 100%; border-collapse: collapse; }
    .cat td { padding: 5px 0; border-bottom: 1px solid #F3F4F6; vertical-align: middle; }
    .dot { display: inline-block; width: 7px; height: 7px; border-radius: 4px; margin-right: 6px; }
    .share { height: 5px; background: #F3F4F6; border-radius: 3px; }
    .share div { height: 5px; border-radius: 3px; }
    .r { text-align: right; }
    .muted { color: #6B7280; }

    .chart { width: 100%; border-collapse: collapse; }
    .chart td { vertical-align: bottom; text-align: center; padding: 0 4px; height: 118px; }
    .col { width: 22px; margin: 0 auto; }
    .col .owed { background: #F59E0B; border-radius: 3px 3px 0 0; }
    .col .paid { background: #10B981; }
    .col .paid.solo { border-radius: 3px 3px 0 0; }
    .chart .val { font-size: 7.5px; font-weight: bold; color: #17233C; margin-bottom: 3px; }
    .chart .mon td { height: auto; padding-top: 4px; font-size: 8px; color: #374151; border-top: 1px solid #D1D5DB; }
    .chart .mon td.now { color: #3B82F6; font-weight: bold; }
    .legend { text-align: center; font-size: 7.5px; color: #6B7280; margin-top: 6px; }
    .legend i { display: inline-block; width: 7px; height: 7px; border-radius: 2px; margin: 0 4px 0 10px; }

    /* Bills table */
    table.list { width: 100%; border-collapse: collapse; }
    table.list th { background: #17233C; color: #fff; text-align: left; font-size: 7.5px; letter-spacing: 1px; text-transform: uppercase; padding: 7px 8px; }
    table.list th.r { text-align: right; }
    table.list td { padding: 6px 8px; border-bottom: 1px solid #EEF0F3; vertical-align: middle; }
    table.list tr.alt td { background: #F8FAFC; }
    table.list .name { font-weight: bold; color: #17233C; }
    table.list tfoot td { background: #EFF6FF; font-weight: bold; color: #17233C; border-top: 2px solid #3B82F6; border-bottom: none; }
    .pill { display: inline-block; padding: 2px 8px; border-radius: 9px; font-size: 7.5px; font-weight: bold; }
    .pill.paid { background: #ECFDF5; color: #047857; } .pill.unpaid { background: #FFFBEB; color: #B45309; }
    .pill.overdue { background: #FEF2F2; color: #B91C1C; } .pill.partial { background: #EFF6FF; color: #1D4ED8; }
    .empty { color: #6B7280; padding: 14px 0; text-align: center; border: 1px dashed #E5E7EB; border-radius: 8px; }

    .footer { position: fixed; bottom: -30px; left: 40px; right: 40px; font-size: 7.5px; color: #9CA3AF; border-top: 1px solid #E5E7EB; padding-top: 6px; }
</style>
</head>
<body>
    <div class="footer">AfterBill · Monthly report for <?= $h($monthStart->format('F Y')) ?> · "Paid" counts money actually paid, so a partly paid bill counts only the part paid.</div>

    <div class="band">
        <table><tr>
            <td><?php if (is_file($logo)): ?><img src="<?= $h($logo) ?>" alt="AfterBill"><?php else: ?><b style="font-size:16px;letter-spacing:3px;">AFTERBILL</b><?php endif; ?></td>
            <td><div class="kicker">MONTHLY REPORT</div><div class="month"><?= $h($monthStart->format('F Y')) ?></div></td>
        </tr></table>
    </div>
    <div class="accent"></div>
    <div class="meta">Prepared for <b style="color:#17233C;"><?= $h($_SESSION['user_name'] ?? '') ?></b> · generated <?= $h(date('F j, Y g:i A')) ?></div>

    <div class="content">
        <table class="cards"><tr>
            <td class="card blue">
                <div class="lbl">Total due</div>
                <div class="val"><?= $peso($totals['total']) ?></div>
                <div class="sub"><?= $totals['count'] ?> bill<?= $totals['count'] === 1 ? '' : 's' ?> this month</div>
            </td>
            <td class="gap"></td>
            <td class="card green">
                <div class="lbl">Paid</div>
                <div class="val"><?= $peso($totals['paid']) ?></div>
                <div class="sub"><?= $totals['paid_count'] ?> fully paid</div>
            </td>
            <td class="gap"></td>
            <td class="card amber">
                <div class="lbl">Still owed</div>
                <div class="val"><?= $peso($totals['unpaid']) ?></div>
                <div class="sub"><?= $totals['unpaid_count'] + $totals['partial_count'] ?> bill<?= ($totals['unpaid_count'] + $totals['partial_count']) === 1 ? '' : 's' ?><?= $totals['partial_count'] ? ' · ' . $totals['partial_count'] . ' partly paid' : '' ?></div>
            </td>
            <td class="gap"></td>
            <td class="card navy">
                <div class="lbl">Paid so far</div>
                <div class="val"><?= $totals['total'] > 0 ? number_format($paidPct, 0) . '%' : '—' ?></div>
                <div class="meter"><div style="width: <?= min(100, round($paidPct, 1)) ?>%;"></div></div>
            </td>
        </tr></table>

        <table class="split"><tr>
            <td style="width: 54%; padding-right: 18px;">
                <h2>Spending by category</h2>
                <?php if (!$categories): ?>
                    <div class="empty">No bills due this month.</div>
                <?php else: ?>
                    <table class="cat">
                        <?php foreach ($categories as $c): ?>
                            <?php $share = $totals['total'] > 0 ? $c['total'] / $totals['total'] * 100 : 0; ?>
                            <tr>
                                <td style="width: 38%;"><span class="dot" style="background: <?= $catColor($c['category_id']) ?>;"></span><?= $h($c['category_name']) ?> <span class="muted">· <?= (int)$c['cnt'] ?></span></td>
                                <td style="width: 34%; padding-right: 10px;"><div class="share"><div style="width: <?= max(2, round($share, 1)) ?>%; background: <?= $catColor($c['category_id']) ?>;"></div></div></td>
                                <td class="r" style="width: 28%;"><b><?= $peso($c['total']) ?></b> <span class="muted"><?= number_format($share, 0) ?>%</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </td>
            <td style="width: 46%;">
                <h2>Last 6 months <span>· due per month</span></h2>
                <table class="chart">
                    <tr>
                        <?php foreach ($history as $m): ?>
                            <?php
                            $scale = $histMax > 0 ? 96 / $histMax : 0;
                            $ph = round($m['paid'] * $scale, 1);
                            $oh = round($m['owed'] * $scale, 1);
                            ?>
                            <td>
                                <?php if ($m['total'] > 0): ?><div class="val"><?= $compact($m['total']) ?></div><?php endif; ?>
                                <div class="col">
                                    <?php if ($oh > 0): ?><div class="owed" style="height: <?= max(1, $oh) ?>px;<?= $ph > 0 ? ' margin-bottom: 1px;' : '' ?>"></div><?php endif; ?>
                                    <?php if ($ph > 0): ?><div class="paid<?= $oh > 0 ? '' : ' solo' ?>" style="height: <?= max(1, $ph) ?>px;"></div><?php endif; ?>
                                </div>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr class="mon">
                        <?php foreach ($history as $m): ?><td class="<?= $m['current'] ? 'now' : '' ?>"><?= $m['label'] ?></td><?php endforeach; ?>
                    </tr>
                </table>
                <div class="legend"><i style="background:#10B981;"></i>Paid<i style="background:#F59E0B;"></i>Still owed</div>
            </td>
        </tr></table>

        <h2>Bills due in <?= $h($monthStart->format('F Y')) ?> <span>· <?= count($bills) ?> bill<?= count($bills) === 1 ? '' : 's' ?></span></h2>
        <?php if (!$bills): ?>
            <div class="empty">No bills due this month.</div>
        <?php else: ?>
            <table class="list">
                <thead><tr><th style="width: 13%;">Due</th><th>Bill</th><th>Category</th><th class="r">Amount</th><th class="r">Paid</th><th style="width: 15%;">Status</th></tr></thead>
                <tbody>
                    <?php foreach ($bills as $i => $b): ?>
                        <?php
                        $paidAmt = $b['status'] === 'paid' ? (float)$b['amount'] : min((float)$b['paid_total'], (float)$b['amount']);
                        if ($b['status'] === 'paid') {
                            [$cls, $label] = ['paid', 'Paid'];
                        } elseif ($b['due_date'] < $today) {
                            [$cls, $label] = ['overdue', 'Overdue'];
                        } elseif ($paidAmt > 0) {
                            [$cls, $label] = ['partial', 'Partially paid'];
                        } else {
                            [$cls, $label] = ['unpaid', 'Unpaid'];
                        }
                        ?>
                        <tr class="<?= $i % 2 ? 'alt' : '' ?>">
                            <td><?= $h((new DateTime($b['due_date']))->format('M j, Y')) ?></td>
                            <td class="name"><?= $h($b['bill_name']) ?></td>
                            <td><?= $h($b['category_name']) ?></td>
                            <td class="r"><?= $peso($b['amount']) ?></td>
                            <td class="r"><?= $paidAmt > 0 ? $peso($paidAmt) : '<span class="muted">—</span>' ?></td>
                            <td><span class="pill <?= $cls ?>"><?= $label ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="3">Total</td><td class="r"><?= $peso($totals['total']) ?></td><td class="r"><?= $peso($totals['paid']) ?></td><td></td></tr></tfoot>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isPhpEnabled', false);
$options->set('isJavascriptEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$options->set('chroot', __DIR__);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// "Page X of Y" in the footer (CSS can't know the page count, so it is stamped on afterwards).
$canvas = $dompdf->getCanvas();
$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
// Canvas units are points (CSS px × 0.75): 30pt = the 40px side margin, same size/colour as the footer text.
$label = 'Page {PAGE_NUM} of {PAGE_COUNT}';
$size = 5.6;
$width = $dompdf->getFontMetrics()->getTextWidth('Page 00 of 00', $font, $size);
$canvas->page_text($canvas->get_width() - 30 - $width, $canvas->get_height() - 21.5, $label, $font, $size, [0.61, 0.64, 0.69]);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="afterbill-report-' . $monthParam . '.pdf"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
echo $dompdf->output();
