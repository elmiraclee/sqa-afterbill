/**
 * AfterBill - Shared page behaviors
 *
 * The Content-Security-Policy (.htaccess) only allows script files from this site, so inline
 * handlers like onchange="..." / onsubmit="..." no longer run. Pages declare behavior with
 * data attributes instead:
 *   <select data-autosubmit>          submits its form when the value changes (filters)
 *   <form data-confirm="Message?">    asks for confirmation before submitting
 */

document.addEventListener('change', function (e) {
    var el = e.target;
    if (el.matches && el.matches('[data-autosubmit]') && el.form) {
        el.form.submit();
    }
});

document.addEventListener('submit', function (e) {
    var form = e.target;
    var message = form.getAttribute && form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        e.preventDefault();
    }
});
