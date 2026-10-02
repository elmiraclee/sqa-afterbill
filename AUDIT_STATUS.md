# AfterBill — Backend Audit Status

Live status log for the backend audit test run. Updated at the end of every phase.

| Phase | Name | Status |
|---|---|---|
| 1 | Recon — read backend code, schema, environment | ✅ Done |
| 2 | Build audit test suite (`tests/backend_audit.php`) | ✅ Done — 49 checks |
| 3 | Run suite — record failing test cases | ✅ Done — 9 real backend failures |
| 4 | Fix failing backend cases | ✅ Done — 9 fixed |
| 5 | Re-run suite — verify all green | ✅ Done — **49 / 49 pass** |

**Current system status: all 56 backend audit checks passing (last run 2026-10-02 12:05). Round 1 + Round 2 complete.**

### Round 2 — logout hardening, CSV escaping, recurring runaway

| Phase | Name | Status |
|---|---|---|
| 2.1 | Recon — logout links, CSV path, recurring generator edge cases | ✅ Done |
| 2.2 | Add tests for the three items (expect some to fail) | ✅ Done — 7 new checks (L01–L03, P03, R03–R05) |
| 2.3 | Run — record failures | ✅ Done — 50 pass / 6 fail |
| 2.4 | Fix | ✅ Done — 6 fixed |
| 2.5 | Re-run full suite — verify green | ✅ Done — **56 / 56 pass** |

Run the suite any time:

```
C:\xampp\php\php.exe tests\backend_audit.php
```

Output is also saved to `tests/last_run.txt`. The suite creates throwaway `@audit.test` users and deletes them when it finishes.

---

## Phase 1 — Recon ✅

- Stack: PHP 8.0.30 (XAMPP), MariaDB `afterbill` (tables `users`, `bills`, `categories`, `payments`), plain PDO, no framework.
- No existing tests in the repo or on any remote branch, so the audit suite was written from scratch.
- `sql_mode` is non-strict, so oversize strings are **silently truncated** by the DB instead of erroring.

## Phase 2 — Test suite ✅

`tests/backend_audit.php` — 49 checks in 9 groups: registration, login/session, bill create/validate,
edit/pay/undo/delete, ownership (IDOR), CSRF, recurring bills, page health, account.

## Phase 3 — First run ✅  (38 pass / 11 fail)

Two failures were mistakes in the test itself (R01 expected count off by one; S08 passed by accident
because S05 had already deleted its bill) — both corrected in the suite. The 9 real backend failures:

| ID | Area | Failure | Severity |
|---|---|---|---|
| A06 | register.php | 150-char full name accepted; DB silently truncates to 120 | Low |
| A07 | register.php | 80-byte password accepted; bcrypt silently uses only the first 72 bytes | Medium |
| A12 | login.php | Session id not regenerated on login → session fixation | High |
| A13 | register.php | Session id not regenerated on registration → session fixation | High |
| B06 | add_bill / edit_bill | Due date year 1900 accepted; a recurring bill that old makes every page load insert 24 more bills | Medium |
| P01 | payments.php | CSV export writes `=HYPERLINK(...)` as-is → formula injection when opened in Excel | Medium |
| S05 | delete_bill.php | No CSRF token — another site can delete a user's bill | High |
| S06 | add_bill.php | No CSRF token — another site can create bills | High |
| S07 | account.php | No CSRF token — another site can change the account email (account takeover) | High |
| R02 | recurring_bills.php | Bill due on the 31st drifts to the 30th/28th and never returns | Medium |

## Phase 4 — Fixes ✅

