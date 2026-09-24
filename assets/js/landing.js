/**
 * AfterBill - Landing page scroll-reveal.
 * Progressive enhancement only: .reveal elements are visible by default
 * in CSS, this just adds a subtle fade/slide-in as they enter view.
 */

document.addEventListener('DOMContentLoaded', function () {
    var items = document.querySelectorAll('.reveal');
    if (!items.length || !('IntersectionObserver' in window)) {
        return;
    }

    document.documentElement.classList.add('js-ready');

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('in-view');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

    items.forEach(function (el, i) {
        el.style.transitionDelay = (i % 3) * 80 + 'ms';
        observer.observe(el);
    });
});
