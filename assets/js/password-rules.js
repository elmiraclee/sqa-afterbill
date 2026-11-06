/**
 * AfterBill - Live password rules checklist
 *
 * Works with password_rules_checklist() in includes/password_policy.php: each <li> carries the
 * same regex the server uses, so this file holds no rules of its own.
 */

(function () {
    function rulesFor(list) {
        return Array.prototype.map.call(list.querySelectorAll('li[data-pattern]'), function (li) {
            return { li: li, re: new RegExp(li.getAttribute('data-pattern')) };
        });
    }

    /** True when `value` passes every rule in the checklist for `inputId`. */
    window.passwordMeetsRules = function (inputId, value) {
        var list = document.querySelector('.pw-rules[data-for="' + inputId + '"]');
        if (!list) return true;
        return rulesFor(list).every(function (r) { return r.re.test(value); });
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.pw-rules[data-for]').forEach(function (list) {
            var input = document.getElementById(list.getAttribute('data-for'));
            if (!input) return;
            var rules = rulesFor(list);

            function update() {
                rules.forEach(function (r) {
                    r.li.classList.toggle('met', r.re.test(input.value));
                });
            }

            input.addEventListener('input', update);
            update();
        });
    });
})();