| ID | Fix | Files |
|---|---|---|
| S05–S08 | New `includes/csrf.php` (one random token per session, `hash_equals` check). Every POST form renders `csrf_field()`; every POST handler rejects a missing/wrong token with a "please try again" error (forms) or a plain redirect (delete/undo). | `includes/csrf.php`, `add_bill.php`, `edit_bill.php`, `mark_paid.php`, `delete_bill.php`, `undo_payment.php`, `bills.php`, `payments.php`, `account.php`, `auth/login.php`, `auth/register.php` |
| A12, A13 | `session_regenerate_id(true)` right after successful login and registration. | `auth/login.php`, `auth/register.php` |
| A06, A07 | Register now enforces full name ≤ 120 chars, email ≤ 180 chars, password ≤ 72 bytes — same rules as `account.php`. | `auth/register.php` |
| B06 | Due date must be between 2000-01-01 and 2100-12-31 on add and edit. | `add_bill.php`, `edit_bill.php` |
| P01 | CSV export prefixes `'` to text cells starting with `= + - @` / tab / CR so spreadsheets show them as text. | `payments.php` |
| R02 | Next recurring cycle keeps the series' intended day of month (Jan 31 → Feb 28 → **Mar 31**), clamped only to the month's length. | `includes/recurring_bills.php` |

## Phase 5 — Verification ✅  (49 pass / 0 fail)

- `php -l` clean on all 13 changed files.
- Full suite re-run: 49 / 49 pass. See `tests/last_run.txt`.
- Audit users cleaned up after the run (0 `@audit.test` users left; real data unchanged: 4 users, 2 bills).

## Round 2 — Phase 2.3 results (50 pass / 6 fail)

| ID | Result | Detail |
|---|---|---|
| P03 | ✅ PASS | CSV escaping confirmed for all six prefixes `=` `+` `-` `@` tab CR, in both the Notes and Bill columns. No change needed. |
| L01 | ❌ FAIL | `GET auth/logout.php` logs the user out — any link or `<img src>` on another site can do it. |
| L02 | ❌ FAIL | `POST auth/logout.php` with no CSRF token logs the user out. |
| L03 | ❌ FAIL | All 10 pages render logout as a plain `<a href>` link. |
| R03 | ❌ FAIL | A recurring bill dated 2001 generated 24 bills per page load, without end: 25 bills after load 1, 73 after load 3 (would reach ~310). |
| R04 | ❌ FAIL | Deleting the newest cycle of a recurring bill: it is re-created on the next page load. |
| R05 | ❌ FAIL | Deleting a middle cycle: it is re-created, and the following month is duplicated (two Oct 13 bills). |

## Round 2 — Phase 2.4 fixes ✅

| ID | Fix | Files |
|---|---|---|
| L01–L03 | `logout.php` only acts on POST with a valid CSRF token; GET or forged POST just redirects back. New shared `partials/logout-button.php` (POST form + token, same `.btn-logout` look) replaces the `<a>` link on all 10 pages. `account.php` now ends a dead-user session itself instead of redirecting to the GET logout. | `auth/logout.php`, `partials/logout-button.php`, `account.php`, `dashboard.php`, `bills.php`, `add_bill.php`, `edit_bill.php`, `mark_paid.php`, `calendar.php`, `reports.php`, `payments.php`, `reminders.php` |
| R03 | Recurring back-fill limited to the last 12 months: an old bill jumps to 12 months back instead of one bill per missed month. Any series catches up in ≤ 13 iterations (cap is 24), so it is done in one load. The loop also stops if every insert in an iteration fails. | `includes/recurring_bills.php` |
| R04, R05 | Deleting a generated cycle sets `is_recurring = 0` on its parent (in a transaction), so the deleted bill is not re-created. A deleted middle cycle's child keeps the series going. | `delete_bill.php` |

## Round 2 — Phase 2.5 verification ✅  (56 pass / 0 fail)

- `php -l` clean on all 15 changed files.
- Full suite: 56 / 56 pass (49 from Round 1 + 7 new). See `tests/last_run.txt`.
- Cleanup verified: 0 `@audit.test` users left; real data unchanged (4 users, 2 bills).

### Notes / follow-ups (not failing, worth knowing)

- Users already logged in keep working — the CSRF token is created the first time a form is rendered.
- Behaviour change: deleting a cycle of a recurring bill ends the series at that point. To restart it, edit the previous bill and tick "recurring" again.
- Behaviour change: a recurring bill more than 12 months overdue back-fills only the last 12 months.
- `mark_paid.php` accepts a partial `amount_paid` and still marks the bill fully paid. This is a product decision, not tested as a failure.
