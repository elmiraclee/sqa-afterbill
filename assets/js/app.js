/**
 * AfterBill - Shared page behaviors
 *
 * The Content-Security-Policy (.htaccess) only allows script files from this site, so inline
 * handlers like onchange="..." / onsubmit="..." no longer run. Pages declare behavior with
 * data attributes instead:
 *   <select data-autosubmit>          submits its form when the value changes (filters)
 *   <form data-confirm="Message?">    asks for confirmation before submitting
 *   <input type="checkbox" data-check-all="name[]">   ticks / unticks every checkbox with that name
 */

document.addEventListener('change', function (e) {
    var el = e.target;
    if (el.matches && el.matches('[data-autosubmit]') && el.form) {
        el.form.submit();
    }
    if (el.matches && el.matches('[data-check-all]')) {
        var boxes = document.querySelectorAll('input[type="checkbox"][name="' + el.getAttribute('data-check-all') + '"]');
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].checked = el.checked;
        }
    }
});

document.addEventListener('submit', function (e) {
    var form = e.target;
    var message = form.getAttribute && form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        e.preventDefault();
    }
});
