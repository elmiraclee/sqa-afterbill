/**
 * AfterBill - Registration Form Scripts
 */

document.addEventListener('DOMContentLoaded', function () {
    // Password visibility toggles
    const toggles = document.querySelectorAll('.toggle-password');

    toggles.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = this.closest('.input-wrapper').querySelector('input');
            const isPassword = input.type === 'password';

            input.type = isPassword ? 'text' : 'password';

            // Swap icon (eye / eye-off)
            const eyeOpen = this.querySelector('.eye-open');
            const eyeClosed = this.querySelector('.eye-closed');

            if (eyeOpen && eyeClosed) {
                eyeOpen.style.display = isPassword ? 'none' : 'block';
                eyeClosed.style.display = isPassword ? 'block' : 'none';
            }
        });
    });

    // Client-side validation before submit
    const form = document.getElementById('registerForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        clearErrors();

        const fullName = form.full_name.value.trim();
        const email = form.email.value.trim();
        const password = form.password.value;
        const confirm = form.confirm_password.value;
        const terms = form.terms.checked;

        let valid = true;

        if (fullName.length < 2) {
            showFieldError('full_name', 'Please enter your full name.');
            valid = false;
        }

        if (!isValidEmail(email)) {
            showFieldError('email', 'Please enter a valid email address.');
            valid = false;
        }

        if (password.length < 8) {
            showFieldError('password', 'Password must be at least 8 characters.');
            valid = false;
        }

        if (password !== confirm) {
            showFieldError('confirm_password', 'Passwords do not match.');
            valid = false;
        }

        if (!terms) {
            showFieldError('terms', 'You must agree to the Terms of Service and Privacy Policy.');
            valid = false;
        }

        if (!valid) {
            e.preventDefault();
        } else {
            // Disable button to prevent double submit
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = 'Creating account...';
            }
        }
    });

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    function showFieldError(fieldName, message) {
        const input = form.querySelector('[name="' + fieldName + '"]');
        if (!input) return;

        const wrapper = input.closest('.input-wrapper') || input.closest('.form-check') || input.parentElement;
        if (wrapper && wrapper.classList) {
            wrapper.classList.add('error');
        }

        // Create or update error message
        let errorEl = form.querySelector('[data-error-for="' + fieldName + '"]');
        if (!errorEl) {
            errorEl = document.createElement('div');
            errorEl.className = 'field-error';
            errorEl.setAttribute('data-error-for', fieldName);
            if (wrapper) {
                wrapper.parentNode.insertBefore(errorEl, wrapper.nextSibling);
            }
        }
        errorEl.textContent = message;
    }

    function clearErrors() {
        form.querySelectorAll('.field-error').forEach(el => el.remove());
        form.querySelectorAll('.input-wrapper.error, .form-check.error').forEach(el => {
            el.classList.remove('error');
        });
    }
});