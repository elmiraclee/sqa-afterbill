<?php
/**
 * AfterBill - Calendar
 * Renders bills for the selected month from the database; navigating
 * months/days always issues a fresh query, so it can never show stale
 * or fake data.
 */

require_once __DIR__ . '/includes/auth.php';
require_login();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/bills_repo.php';

$userId = current_user_id();
$user = get_user($userId);
$userName = $user['full_name'] ?? ($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));
$currency = $user['currency'] ?? 'PHP';

$today = new DateTime();
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)$today->format('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)$today->format('n');

if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }
if ($year < 1970) { $year = (int)$today->format('Y'); }

$firstOfMonth = new DateTime(sprintf('%04d-%02d-01', $year, $month));
$daysInMonth = (int)$firstOfMonth->format('t');
$firstWeekday = (int)$firstOfMonth->format('w');
$isCurrentMonth = ($year === (int)$today->format('Y') && $month === (int)$today->format('n'));

$selectedDay = null;
if (isset($_GET['day'])) {
    $d = (int)$_GET['day'];
    if ($d >= 1 && $d <= $daysInMonth) {
        $selectedDay = $d;
    }
} elseif ($isCurrentMonth) {
    $selectedDay = (int)$today->format('j');
}

$monthBills = bills_for_month($userId, $year, $month);
$byDay = [];
foreach ($monthBills as $bill) {
    $d = (int)substr($bill['due_date'], 8, 2);
    $byDay[$d][] = $bill;
}

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$selectedDate = $selectedDay ? sprintf('%04d-%02d-%02d', $year, $month, $selectedDay) : null;
$selectedBills = $selectedDay ? ($byDay[$selectedDay] ?? []) : [];

$redirectQs = http_build_query(array_filter(['year' => $year, 'month' => $month, 'day' => $selectedDay]));
$redirectTo = 'calendar.php';

$pageTitle = 'Calendar';
$pageSubtitle = 'View your bills by due date.';
$activePage = 'calendar';
$weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar – AfterBill</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app">

    <?php include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <?php include __DIR__ . '/partials/topbar.php'; ?>

        <main class="page-body">
            <?php include __DIR__ . '/partials/alerts.php'; ?>

            <div class="content-grid" style="grid-template-columns: 1.4fr 1fr; align-items:start;">
                <div class="panel">
                    <div class="panel-body padded">
                        <div class="calendar-nav">
                            <a href="calendar.php?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="icon-btn" aria-label="Previous month">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            </a>
                            <div class="cal-title"><?= $firstOfMonth->format('F Y') ?></div>
                            <div class="calendar-nav-controls">
                                <a href="calendar.php" class="btn btn-outline btn-sm">Today</a>
                                <a href="calendar.php?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="icon-btn" aria-label="Next month">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                                </a>
                            </div>
                        </div>

                        <div class="calendar-grid">
                            <?php foreach ($weekdays as $wd): ?>
                                <div class="calendar-weekday"><?= $wd ?></div>
                            <?php endforeach; ?>

                            <?php for ($i = 0; $i < $firstWeekday; $i++): ?>
                                <div class="calendar-day empty"></div>
                            <?php endfor; ?>

                            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                                $dayBills = $byDay[$d] ?? [];
                                $isToday = $isCurrentMonth && $d === (int)$today->format('j');
                                $isSelected = $selectedDay === $d;
                                $classes = 'calendar-day' . ($isToday ? ' today' : '') . ($isSelected ? ' selected' : '');
                            ?>
                                <a class="<?= $classes ?>" href="calendar.php?year=<?= $year ?>&month=<?= $month ?>&day=<?= $d ?>" style="text-decoration:none;">
                                    <span class="day-num"><?= $d ?></span>
                                    <?php if (!empty($dayBills)): ?>
                                        <div class="day-dots">
                                            <?php foreach (array_slice($dayBills, 0, 4) as $b):
                                                $ds = bill_display_status($b['status'], $b['due_date']);
                                            ?>
                                                <span class="day-dot <?= $ds ?>"></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if (count($dayBills) > 4): ?>
                                            <span class="day-more">+<?= count($dayBills) - 4 ?> more</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </a>
                            <?php endfor; ?>
                        </div>

                        <div class="calendar-legend">
                            <div class="legend-item"><span class="legend-dot" style="background:var(--electric-blue);"></span> Upcoming</div>
                            <div class="legend-item"><span class="legend-dot" style="background:var(--success);"></span> Paid</div>
                            <div class="legend-item"><span class="legend-dot" style="background:var(--danger);"></span> Overdue</div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-title">
                            <?= $selectedDate ? (new DateTime($selectedDate))->format('l, M j, Y') : 'Select a date' ?>
                        </span>
                        <?php if ($selectedDate): ?>
                            <button type="button" class="btn btn-primary btn-sm" onclick="openAddBillModal('<?= $selectedDate ?>')">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                Add Bill
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="panel-body">
                        <?php if (empty($selectedBills)): ?>
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                                </svg>
                                <p>No bills scheduled for this date.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($selectedBills as $bill):
                                $display = bill_display_status($bill['status'], $bill['due_date']);
                            ?>
                            <div class="bill-item">
                                <div class="bill-icon"><?= category_icon($bill['category']) ?></div>
                                <div class="bill-info">
                                    <div class="bill-name"><?= e($bill['name']) ?></div>
                                    <div class="bill-due"><?= e($bill['category']) ?> · <?= format_money((float)$bill['amount'], $currency) ?></div>
                                </div>
                                <div class="cell-actions">
                                    <span class="bill-status <?= status_badge_class($display) ?>" style="margin-right:4px;"><?= status_label($display) ?></span>

                                    <?php if ($display === 'paid'): ?>
                                        <form method="POST" action="bills.php" style="display:contents;">
                                            <input type="hidden" name="action" value="mark_unpaid">
                                            <input type="hidden" name="bill_id" value="<?= (int)$bill['id'] ?>">
                                            <input type="hidden" name="redirect_to" value="calendar.php">
                                            <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
                                            <button type="submit" class="icon-btn" title="Mark as Unpaid">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" action="bills.php" style="display:contents;">
                                            <input type="hidden" name="action" value="mark_paid">
                                            <input type="hidden" name="bill_id" value="<?= (int)$bill['id'] ?>">
                                            <input type="hidden" name="redirect_to" value="calendar.php">
                                            <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
                                            <button type="submit" class="icon-btn" title="Mark as Paid">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <button type="button" class="icon-btn" title="View / Edit Details"
                                        onclick='openEditBillModal(<?= json_encode($bill, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                    </button>

                                    <form method="POST" action="bills.php" data-confirm="Delete this bill? This cannot be undone." style="display:contents;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="bill_id" value="<?= (int)$bill['id'] ?>">
                                        <input type="hidden" name="redirect_to" value="calendar.php">
                                        <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>">
                                        <button type="submit" class="icon-btn danger" title="Delete">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include __DIR__ . '/partials/bill-modal.php'; ?>

<script src="assets/js/app.js"></script>
</body>
</html>
