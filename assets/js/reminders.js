/**
 * AfterBill - Desktop reminder alerts (moved out of reminders.php for the Content-Security-Policy)
 * The alert text comes from #notifyBtn[data-summary], rendered by reminders.php.
 */

(function () {
    var btn = document.getElementById('notifyBtn');
    if (!btn) return;
    var summary = btn.getAttribute('data-summary') || '';
    var status = document.getElementById('notifyStatus');

    if (!('Notification' in window)) {
        btn.style.display = 'none';
        return;
    }

    function notify() {
        if (summary) {
            new Notification('AfterBill reminders', { body: 'You have ' + summary + '.' });
        }
    }

    function refresh() {
        if (Notification.permission === 'granted') {
            btn.style.display = 'none';
            status.textContent = 'Desktop alerts are on.';
        } else if (Notification.permission === 'denied') {
            btn.style.display = 'none';
            status.textContent = 'Desktop alerts are blocked in your browser settings.';
        }
    }

    btn.addEventListener('click', function () {
        Notification.requestPermission().then(function (p) {
            refresh();
            if (p === 'granted') notify();
        });
    });

    refresh();
    // Once per browser session, so navigating back here doesn't spam alerts.
    try {
        if (Notification.permission === 'granted' && !sessionStorage.getItem('abNotified')) {
            sessionStorage.setItem('abNotified', '1');
            notify();
        }
    } catch (e) {}
})();
