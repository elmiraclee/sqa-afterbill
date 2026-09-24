<?php
/**
 * AfterBill - Landing Page
 */

session_start();

$loggedIn = isset($_SESSION['user_id']);
$firstName = $loggedIn ? htmlspecialchars(explode(' ', $_SESSION['user_name'] ?? 'there')[0]) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AfterBill – Monthly Bill Tracker</title>
    <meta name="description" content="AfterBill helps you track monthly bills, see due dates on a calendar, get reminders before you're late, and understand where your money goes.">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --deep-navy: #17233C;
            --navy-2: #0F172A;
            --electric-blue: #3B82F6;
            --electric-blue-hover: #2563EB;
            --white: #FFFFFF;
            --light-gray: #F3F4F6;
            --text-dark: #1F2937;
            --text-muted: #6B7280;
            --border: #E5E7EB;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
        }

        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--white);
            color: var(--text-dark);
            line-height: 1.6;
        }
        a { color: inherit; }
        .container { width: 100%; max-width: 1120px; margin: 0 auto; padding: 0 24px; }

        /* ── Nav ── */
        .nav {
            position: sticky; top: 0; z-index: 50;
            background: rgba(23, 35, 60, 0.92);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .nav-inner { display: flex; align-items: center; justify-content: space-between; height: 68px; }
        .nav-logo img { height: 40px; width: auto; display: block; filter: brightness(0) invert(1); }
        .nav-links { display: flex; align-items: center; gap: 28px; }
        .nav-links a { color: rgba(255,255,255,0.72); text-decoration: none; font-size: 0.88rem; font-weight: 500; }
        .nav-links a:hover { color: #fff; }
        .nav-actions { display: flex; gap: 10px; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 11px 22px; border-radius: 10px;
            font-size: 0.9rem; font-weight: 600; font-family: inherit;
            text-decoration: none; cursor: pointer; border: 1.5px solid transparent;
            transition: background 0.18s, border-color 0.18s, color 0.18s, transform 0.18s;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn svg { width: 17px; height: 17px; }
        .btn-primary { background: var(--electric-blue); color: #fff; }
        .btn-primary:hover { background: var(--electric-blue-hover); }
        .btn-ghost { color: #fff; border-color: rgba(255,255,255,0.25); }
        .btn-ghost:hover { border-color: #fff; }
        .btn-sm { padding: 8px 16px; font-size: 0.84rem; }

        /* ── Hero ── */
        .hero {
            background:
                radial-gradient(ellipse at 85% 10%, rgba(59,130,246,0.35) 0%, transparent 50%),
                radial-gradient(ellipse at 0% 100%, rgba(59,130,246,0.15) 0%, transparent 45%),
                linear-gradient(180deg, var(--deep-navy) 0%, var(--navy-2) 100%);
            color: #fff;
            padding: 84px 0 96px;
            overflow: hidden;
        }
        .hero-grid { display: grid; grid-template-columns: 1.05fr 1fr; gap: 56px; align-items: center; }
        .eyebrow {
            display: inline-block; padding: 6px 14px; border-radius: 999px;
            background: rgba(59,130,246,0.18); border: 1px solid rgba(59,130,246,0.4);
            font-size: 0.75rem; font-weight: 600; letter-spacing: 0.6px; text-transform: uppercase;
            color: #BFDBFE; margin-bottom: 20px;
        }
        .hero h1 { font-size: clamp(2.1rem, 4.6vw, 3.4rem); line-height: 1.12; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 18px; }
        .hero h1 span { color: var(--electric-blue); }
        .hero p.lead { font-size: 1.08rem; color: rgba(255,255,255,0.72); max-width: 520px; margin-bottom: 32px; }
        .hero-cta { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 28px; }
        .hero-points { display: flex; flex-wrap: wrap; gap: 20px; font-size: 0.84rem; color: rgba(255,255,255,0.6); list-style: none; }
        .hero-points li::before { content: '✓'; color: var(--success); font-weight: 700; margin-right: 6px; }

        /* Hero mock dashboard */
        .mock {
            background: var(--white); color: var(--text-dark);
            border-radius: 18px; padding: 20px;
            box-shadow: 0 30px 60px -15px rgba(0,0,0,0.5);
            transform: rotate(1.5deg);
        }
        .mock-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .mock-head strong { font-size: 0.95rem; color: var(--deep-navy); }
        .mock-head span { font-size: 0.72rem; color: var(--text-muted); }
        .mock-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
        .mock-stat { background: var(--light-gray); border-radius: 10px; padding: 10px 12px; }
        .mock-stat b { display: block; font-size: 1rem; color: var(--deep-navy); }
        .mock-stat small { font-size: 0.68rem; color: var(--text-muted); }
        .mock-row { display: flex; align-items: center; gap: 12px; padding: 10px 4px; border-top: 1px solid var(--border); }
        .mock-dot { width: 32px; height: 32px; border-radius: 9px; display: grid; place-items: center; font-size: 0.9rem; flex-shrink: 0; }
        .mock-row .name { flex: 1; font-size: 0.82rem; font-weight: 600; }
        .mock-row .name small { display: block; font-weight: 400; color: var(--text-muted); font-size: 0.7rem; }
        .mock-row .amt { font-size: 0.85rem; font-weight: 700; color: var(--deep-navy); }
        .pill { font-size: 0.66rem; font-weight: 600; padding: 3px 8px; border-radius: 999px; }
        .pill.paid { background: #ECFDF5; color: #047857; }
        .pill.due { background: #FFFBEB; color: #B45309; }
        .pill.late { background: #FEF2F2; color: #B91C1C; }

        /* ── Sections ── */
        section.block { padding: 88px 0; }
        section.alt { background: var(--light-gray); }
        .section-head { text-align: center; max-width: 620px; margin: 0 auto 52px; }
        .section-head .kicker { font-size: 0.78rem; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--electric-blue); margin-bottom: 10px; }
        .section-head h2 { font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 800; color: var(--deep-navy); letter-spacing: -0.01em; margin-bottom: 12px; line-height: 1.2; }
        .section-head p { color: var(--text-muted); font-size: 1rem; }

        .features { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
        .feature {
            background: var(--white); border: 1px solid var(--border); border-radius: 16px; padding: 26px;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .feature:hover { box-shadow: 0 12px 28px rgba(23,35,60,0.08); transform: translateY(-2px); }
        .feature-icon {
            width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center;
            background: rgba(59,130,246,0.1); color: var(--electric-blue); margin-bottom: 16px;
        }
        .feature-icon svg { width: 23px; height: 23px; }
        .feature h3 { font-size: 1.02rem; color: var(--deep-navy); margin-bottom: 6px; }
        .feature p { font-size: 0.88rem; color: var(--text-muted); }

        .steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; counter-reset: step; }
        .step { position: relative; padding-top: 8px; }
        .step-num {
            width: 42px; height: 42px; border-radius: 50%;
            background: var(--deep-navy); color: #fff; font-weight: 800;
            display: grid; place-items: center; margin-bottom: 16px;
        }
        .step h3 { font-size: 1.02rem; color: var(--deep-navy); margin-bottom: 6px; }
        .step p { font-size: 0.9rem; color: var(--text-muted); }

        .cta {
            background: linear-gradient(135deg, var(--deep-navy) 0%, #1E3A5F 100%);
            border-radius: 22px; padding: 56px 40px; text-align: center; color: #fff;
            position: relative; overflow: hidden;
        }
        .cta::before {
            content: ''; position: absolute; top: -80px; right: -80px; width: 280px; height: 280px;
            background: radial-gradient(circle, rgba(59,130,246,0.35) 0%, transparent 70%); border-radius: 50%;
        }
        .cta h2 { font-size: clamp(1.5rem, 3vw, 2rem); font-weight: 800; margin-bottom: 10px; position: relative; }
        .cta p { color: rgba(255,255,255,0.7); margin-bottom: 26px; position: relative; }
        .cta .btn { position: relative; }

        footer { padding: 32px 0; border-top: 1px solid var(--border); }
        .footer-inner { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; font-size: 0.84rem; color: var(--text-muted); }
        .footer-inner img { height: 30px; }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .hero-grid { grid-template-columns: 1fr; }
            .mock { transform: none; max-width: 520px; }
            .features { grid-template-columns: 1fr 1fr; }
            .steps { grid-template-columns: 1fr; }
            .nav-links { display: none; }
        }
        @media (max-width: 600px) {
            .container { padding: 0 16px; }
            .hero { padding: 56px 0 64px; }
            .features { grid-template-columns: 1fr; }
            .mock-stats { grid-template-columns: 1fr 1fr; }
            .mock-stats .mock-stat:last-child { display: none; }
            section.block { padding: 64px 0; }
            .cta { padding: 40px 22px; }
            .nav-actions .btn-ghost { display: none; }
        }
    </style>
</head>
<body>

<header class="nav">
    <div class="container nav-inner">
        <a href="index.php" class="nav-logo"><img src="assets/images/logo.png" alt="AfterBill"></a>
        <nav class="nav-links">
            <a href="#features">Features</a>
            <a href="#how">How it works</a>
        </nav>
        <div class="nav-actions">
            <?php if ($loggedIn): ?>
                <a href="dashboard.php" class="btn btn-primary btn-sm">Go to Dashboard</a>
            <?php else: ?>
                <a href="auth/login.php" class="btn btn-ghost btn-sm">Log In</a>
                <a href="auth/register.php" class="btn btn-primary btn-sm">Get Started</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<section class="hero">
    <div class="container hero-grid">
        <div>
            <span class="eyebrow">Monthly Bill Tracker</span>
            <h1>Never miss a bill <span>again.</span></h1>
            <p class="lead">AfterBill keeps every electricity, internet, rent and subscription bill in one place, reminds you before they're due, and shows you exactly where your money goes each month.</p>
            <div class="hero-cta">
                <?php if ($loggedIn): ?>
                    <a href="dashboard.php" class="btn btn-primary">
                        Welcome back, <?= $firstName ?> — open dashboard
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                    </a>
                <?php else: ?>
                    <a href="auth/register.php" class="btn btn-primary">
                        Create a free account
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                    </a>
                    <a href="auth/login.php" class="btn btn-ghost">I already have an account</a>
                <?php endif; ?>
            </div>
            <ul class="hero-points">
                <li>Free to use</li>
                <li>Recurring bills auto-generated</li>
                <li>Works on any device</li>
            </ul>
        </div>

        <div class="mock" aria-hidden="true">
            <div class="mock-head">
                <strong>This month</strong>
                <span>Overview</span>
            </div>
            <div class="mock-stats">
                <div class="mock-stat"><b>₱8,450</b><small>Total bills</small></div>
                <div class="mock-stat"><b>₱5,200</b><small>Paid</small></div>
                <div class="mock-stat"><b>2</b><small>Due soon</small></div>
            </div>
            <div class="mock-row">
                <div class="mock-dot" style="background:#FEF3C7;">⚡</div>
                <div class="name">Electric Bill<small>Utilities · Due Oct 5</small></div>
                <span class="pill due">Due in 3 days</span>
                <div class="amt">₱2,150</div>
            </div>
            <div class="mock-row">
                <div class="mock-dot" style="background:#DBEAFE;">📶</div>
                <div class="name">Fiber Internet<small>Internet · Due Oct 8</small></div>
                <span class="pill due">Upcoming</span>
                <div class="amt">₱1,699</div>
            </div>
            <div class="mock-row">
                <div class="mock-dot" style="background:#DCFCE7;">🏠</div>
                <div class="name">Apartment Rent<small>Housing · Paid Oct 1</small></div>
                <span class="pill paid">Paid</span>
                <div class="amt">₱4,500</div>
            </div>
            <div class="mock-row">
                <div class="mock-dot" style="background:#FCE7F3;">🎬</div>
                <div class="name">Streaming<small>Subscriptions · Due Sep 28</small></div>
                <span class="pill late">Overdue</span>
                <div class="amt">₱549</div>
            </div>
        </div>
    </div>
</section>

<section class="block" id="features">
    <div class="container">
        <div class="section-head">
            <div class="kicker">Features</div>
            <h2>Everything you need to stay on top of your bills</h2>
            <p>Simple tools that turn a pile of due dates into a clear monthly plan.</p>
        </div>

        <div class="features">
            <div class="feature">
                <div class="feature-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08M8.25 8.25H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z"/></svg></div>
                <h3>Bill management</h3>
                <p>Add, edit, search and filter your bills by category and status. Overdue bills are flagged automatically.</p>
            </div>
            <div class="feature">
                <div class="feature-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg></div>
                <h3>Recurring bills</h3>
                <p>Mark a bill as monthly and AfterBill creates next month's bill for you — no retyping.</p>
            </div>
            <div class="feature">
                <div class="feature-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg></div>
                <h3>Calendar view</h3>
                <p>See every due date on a monthly calendar, color-coded by paid, unpaid and overdue.</p>
            </div>
            <div class="feature">
                <div class="feature-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/></svg></div>
                <h3>Smart reminders</h3>
                <p>Overdue, due today and coming-up lists with your own reminder window, plus optional desktop alerts.</p>
            </div>
            <div class="feature">
                <div class="feature-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg></div>
                <h3>Payment history</h3>
                <p>Record how and when you paid — cash, GCash, bank or card — and export your history to CSV.</p>
            </div>
            <div class="feature">
                <div class="feature-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg></div>
                <h3>Monthly reports</h3>
                <p>Spending by category, a six-month trend and month-over-month change, so you know where your money goes.</p>
            </div>
        </div>
    </div>
</section>

<section class="block alt" id="how">
    <div class="container">
        <div class="section-head">
            <div class="kicker">How it works</div>
            <h2>Get organized in three steps</h2>
        </div>
        <div class="steps">
            <div class="step">
                <div class="step-num">1</div>
                <h3>Create your account</h3>
                <p>Sign up with your name and email. It takes less than a minute.</p>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <h3>Add your bills</h3>
                <p>Enter each bill's amount, due date and category. Tick “repeats monthly” for regular ones.</p>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <h3>Pay and track</h3>
                <p>Get reminded before due dates, mark bills as paid, and review your monthly report.</p>
            </div>
        </div>
    </div>
</section>

<section class="block">
    <div class="container">
        <div class="cta">
            <h2>Take control of your monthly bills today.</h2>
            <p>Join AfterBill and stop worrying about late payments.</p>
            <?php if ($loggedIn): ?>
                <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
            <?php else: ?>
                <a href="auth/register.php" class="btn btn-primary">Get Started — it's free</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<footer>
    <div class="container footer-inner">
        <img src="assets/images/logo.png" alt="AfterBill">
        <span>© <?= date('Y') ?> AfterBill · Monthly Bill Tracker</span>
    </div>
</footer>

</body>
</html>
