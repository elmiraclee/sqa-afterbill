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
