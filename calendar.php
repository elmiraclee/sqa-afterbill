<?php
/**
 * AfterBill - Calendar View
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/recurring_bills.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pdo = getPDO();

// Same as dashboard.php/bills.php: catch up recurring bills before querying, in case this is
// the first page a user lands on this session.
generateMissedRecurringBills($pdo, (int)$_SESSION['user_id']);

// Resolve the visible month from ?month=YYYY-MM, defaulting to the current month.
$monthParam = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)) {
    $monthParam = date('Y-m');
}
$monthStart = new DateTime($monthParam . '-01');
$monthEnd = (clone $monthStart)->modify('last day of this month');
$prevMonth = (clone $monthStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $monthStart)->modify('+1 month')->format('Y-m');

// One query for the whole visible month — gives us both the per-day badge (Step 2) and the
// full bill list per day for the click-to-expand behavior (Step 3), no second round trip.
$stmt = $pdo->prepare(
    'SELECT bill_id, bill_name, amount, due_date, status
     FROM bills
     WHERE user_id = ?
       AND due_date BETWEEN ? AND ?
     ORDER BY due_date ASC'
);
$stmt->execute([
    $_SESSION['user_id'],
    $monthStart->format('Y-m-d'),
    $monthEnd->format('Y-m-d'),
]);
$monthBills = $stmt->fetchAll();

// Group bills by due_date.
$billsByDay = [];
foreach ($monthBills as $bill) {
    $billsByDay[$bill['due_date']][] = $bill;
}

// Determine each day's badge color. "Overdue" is computed here exactly like bills.php
// (status = 'unpaid' AND due_date < today) — never stored. Since every bill in a $dayBills
// group shares the same due_date, the date-vs-today comparison only needs to happen once per
// day, not once per bill.
$today = date('Y-m-d');
$dayBadge = [];
foreach ($billsByDay as $day => $dayBills) {
    $hasUnpaid = false;
    foreach ($dayBills as $bill) {
        if ($bill['status'] === 'unpaid') {
            $hasUnpaid = true;
            break;
        }
    }
    if ($hasUnpaid && $day < $today) {
        $dayBadge[$day] = 'overdue';
    } elseif ($hasUnpaid) {
        $dayBadge[$day] = 'unpaid';
    } else {
        $dayBadge[$day] = 'paid';
    }
}

// Build grid cells: leading/trailing blanks so the month starts on the correct weekday and
// every row completes a full week.
$firstWeekday = (int)$monthStart->format('w'); // 0 = Sunday
$daysInMonth = (int)$monthEnd->format('j');

$calendarCells = array_fill(0, $firstWeekday, null);
for ($day = 1; $day <= $daysInMonth; $day++) {
    $calendarCells[] = $monthStart->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
}
while (count($calendarCells) % 7 !== 0) {
    $calendarCells[] = null;
}
$calendarWeeks = array_chunk($calendarCells, 7);
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
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --deep-navy: #17233C;
            --electric-blue: #3B82F6;
            --electric-blue-hover: #2563EB;
            --white: #FFFFFF;
            --light-gray: #F3F4F6;
            --text-dark: #1F2937;
            --text-muted: #6B7280;
            --border: #E5E7EB;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --sidebar-width: 240px;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--light-gray);
            color: var(--text-dark);
        }

        .app { display: flex; min-height: 100vh; }

        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--deep-navy) 0%, #0F172A 100%);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
            overflow: hidden;
        }

        .sidebar-logo {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }

        .sidebar-logo img { height: 52px; width: auto; display: block; filter: brightness(0) invert(1); opacity: 0.9; }

        .sidebar-nav { flex: 1; padding: 16px 12px; display: flex; flex-direction: column; gap: 2px; }

        .nav-label {
            font-size: 0.65rem;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.3);
            padding: 10px 10px 6px;
            font-weight: 600;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            color: rgba(255,255,255,0.65);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background 0.18s, color 0.18s;
        }

        .nav-item:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.9); }
        .nav-item.active { background: rgba(59, 130, 246, 0.2); color: #fff; }
        .nav-item svg { width: 18px; height: 18px; flex-shrink: 0; }

        .sidebar-footer { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.07); }

        .user-card { display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: 10px; }

        .user-avatar {
            width: 34px; height: 34px;
            background: var(--electric-blue);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.8rem; font-weight: 700; color: #fff;
            flex-shrink: 0;
        }

        .user-info { flex: 1; min-width: 0; }
        .user-name { font-size: 0.82rem; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .main { margin-left: var(--sidebar-width); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

        .topbar {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .topbar-left h2 { font-size: 1.1rem; font-weight: 700; color: var(--deep-navy); }
        .topbar-left p { font-size: 0.8rem; color: var(--text-muted); }

        .btn-logout {
            display: flex; align-items: center; gap: 6px;
            padding: 8px 16px;
            background: none;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.82rem; font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            transition: border-color 0.18s, color 0.18s, background 0.18s;
        }

        .btn-logout:hover { border-color: var(--danger); color: var(--danger); background: #FEF2F2; }
        .btn-logout svg { width: 15px; height: 15px; }

        .page-body { padding: 32px; flex: 1; }

        .panel {
            background: var(--white);
            border-radius: 14px;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
        }

        .calendar-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--deep-navy);
        }

        .calendar-nav { display: flex; align-items: center; gap: 8px; }

        .btn-nav {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            background: none;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            color: var(--text-muted);
            text-decoration: none;
        }

        .btn-nav:hover { border-color: var(--electric-blue); color: var(--electric-blue); }
        .btn-nav svg { width: 16px; height: 16px; }

        .calendar-grid { padding: 16px 22px 22px; }

        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
            margin-bottom: 8px;
        }

        .calendar-weekdays div {
            text-align: center;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            padding: 6px 0;
        }

        .calendar-week {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 6px;
            margin-bottom: 6px;
        }

        .calendar-day {
            position: relative;
            min-height: 84px;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 8px;
            background: var(--white);
        }

        .calendar-day.blank { background: transparent; border-color: transparent; }

        .calendar-day.today { border-color: var(--electric-blue); border-width: 2px; padding: 7px; }

        .day-number { font-size: 0.8rem; font-weight: 600; color: var(--text-dark); }
        .calendar-day.today .day-number { color: var(--electric-blue); }

        .day-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 700;
            margin-top: 6px;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }

        .day-badge.paid    { background: #ECFDF5; color: #047857; }
        .day-badge.unpaid  { background: #FFFBEB; color: #B45309; }
        .day-badge.overdue { background: #FEF2F2; color: #B91C1C; }

        /* Click-to-expand day details (native <details>, no JS) */
        .calendar-day summary { cursor: pointer; list-style: none; }
        .calendar-day summary::-webkit-details-marker { display: none; }
        .calendar-day summary::marker { content: ''; }

        /* position: fixed (not absolute) so the popup escapes .panel's overflow: hidden —
           an ancestor clipping the popup at the panel's edge was the real cause of it
           rendering cut off, separate from whether it fit in the viewport. JS sets the
           exact top/left in pixels, computed from the clicked cell's position at open time. */
        .day-popup {
            position: fixed;
            z-index: 20;
            min-width: 200px;
            max-width: 260px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(23,35,60,0.15);
            padding: 10px;
        }

        .day-popup-title {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 8px;
        }

        .day-popup-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 6px 0;
            border-bottom: 1px solid var(--border);
        }

        .day-popup-item:last-child { border-bottom: none; }

        .popup-bill-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-dark);
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .popup-bill-amount { font-size: 0.78rem; font-weight: 700; color: var(--deep-navy); white-space: nowrap; }

        .bill-tag {
            display: inline-block;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .bill-tag.paid    { background: #ECFDF5; color: #047857; }
        .bill-tag.unpaid  { background: #FFFBEB; color: #B45309; }
        .bill-tag.overdue { background: #FEF2F2; color: #B91C1C; }

        @media (max-width: 900px) {
            .calendar-day { min-height: 64px; }
        }

        @media (max-width: 680px) {
            .sidebar { transform: translateX(-100%); }
            .main { margin-left: 0; }
            .page-body { padding: 20px; }
            .topbar { padding: 0 20px; }
            .calendar-weekdays div { font-size: 0.6rem; }
            .calendar-day { min-height: 48px; padding: 5px; }
            .day-number { font-size: 0.7rem; }
        }
    </style>
</head>
<body>
<div class="app">

    <aside class="sidebar">
        <div class="sidebar-logo">
            <img src="assets/images/logo.png" alt="AfterBill">
        </div>

        <nav class="sidebar-nav">
            <span class="nav-label">Menu</span>

            <a href="dashboard.php" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Dashboard
            </a>

            <a href="bills.php" class="nav-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/>
                </svg>
                My Bills
            </a>

            <a href="calendar.php" class="nav-item active">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
                </svg>
                Calendar
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar"><?= $userInitial ?></div>
                <div class="user-info">
                    <div class="user-name"><?= $userName ?></div>
                </div>
            </div>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2>Calendar</h2>
                <p>Your bill due dates at a glance.</p>
            </div>
            <div class="topbar-right">
                <a href="auth/logout.php" class="btn-logout">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                    </svg>
                    Logout
                </a>
            </div>
        </header>

        <main class="page-body">
            <div class="panel">
                <div class="calendar-header">
                    <h3><?= htmlspecialchars($monthStart->format('F Y')) ?></h3>
                    <div class="calendar-nav">
                        <a href="calendar.php?month=<?= urlencode($prevMonth) ?>" class="btn-nav" aria-label="Previous month">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                            </svg>
                        </a>
                        <a href="calendar.php?month=<?= urlencode($nextMonth) ?>" class="btn-nav" aria-label="Next month">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </a>
                    </div>
                </div>

                <div class="calendar-grid">
                    <div class="calendar-weekdays">
                        <div>Sun</div>
                        <div>Mon</div>
                        <div>Tue</div>
                        <div>Wed</div>
                        <div>Thu</div>
                        <div>Fri</div>
                        <div>Sat</div>
                    </div>

                    <?php foreach ($calendarWeeks as $week): ?>
                        <div class="calendar-week">
                            <?php foreach ($week as $cellDate): ?>
                                <?php if ($cellDate === null): ?>
                                    <div class="calendar-day blank"></div>
                                <?php else: ?>
                                    <?php
                                        $dayNum = (int)substr($cellDate, -2);
                                        $isToday = $cellDate === $today;
                                        $badge = $dayBadge[$cellDate] ?? null;
                                        $count = isset($billsByDay[$cellDate]) ? count($billsByDay[$cellDate]) : 0;
                                    ?>
                                    <?php if ($badge !== null): ?>
                                        <details class="calendar-day<?= $isToday ? ' today' : '' ?>">
                                            <summary>
                                                <div class="day-number"><?= $dayNum ?></div>
                                                <span class="day-badge <?= $badge ?>"><?= $count ?></span>
                                            </summary>
                                            <div class="day-popup">
                                                <div class="day-popup-title">
                                                    <?= htmlspecialchars((new DateTime($cellDate))->format('M j, Y')) ?>
                                                </div>
                                                <?php foreach ($billsByDay[$cellDate] as $dayBill): ?>
                                                    <?php
                                                        if ($dayBill['status'] === 'paid') {
                                                            $tagClass = 'paid';
                                                            $tagLabel = 'Paid';
                                                        } elseif ($cellDate < $today) {
                                                            $tagClass = 'overdue';
                                                            $tagLabel = 'Overdue';
                                                        } else {
                                                            $tagClass = 'unpaid';
                                                            $tagLabel = 'Unpaid';
                                                        }
                                                    ?>
                                                    <div class="day-popup-item">
                                                        <span class="popup-bill-name"><?= htmlspecialchars($dayBill['bill_name']) ?></span>
                                                        <span class="popup-bill-amount">₱<?= number_format((float)$dayBill['amount'], 2) ?></span>
                                                        <span class="bill-tag <?= $tagClass ?>"><?= $tagLabel ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </details>
                                    <?php else: ?>
                                        <div class="calendar-day<?= $isToday ? ' today' : '' ?>">
                                            <div class="day-number"><?= $dayNum ?></div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
    // Position a day's popup (fixed-position, so it isn't clipped by .panel's
    // overflow: hidden) at open time: below/left-aligned to the clicked cell by default,
    // flipped above and/or right-aligned if that would overflow the viewport. Measured
    // fresh on every open, not assumed from row/column position, so it works regardless
    // of how many weeks the month has or which cell was clicked.
    var OPEN_MARGIN = 6;

    document.querySelectorAll('.calendar-day').forEach(function (day) {
        if (day.tagName !== 'DETAILS') return;
        day.addEventListener('toggle', function () {
            if (!day.open) return;
            var summary = day.querySelector('summary');
            var popup = day.querySelector('.day-popup');
            if (!summary || !popup) return;

            var cellRect = summary.getBoundingClientRect();
            var popupHeight = popup.offsetHeight;
            var popupWidth = popup.offsetWidth;

            var top = cellRect.bottom + OPEN_MARGIN;
            if (top + popupHeight > window.innerHeight) {
                top = cellRect.top - popupHeight - OPEN_MARGIN;
            }

            var left = cellRect.left;
            if (left + popupWidth > window.innerWidth) {
                left = cellRect.right - popupWidth;
            }
            if (left < 0) left = OPEN_MARGIN;

            popup.style.top = top + 'px';
            popup.style.left = left + 'px';
        });
    });
</script>
</body>
</html>
