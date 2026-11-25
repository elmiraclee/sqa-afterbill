/**
 * AfterBill - Mobile navigation (loaded by partials/sidebar.php on every app page)
 *
 * Below 680px the sidebar is off-screen. The menu button slides it in over the page; tapping the
 * backdrop, a menu link, or pressing Escape closes it again.
 */
(function () {
    var toggle = document.querySelector('.nav-toggle');
    var sidebar = document.getElementById('app-sidebar');
    var backdrop = document.querySelector('.nav-backdrop');
    if (!toggle || !sidebar) {
        return;
    }

    function setOpen(open) {
        sidebar.classList.toggle('open', open);
        document.body.classList.toggle('nav-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        if (open) {
            var first = sidebar.querySelector('a, button');
            if (first) {
                first.focus();
            }
        }
    }

    toggle.addEventListener('click', function () {
        setOpen(!sidebar.classList.contains('open'));
    });
    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setOpen(false);
        });
    }
    sidebar.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('a')) {
            setOpen(false);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) {
            setOpen(false);
            toggle.focus();
        }
    });
    // Rotating to a wide screen: the sidebar is always visible there, so drop the open state.
    window.addEventListener('resize', function () {
        if (window.innerWidth > 680 && sidebar.classList.contains('open')) {
            setOpen(false);
        }
    });
})();
