/**
 * AfterBill - Shared app shell behavior (sidebar, modals, confirms).
 */

document.addEventListener('DOMContentLoaded', function () {
    // Mobile sidebar toggle
    var toggle = document.querySelector('.menu-toggle');
    var sidebar = document.querySelector('.sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            sidebar.classList.toggle('open');
        });
        document.addEventListener('click', function (e) {
            if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // Generic modal open/close via [data-open-modal] / [data-close-modal]
    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-open-modal');
            var modal = document.getElementById(id);
            if (modal) modal.classList.add('open');
        });
    });

    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modal = btn.closest('.modal-overlay');
            if (modal) modal.classList.remove('open');
        });
    });

    document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) overlay.classList.remove('open');
        });
    });

    // Confirm before destructive actions
    document.querySelectorAll('[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var message = form.getAttribute('data-confirm') || 'Are you sure?';
            if (!confirm(message)) {
                e.preventDefault();
            } else {
                var btn = form.querySelector('button[type="submit"]');
                if (btn) btn.disabled = true;
            }
        });
    });

    // Password visibility toggles (shared with auth pages)
    document.querySelectorAll('.toggle-password').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = btn.closest('.input-wrapper').querySelector('input');
            var isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            var eyeOpen = btn.querySelector('.eye-open');
            var eyeClosed = btn.querySelector('.eye-closed');
            if (eyeOpen && eyeClosed) {
                eyeOpen.style.display = isPassword ? 'none' : 'block';
                eyeClosed.style.display = isPassword ? 'block' : 'none';
            }
        });
    });

    // Auto-dismiss flash alerts after a few seconds
    document.querySelectorAll('.page-alert').forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.4s';
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 400);
        }, 5000);
    });
});

/** Opens the Add/Edit Bill modal pre-filled for editing. Called from inline onclick. */
function openEditBillModal(bill) {
    var form = document.getElementById('billForm');
    if (!form) return;

    document.getElementById('modalTitle').textContent = 'Edit Bill';
    document.getElementById('billFormAction').value = 'update';
    document.getElementById('billIdField').value = bill.id;
    document.getElementById('name').value = bill.name;
    document.getElementById('category').value = bill.category;
    document.getElementById('amount').value = bill.amount;
    document.getElementById('due_date').value = bill.due_date;
    document.getElementById('frequency').value = bill.frequency;
    document.getElementById('notes').value = bill.notes || '';
    document.getElementById('reminder_at').value = bill.reminder_at ? bill.reminder_at.replace(' ', 'T').substring(0, 16) : '';

    document.getElementById('billModal').classList.add('open');
}

function openAddBillModal(prefillDate) {
    var form = document.getElementById('billForm');
    if (!form) return;

    document.getElementById('modalTitle').textContent = 'Add Bill';
    form.reset();
    document.getElementById('billFormAction').value = 'create';
    document.getElementById('billIdField').value = '';
    if (prefillDate) {
        document.getElementById('due_date').value = prefillDate;
    }

    document.getElementById('billModal').classList.add('open');
}
