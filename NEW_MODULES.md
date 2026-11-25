# AfterBill — New Modules

Modules and features added on 2026-10-02. Status of the work: [FEATURES_STATUS.md](FEATURES_STATUS.md).

> **Setup for every copy of the database (teammates too):** run the migration once —
> phpMyAdmin → `afterbill` → SQL → paste `database/migrations/001_budgets_activity_log.sql` → Go.
> Without it, Budgets and Activity Log show a "not set up yet" message instead of failing.

---

## Module 1 — Budgets

**Page:** `budgets.php` (sidebar → **Budgets**) · **Table:** `budgets`

Set a monthly spending limit per category and track it against the bills due that month.

| Function | Details |
|---|---|
| Set / update a budget | One amount per category; saving again updates it (no duplicates). Amount must be > 0 and ≤ 99,999,999.99. |
| Remove a budget | "Remove" button, with a confirmation prompt. |
| Monthly tracking | For the chosen month: due amount and bill count, budget, remaining (negative when over), progress bar, % used. |
| Status | **On track** (< 80%) · **Near limit** (80–100%) · **Over budget** (> 100%) · **No budget**. |
| Summary cards | Total budget, due in budgeted categories, remaining / over, number of categories over budget. |
| Month navigation | ‹ Prev / Next › / This month; `?month=YYYY-MM`. |
| Over-budget warning | After adding or editing a bill that puts its category over budget, My Bills shows a one-time warning with a link to Budgets. |
| Sidebar badge | Red count next to **Budgets** = categories over budget this month, on every page. |

"Due" = all bills due in that month for the category, paid + unpaid — the same total Reports uses.

**Files:** `budgets.php`, `includes/budgets.php`, `partials/sidebar.php`, `add_bill.php`, `edit_bill.php`, `bills.php`

---

## Module 2 — Activity Log

**Page:** `activity.php` (sidebar → Account → **Activity Log**) · **Table:** `activity_log`

A read-only history of everything done on the account, newest first.

| Function | Details |
|---|---|
| Tracked events (13) | Account created · Logged in · **Failed login** · Logged out · Bill added / updated / deleted · Payment recorded / undone · Profile updated (says what changed) · Password changed · Budget set / removed |
| Details | e.g. "Meralco Electric — ₱2,450.00, due Oct 5, 2026", "Netflix — ₱549.00 via GCash". |
| Filter | All · Sign-in · Bills · Payments · Account · Budgets. |
| Grouping & paging | Grouped by day (Today / Yesterday / date); 25 per page with Newer / Older. |
| Security alert | Shows a warning when there were failed login attempts on the account in the last 7 days. |
| Privacy | Each user sees only their own events. |

Only successful actions are logged — invalid or forged requests are not. Logging never blocks the action itself.

**Files:** `activity.php`, `includes/activity.php`, plus one `logActivity()` call in `auth/login.php`,
`auth/register.php`, `auth/logout.php`, `add_bill.php`, `edit_bill.php`, `delete_bill.php`, `mark_paid.php`,
`undo_payment.php`, `account.php`, `budgets.php`

---

## Feature — Bills CSV export (enhancement to My Bills)

**Where:** My Bills → **Export CSV** button (top right, shown when there are bills)

| Function | Details |
|---|---|
| Export | Downloads `afterbill-bills-YYYY-MM-DD.csv`: Bill, Category, Amount, Due Date, Status (Paid / Unpaid / Overdue), Recurring. |
| Follows filters | Exports exactly what the list shows (search, status, category). |
| Safe for Excel | Formula-like text (`=`, `+`, `-`, `@`, tab, CR) is escaped, same as the payments export. |

**Files:** `bills.php`, `includes/csv.php` (shared with `payments.php`)

---

## Database changes

| Table | Columns | Notes |
|---|---|---|
| `budgets` | budget_id, user_id, category_id, monthly_limit, created_at, updated_at | Unique (user_id, category_id). Deleted with the user. |
| `activity_log` | log_id, user_id, action, details, ip_address, created_at | Indexed on (user_id, created_at). Deleted with the user. |

