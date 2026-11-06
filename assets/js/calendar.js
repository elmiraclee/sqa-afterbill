/**
 * AfterBill - Calendar day popups (moved out of calendar.php for the Content-Security-Policy)
 */

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
