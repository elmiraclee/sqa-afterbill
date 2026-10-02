<?php
/**
 * AfterBill - User Registration
 * Uses PHP PDO
 */

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: ../dashboard.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/activity.php';

$errors = [];
$success = false;
$old = [
    'full_name' => '',
    'email'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $terms    = isset($_POST['terms']);

    $old['full_name'] = $fullName;
    $old['email']     = $email;

    if (!csrf_valid()) {
        $errors[] = CSRF_ERROR;
    }
    // Length limits match the users columns (varchar 120 / 180) and account.php, so nothing is
    // silently truncated by the database.
    if (mb_strlen($fullName) < 2) {
        $errors[] = 'Full name must be at least 2 characters.';
    } elseif (mb_strlen($fullName) > 120) {
        $errors[] = 'Full name must be 120 characters or fewer.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (mb_strlen($email) > 180) {
        $errors[] = 'Email must be 180 characters or fewer.';
    }
    // 72 bytes is bcrypt's limit: password_hash() would silently ignore anything past it.
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif (strlen($password) > 72) {
        $errors[] = 'Password must be 72 characters or fewer.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$terms) {
        $errors[] = 'You must agree to the Terms of Service and Privacy Policy.';
    }

    if (empty($errors)) {
        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = 'This email is already registered. Please log in instead.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Something went wrong. Please try again later.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo = getPDO();
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                'INSERT INTO users (full_name, email, password, created_at) VALUES (?, ?, ?, NOW())'
            );
            $stmt->execute([$fullName, $email, $hashed]);

            // Auto-login: set session and redirect to dashboard
            $newId = $pdo->lastInsertId();
            session_regenerate_id(true); // same session-fixation guard as login.php
            $_SESSION['user_id']   = $newId;
            $_SESSION['user_name'] = $fullName;
            logActivity($pdo, (int)$newId, 'register');
            header('Location: ../dashboard.php');
            exit;
        } catch (PDOException $e) {
            $errors[] = 'Registration failed. Please try again later.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account – AfterBill</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
    <div class="auth-page">
        <div class="auth-card">
            <!-- Left Brand Panel -->
            <div class="auth-brand">
                <div class="brand-top">
                    <div class="brand-tagline">Monthly Bill Tracker</div>

                    <h1 class="brand-headline">Stay on top of<br>your bills, <span>always.</span></h1>
                    <p class="brand-sub">Track. Organize. Pay. All in one place.</p>
                </div>

                <!-- Icon illustration (pure SVG – no raster image) -->
                <div class="brand-art brand-art--register" aria-hidden="true">
                    <svg viewBox="0 0 336 200" xmlns="http://www.w3.org/2000/svg" role="presentation" focusable="false">
                        <defs>
                            <linearGradient id="abGlow" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#3B82F6" stop-opacity=".45"/>
                                <stop offset="100%" stop-color="#3B82F6" stop-opacity="0"/>
                            </linearGradient>
                            <linearGradient id="abCard" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#FFFFFF"/>
                                <stop offset="100%" stop-color="#E9EFFB"/>
                            </linearGradient>
                        </defs>

                        <!-- soft backdrop -->
                        <circle cx="150" cy="118" r="112" fill="url(#abGlow)"/>

                        <!-- calendar -->
                        <g class="art-calendar">
                            <rect x="26" y="34" width="176" height="176" rx="20" fill="url(#abCard)"/>
                            <path d="M26 54a20 20 0 0 1 20-20h136a20 20 0 0 1 20 20v24H26z" fill="#3B82F6"/>
                            <rect x="66" y="18" width="12" height="30" rx="6" fill="#E9EFFB"/>
                            <rect x="150" y="18" width="12" height="30" rx="6" fill="#E9EFFB"/>

                            <!-- date grid -->
                            <g fill="#CBD9F2">
                                <rect x="50" y="96" width="24" height="20" rx="6"/>
                                <rect x="86" y="96" width="24" height="20" rx="6"/>
                                <rect x="122" y="96" width="24" height="20" rx="6"/>
                                <rect x="158" y="96" width="20" height="20" rx="6"/>
                                <rect x="50" y="128" width="24" height="20" rx="6"/>
                                <rect x="122" y="128" width="24" height="20" rx="6"/>
                                <rect x="158" y="128" width="20" height="20" rx="6"/>
                                <rect x="50" y="160" width="24" height="20" rx="6"/>
                                <rect x="86" y="160" width="24" height="20" rx="6"/>
                                <rect x="122" y="160" width="24" height="20" rx="6"/>
                                <rect x="158" y="160" width="20" height="20" rx="6"/>
                            </g>
                            <!-- highlighted due date -->
                            <rect x="86" y="128" width="24" height="20" rx="6" fill="#3B82F6"/>
                        </g>

                        <!-- bill / receipt -->
                        <g class="art-receipt" transform="translate(174 102) rotate(-7)">
                            <path d="M0 16A16 16 0 0 1 16 0h116a16 16 0 0 1 16 16v100l-14.8 8-14.8-8-14.8 8-14.8-8-14.8 8-14.8-8-14.8 8-14.8-8L0 124z"
                                  fill="#FFFFFF"/>
                            <rect x="20" y="24" width="62" height="11" rx="5.5" fill="#17233C"/>
                            <rect x="20" y="43" width="40" height="8" rx="4" fill="#C7D5EE"/>

                            <g>
                                <circle cx="26" cy="72" r="8" fill="#3B82F6"/>
                                <path d="M22.5 72l2.6 2.7 4.6-5" fill="none" stroke="#FFFFFF" stroke-width="2.2"
                                      stroke-linecap="round" stroke-linejoin="round"/>
                                <rect x="42" y="67" width="86" height="10" rx="5" fill="#E4EBF8"/>
                            </g>
                            <g>
                                <circle cx="26" cy="96" r="8" fill="#3B82F6"/>
                                <path d="M22.5 96l2.6 2.7 4.6-5" fill="none" stroke="#FFFFFF" stroke-width="2.2"
                                      stroke-linecap="round" stroke-linejoin="round"/>
                                <rect x="42" y="91" width="64" height="10" rx="5" fill="#E4EBF8"/>
                            </g>
                        </g>

                        <!-- paid badge -->
                        <g class="art-badge">
                            <circle cx="292" cy="62" r="28" fill="#3B82F6"/>
                            <circle cx="292" cy="62" r="28" fill="none" stroke="#FFFFFF" stroke-opacity=".35" stroke-width="2"/>
                            <path d="M281 62.5l7.5 7.5L304 55" fill="none" stroke="#FFFFFF" stroke-width="4.5"
                                  stroke-linecap="round" stroke-linejoin="round"/>
                        </g>

                        <!-- floating accents -->
                        <circle class="art-dot art-dot-a" cx="34" cy="226" r="6" fill="#3B82F6"/>
                        <circle class="art-dot art-dot-b" cx="318" cy="168" r="9" fill="#FFFFFF" fill-opacity=".28"/>
                        <circle class="art-dot art-dot-c" cx="246" cy="22" r="5" fill="#FFFFFF" fill-opacity=".45"/>
                    </svg>
                </div>

                <ul class="brand-features">
                    <li class="feature-item">
                        <span class="feature-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                            </svg>
                        </span>
                        Due dates in one calendar
                    </li>
                    <li class="feature-item">
                        <span class="feature-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                            </svg>
                        </span>
                        Reminders before you're late
                    </li>
                    <li class="feature-item">
                        <span class="feature-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                            </svg>
                        </span>
                        See where your money goes
                    </li>
                </ul>
            </div>

            <!-- Right Form Panel -->
            <div class="auth-form-panel">
                <div class="auth-form-inner">
                    <div class="form-logo">
                        <a href="../index.php" title="Back to home"><img src="../assets/images/logo.png" alt="AfterBill"></a>
                    </div>
                    <div class="form-header">
                        <h1>Create Account</h1>
                        <p>Fill in your details to get started.</p>
                    </div>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            Account created successfully! You can now <a href="login.php">log in</a>.
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-error">
                            <strong>Please fix the following:</strong>
                            <ul>
                                <?php foreach ($errors as $err): ?>
                                    <li><?= htmlspecialchars($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!$success): ?>
                    <form id="registerForm" method="POST" action="" novalidate>
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                </svg>
                                <input type="text" id="full_name" name="full_name" placeholder="Juan Dela Cruz"
                                    value="<?= htmlspecialchars($old['full_name']) ?>" required autocomplete="name">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                </svg>
                                <input type="email" id="email" name="email" placeholder="you@example.com"
                                    value="<?= htmlspecialchars($old['email']) ?>" required autocomplete="email">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                                <input type="password" id="password" name="password" placeholder="Create a password"
                                    required minlength="8" autocomplete="new-password">
                                <button type="button" class="toggle-password" aria-label="Toggle password">
                                    <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <svg class="eye-closed" style="display:none;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                                <input type="password" id="confirm_password" name="confirm_password"
                                    placeholder="Confirm your password" required minlength="8" autocomplete="new-password">
                                <button type="button" class="toggle-password" aria-label="Toggle password">
                                    <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <svg class="eye-closed" style="display:none;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="form-check">
                            <input type="checkbox" id="terms" name="terms" value="1" required>
                            <label for="terms">
                                I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>
                            </label>
                        </div>

                        <button type="submit" class="btn-primary">
                            Create Account
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                            </svg>
                        </button>
                    </form>
                    <?php endif; ?>

                    <div class="form-footer">
                        Already have an account? <a href="login.php">Log in</a><br><a href="../index.php" style="font-weight:500;color:#6B7280;">&larr; Back to home</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/register.js"></script>
</body>
</html>