No existing table was changed.

---

# Round 2 — added 2026-11-20

> **Setup for every copy of the database:** run `database/migrations/004_categories_payments_reminders.sql` once
> (phpMyAdmin → `afterbill` → SQL → paste → Go). It is safe to re-run. Without it, payments still work but
> receipts, custom categories and email reminders show a "not set up yet" message.

## Feature 1 — Email reminders

**Script:** `cron/send_reminders.php` (command line only) · **Settings:** Account → **Email Reminders** · **Columns:** `users.reminder_emails`, `reminder_days`, `last_reminder_on`

| Function | Details |
|---|---|
| Daily digest | One plain-text email per user per day listing unpaid bills that are **overdue** or **due within N days**, with the balance still owed and a total. Nothing is sent on days when nothing is due. |
| Settings | On/off and N = 1, 3, 7 or 14 days (default: on, 3 days). Changes are logged in the Activity Log. |
| Never twice a day | `last_reminder_on` is claimed atomically before sending, so running the script again (or twice at once) sends nothing new. If the email fails, the day is given back so a later run retries. |
| Who gets it | Only verified accounts with reminders on. Recurring bills are caught up first, so this month's cycle is included. |
| Reminders page | Shows whether email reminders are on, with a link to change it. |

Run it once a day (see the header of `cron/send_reminders.php` for crontab / Windows Task Scheduler lines):

```
php cron/send_reminders.php             # send
php cron/send_reminders.php --dry-run   # preview, sends nothing
php cron/send_reminders.php --user=12   # one account only
```

With the default `log` mail driver the emails go to `storage/mail.log`. Configure SMTP in `config/mail.local.php` for real email.

## Feature 2 — Custom categories

**Page:** `categories.php` (sidebar → **Categories**) · **Column:** `categories.user_id` (NULL = built-in)

| Function | Details |
|---|---|
| Add / rename / delete | Up to 20 custom categories per user, 2–50 characters. A name can't repeat a built-in or one of your own (case-insensitive). Built-ins can't be changed. |
| Private | Each user only sees and can only use their own custom categories (bills, filters, budgets). Another user's category id is rejected everywhere. |
| Delete | Moves the category's bills to **Others** and removes its budget, in one transaction. |
| Everywhere | Add/Edit Bill (with a "Manage categories" link), My Bills filter, Budgets, Reports. |

## Feature 3 — Partial payments

**Pages:** `mark_paid.php`, `bills.php`, `undo_payment.php`, `edit_bill.php` · **Rule:** `includes/payments.php` → `refreshBillStatus()`

| Function | Details |
|---|---|
| Pay part of a bill | Enter less than the balance: the payment is recorded and the bill stays unpaid as **Partially paid**. The form defaults to the remaining balance. |
| Fully paid | The bill turns paid once its payments add up to the amount (compared in centavos). |
| Can't overpay | An amount above the balance is rejected; the balance is re-checked inside a locked transaction, so two tabs can't both pay. |
| My Bills | "Partially paid" badge, "₱X paid · ₱Y left", **Add Payment** button, and a "Partially paid" status filter (also in CSV export). |
| Undo / edit | Undoing one payment, or changing the bill amount, works the status out again from the payments that are left. |

Dashboard and Reports still count a partially paid bill as unpaid (by bill status).

## Feature 4 — Payment receipts

**Upload:** Mark as Paid / Add Payment form · **View:** Payment History → 📎 Receipt · **Column:** `payments.receipt_file`

| Function | Details |
|---|---|
| Upload | Optional JPG, PNG, WebP or PDF up to 5 MB. The type is detected from the file contents (not the name); images must decode as images. |
| Storage | `storage/receipts/` under a random 32-character name. `storage/` is denied by Apache and git-ignored. |
| Viewing | `receipt.php?payment_id=N` — only the owner; anyone else gets 404. Images open inline, PDFs download. Sent with `nosniff` and `no-store`. |
| Cleanup | The file is deleted when its payment is undone or the account is deleted. |

