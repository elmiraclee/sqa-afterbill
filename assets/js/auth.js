/**
 * AfterBill - Login & Registration form scripts (password toggle,
 * client-side validation, submit-loading state).
 */

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function clearErrors(form) {
    form.querySelectorAll('.field-error').forEach(el => el.remove());
    form.querySelectorAll('.input-wrapper.error, .form-check.error').forEach(el => {
        el.classList.remove('error');
    });
}

function showFieldError(form, fieldName, message) {
    const input = form.querySelector('[name="' + fieldName + '"]');
    if (!input) return;

    const wrapper = input.closest('.input-wrapper') || input.closest('.form-check') || input.parentElement;
    if (wrapper && wrapper.classList) {
        wrapper.classList.add('error');
    }

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

document.addEventListener('DOMContentLoaded', function () {
    // Password visibility toggles (shared by login + register)
    document.querySelectorAll('.toggle-password').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = this.closest('.input-wrapper').querySelector('input');
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';

            const eyeOpen = this.querySelector('.eye-open');
            const eyeClosed = this.querySelector('.eye-closed');
            if (eyeOpen && eyeClosed) {
                eyeOpen.style.display = isPassword ? 'none' : 'block';
                eyeClosed.style.display = isPassword ? 'block' : 'none';
            }
        });
    });

    // ── Login form ──
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            clearErrors(loginForm);
            const email = loginForm.email.value.trim();
            const password = loginForm.password.value;
            let valid = true;

            if (!isValidEmail(email)) {
                showFieldError(loginForm, 'email', 'Please enter a valid email address.');
                valid = false;
            }
            if (password === '') {
                showFieldError(loginForm, 'password', 'Password is required.');
                valid = false;
            }

            if (!valid) {
                e.preventDefault();
                return;
            }
            const btn = loginForm.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = 'Signing in...';
            }
        });
    }

    // ── Registration form ──
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', function (e) {
            clearErrors(registerForm);

            const fullName = registerForm.full_name.value.trim();
            const email = registerForm.email.value.trim();
            const password = registerForm.password.value;
            const confirm = registerForm.confirm_password.value;
            const terms = registerForm.terms.checked;

            let valid = true;

            if (fullName.length < 2) {
                showFieldError(registerForm, 'full_name', 'Please enter your full name.');
                valid = false;
            }
            if (!isValidEmail(email)) {
                showFieldError(registerForm, 'email', 'Please enter a valid email address.');
                valid = false;
            }
            if (password.length < 8) {
                showFieldError(registerForm, 'password', 'Password must be at least 8 characters.');
                valid = false;
            }
            if (password !== confirm) {
                showFieldError(registerForm, 'confirm_password', 'Passwords do not match.');
                valid = false;
            }
            if (!terms) {
                showFieldError(registerForm, 'terms', 'You must agree to the Terms of Service and Privacy Policy.');
                valid = false;
            }

            if (!valid) {
                e.preventDefault();
                return;
            }
            const btn = registerForm.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = 'Creating account...';
            }
        });
    }
});
