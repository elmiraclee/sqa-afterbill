<?php
/**
 * AfterBill - Landing Page
 */

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AfterBill – Take Control of Your Monthly Bills</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/landing.css">
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a href="index.php" class="site-logo">
                <img src="assets/images/logo.png" alt="AfterBill">
            </a>
            <nav class="site-nav">
                <a href="#how-it-works">How It Works</a>
                <a href="#features">Features</a>
                <a href="#faq">FAQ</a>
            </nav>
            <div class="header-actions">
                <a href="auth/login.php" class="btn btn-ghost"><span>Log In</span></a>
                <a href="auth/register.php" class="btn btn-primary">
                    <span>Get Started</span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                    </svg>
                </a>
            </div>
        </div>
    </header>

    <section class="hero">
        <div class="hero-inner">
            <div class="hero-copy">
                <span class="hero-tagline">Monthly Bill Tracker</span>
                <h1 class="hero-headline">Take control of<br>your monthly <span>bills.</span></h1>
                <p class="hero-sub">AfterBill helps you track due dates, get reminders before you're late, and see exactly where your money goes — all in one place.</p>

                <div class="hero-cta">
                    <a href="auth/register.php" class="btn btn-primary">
                        Get Started Free
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </a>
                    <a href="auth/login.php" class="btn btn-ghost">I already have an account</a>
                </div>

                <div class="hero-trust">
                    <div class="trust-item">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Free to use
                    </div>
                    <div class="trust-item">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                        Your data stays private
                    </div>
                    <div class="trust-item">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Set up in minutes
                    </div>
                </div>
            </div>

            <div class="hero-art-wrap">
            <div class="hero-art-panel" aria-hidden="true">
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

                    <circle cx="150" cy="118" r="112" fill="url(#abGlow)"/>

                    <g class="art-calendar">
                        <rect x="26" y="34" width="176" height="176" rx="20" fill="url(#abCard)"/>
                        <path d="M26 54a20 20 0 0 1 20-20h136a20 20 0 0 1 20 20v24H26z" fill="#3B82F6"/>
                        <rect x="66" y="18" width="12" height="30" rx="6" fill="#E9EFFB"/>
                        <rect x="150" y="18" width="12" height="30" rx="6" fill="#E9EFFB"/>

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
                        <rect x="86" y="128" width="24" height="20" rx="6" fill="#3B82F6"/>
                    </g>

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

                    <g class="art-badge">
                        <circle cx="292" cy="62" r="28" fill="#3B82F6"/>
                        <circle cx="292" cy="62" r="28" fill="none" stroke="#FFFFFF" stroke-opacity=".35" stroke-width="2"/>
                        <path d="M281 62.5l7.5 7.5L304 55" fill="none" stroke="#FFFFFF" stroke-width="4.5"
                              stroke-linecap="round" stroke-linejoin="round"/>
                    </g>

                    <circle class="art-dot art-dot-a" cx="34" cy="226" r="6" fill="#3B82F6"/>
                    <circle class="art-dot art-dot-b" cx="318" cy="168" r="9" fill="#FFFFFF" fill-opacity=".28"/>
                    <circle class="art-dot art-dot-c" cx="246" cy="22" r="5" fill="#FFFFFF" fill-opacity=".45"/>
                </svg>
            </div>

            <div class="hero-preview-card" aria-hidden="true">
                <div class="preview-card-label">This Month</div>
                <div class="preview-card-row">
                    <span class="preview-dot upcoming"></span> Upcoming <strong>3</strong>
                </div>
                <div class="preview-card-amount">₱4,250.00</div>
                <span class="preview-card-pill">On Track</span>
            </div>
            </div>
        </div>
    </section>

    <section class="how-it-works" id="how-it-works">
        <div class="how-it-works-inner">
            <div class="features-heading">
                <h2>How AfterBill works</h2>
                <p>Three simple steps to stop guessing when your next bill is due.</p>
            </div>

            <div class="steps-grid">
                <div class="step-card reveal">
                    <span class="step-number">1</span>
                    <h3>Add your bills</h3>
                    <p>Enter your electricity, rent, subscriptions, and more — with amount, category, and due date.</p>
                </div>
                <div class="step-card reveal">
                    <span class="step-number">2</span>
                    <h3>Get timely reminders</h3>
                    <p>AfterBill automatically flags bills that are due soon or already overdue, right on your dashboard.</p>
                </div>
                <div class="step-card reveal">
                    <span class="step-number">3</span>
                    <h3>Stay on top of spending</h3>
                    <p>Mark bills as paid, track totals by category, and see your monthly spending trend at a glance.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="features" id="features">
        <div class="features-inner">
            <div class="features-heading">
                <h2>Everything you need to stay ahead</h2>
                <p>A simple way to organize every bill you're responsible for.</p>
            </div>

            <div class="features-grid">
                <div class="feature-card reveal">
                    <div class="icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                        </svg>
                    </div>
                    <h3>Due dates in one calendar</h3>
                    <p>See every upcoming bill laid out on a single calendar so nothing slips through the cracks.</p>
                </div>

                <div class="feature-card reveal">
                    <div class="icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                        </svg>
                    </div>
                    <h3>Reminders before you're late</h3>
                    <p>Get notified ahead of time so you can pay on schedule and avoid late fees.</p>
                </div>

                <div class="feature-card reveal">
                    <div class="icon-box">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.9" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                        </svg>
                    </div>
                    <h3>See where your money goes</h3>
                    <p>Track spending across all your bills and get a clear picture of your monthly obligations.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="faq" id="faq">
        <div class="faq-inner">
            <div class="features-heading">
                <h2>Frequently asked questions</h2>
                <p>Everything you might be wondering before you get started.</p>
            </div>

            <details class="faq-item">
                <summary>
                    Is AfterBill free to use?
                    <svg class="faq-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </summary>
                <p>Yes. Creating an account and tracking your bills on AfterBill doesn't cost anything.</p>
            </details>

            <details class="faq-item">
                <summary>
                    Is my data kept private?
                    <svg class="faq-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </summary>
                <p>Your password is securely hashed, and every bill you add is tied only to your account — no one else can view or edit it.</p>
            </details>

            <details class="faq-item">
                <summary>
                    What kinds of bills can I track?
                    <svg class="faq-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </summary>
                <p>Electricity, water, internet, phone, rent, insurance, subscriptions, transportation, or anything else under "Other" — one-time or recurring.</p>
            </details>

            <details class="faq-item">
                <summary>
                    Do I need to install anything?
                    <svg class="faq-chevron" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                </summary>
                <p>No. AfterBill runs entirely in your browser — just log in from any device to see your bills.</p>
            </details>
        </div>
    </section>

    <section class="cta-band">
        <div class="cta-band-inner">
            <h2>Ready to get on top of your bills?</h2>
            <p>Create your free AfterBill account and start tracking in minutes.</p>
            <a href="auth/register.php" class="btn btn-primary">
                Create Free Account
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>
    </section>

    <footer class="site-footer">
        <div class="footer-inner">
            <div class="footer-top">
                <div class="footer-brand">
                    <img src="assets/images/logo.png" alt="AfterBill">
                    <p>A simple way to track, manage, and stay ahead of every monthly bill.</p>
                </div>
                <div class="footer-cols">
                    <div class="footer-col">
                        <h4>Product</h4>
                        <a href="#how-it-works">How It Works</a>
                        <a href="#features">Features</a>
                        <a href="#faq">FAQ</a>
                    </div>
                    <div class="footer-col">
                        <h4>Account</h4>
                        <a href="auth/login.php">Log In</a>
                        <a href="auth/register.php">Create Account</a>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                &copy; <?= date('Y') ?> AfterBill. All rights reserved.
            </div>
        </div>
    </footer>

    <script src="assets/js/landing.js"></script>
</body>
</html>