## Tests

34 new checks in `tests/backend_audit.php`: C01–C11 (categories), PP01–PP08 (partial payments), RC01–RC08
(receipts), RM01–RM07 (reminders). Existing page-health and login-redirect checks now include the new pages.


---

# Round 3 — added 2026-11-20

> **Setup for every copy:** `composer install` (new libraries: dompdf for PDFs, chillerlan/php-qrcode for the
> 2FA QR code), then run `database/migrations/005_more_features.sql` once (after 004). Safe to re-run.

| # | Feature | Where | Notes |
|---|---|---|---|
| 1 | **Totals count partial payments** | Dashboard, Reports, PDF | `billTotals()` in `includes/payments.php`: a paid bill counts in full; a partly paid bill splits into "paid" (money in) and "still owed". Cards say e.g. "Still owed · 3 bills (1 partly paid)". |
| 2 | **Attach a receipt later** | Payment History → "+ Attach receipt" / Replace / Remove | `attach_receipt.php`. Same file checks as on payment. Replacing deletes the old file. Errors travel in the session, never the URL. |
| 3 | **Budget alerts in the reminder email** | Daily reminder | A "BUDGETS THIS MONTH" section lists categories ≥ 80% or over, and the subject says "N over budget". Budgets alone never trigger an email. |
| 4 | **Recurring back-fill fixed (R03)** | `includes/recurring_bills.php` | Back-fill now covers exactly 12 months (this month + 11), as the comments always said; it filled 13 before. |
| 5 | **Repeat schedules** | Add / Edit Bill → "This bill repeats" | Weekly, monthly, every 3 months, yearly; ends never / on a date / after N bills (stored as the N-th date). Month-based schedules keep their day (Jan 31 → Feb 28 → Mar 31). Weekly back-fill: 8 weeks. My Bills shows "Weekly", "Yearly"… |
| 6 | **Pay selected bills** | My Bills → tick bills → Pay selected | `bulk_pay.php`. Each ticked bill gets one payment for its whole remaining balance; paid / other users' bills are skipped; each bill is locked in its own transaction. |
| 7 | **Dashboard 6-month chart** | Dashboard → "Last 6 months" | Paid vs still owed per month (same numbers as Reports), total label per column, hover tooltip, click → that month's report, "Show as table". |
| 8 | **Monthly report PDF** | Reports → Download PDF | `report_pdf.php?month=YYYY-MM`. Summary, by category, every bill with paid amount and status. dompdf with remote loading, PHP and JS off. |
| 9 | **Mobile layout** | Every app page under 680px | Menu button opens the sidebar as a slide-in panel (backdrop / link / Esc closes). Pages no longer overflow sideways; wide tables scroll inside their panel. `assets/js/nav.js` + `partials/sidebar.php`. |
| 10 | **Two-factor login** | Account → Two-Factor Login | Authenticator app (TOTP, RFC 6238; QR code or typed key), 8 one-time recovery codes (stored hashed, shown once). Login asks for the code after the password (`auth/two_factor.php`): 5 minutes, 5 tries, codes can't be reused, wrong codes count toward the login lock. Turning it off needs password + code. |
| 11 | **Signed-in devices** | Account → Signed-In Devices | Each signed-in browser (device, IP, times). Sign out one or all others; that browser is signed out on its next request. Only a hash of the session id is stored. Logout removes the row. |
| 12 | **Download my data** | Account → Download My Data | Password-protected ZIP: README, profile.json (no password hash / 2FA secret), bills, payments, budgets, categories, activity log as CSV (formula-safe), and every receipt file. |

Tests: 33 new checks — TP01–TP05, AR01–AR04, BA01, RS01–RS06, BP01–BP03, MB01, TF01–TF07, SD01–SD04, DX01–DX02.
