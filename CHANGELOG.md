# AfterBill — Changelog

## SQA audit, fixes and new modules (2026-10-02)

Full test suite: `C:\xampp\php\php.exe tests\backend_audit.php` — **106 / 106 checks passing.**
Detailed logs: [AUDIT_STATUS.md](AUDIT_STATUS.md), [FEATURES_STATUS.md](FEATURES_STATUS.md),
[SYSTEM_AUDIT_STATUS.md](SYSTEM_AUDIT_STATUS.md), module list [NEW_MODULES.md](NEW_MODULES.md).

> **Setup after pulling:** run [database/migrations/001_budgets_activity_log.sql](database/migrations/001_budgets_activity_log.sql)
> once on your `afterbill` database (phpMyAdmin → SQL). It creates the `budgets` and `activity_log` tables.

### 1. New modules

| Module | Page | What it does |
|---|---|---|
| **Budgets** | `budgets.php` (sidebar → Budgets) | Monthly spending limit per category, with progress bars and a status: On track, Near limit (80% or more), Over budget. Also has summary cards, month navigation, a red sidebar badge for categories over budget, and a one-time warning on My Bills when a new or edited bill goes over budget. |
| **Activity Log** | `activity.php` (sidebar → Account → Activity Log) | History of 13 kinds of events: sign-ins, failed logins, logouts, bill add/edit/delete, payments recorded/undone, profile and password changes, budget changes. Filter by type, grouped by day, 25 per page, plus a warning when there were failed logins in the last 7 days. |

### 2. New features and enhancements

1. **Export CSV on My Bills.** Exports exactly what the current filters show, and is safe to open in Excel.
2. **Over-budget warning** after adding or editing a bill.
3. **Sidebar badges** for categories over budget.
4. **Login lockout.** 5 wrong passwords lock that account for 15 minutes.
5. **Logout button is now a secure form** on all 10 pages, instead of a plain link.
6. **A test suite of 106 automated checks** in `tests/backend_audit.php`.

### 3. Fixes in the app (29)

**Security**

1. CSRF protection added to every form; before, another website could delete bills, add bills or change your email.
2. Logout now requires a POST with a token; before, a link or image on another site could log you out.
3. Session fixation fixed: the session ID now changes at login.
4. The same fix at registration.
5. Login brute-force protection added (the lockout above).
6. The `.git` folder can no longer be downloaded; before, anyone could pull the full source code.
7. Directory listings turned off on 9 folders.
8. Internal files (tests, notes, SQL, internal PHP files) blocked from the browser.
9. PHP errors no longer shown to visitors; they used to show `C:\xampp\...` paths.
10. Clickjacking protection and other security headers added.
11. The PHP version is no longer advertised.
12. The login cookie is now HttpOnly and SameSite.
13. Session IDs the server never issued are now rejected.
14. Payments CSV export now escapes text Excel would run as a formula, like `=HYPERLINK(...)`.

**Data and logic**

15. A full name over 120 characters at registration was silently cut short; now it's rejected.
16. A password over 72 bytes at registration was silently cut short; now it's rejected.
17. Due dates like 1900 or 9999 were accepted; now they must fall between 2000 and 2100.
18. A recurring bill due on the 31st drifted to the 28th; it now keeps its day.
19. A years-old recurring bill added 24 bills on every page load; it now catches up in one load, back-filling at most 12 months.
20. A deleted recurring bill came back on the next load; now it stays deleted.
21. Deleting a middle bill in a recurring series duplicated the next month; fixed.
22. The recurring generator now stops if nothing can be inserted.
23. The clock was wrong. PHP was 6 hours behind the database, so the dashboard said "Good afternoon" in the evening, and after midnight a payment made today was rejected as "in the future". Both now use Manila time.
24. Database connection errors are now written to the server log.
25. `account.php` used to redirect a deleted user's session through the old logout link; it now ends that session itself.

**Display**

26. Budgets page: badges and buttons wrapped onto two lines; fixed.
27. Activity Log: the IP column showed `::1`; it now says "This computer".

**Clean-up**

28. The payments CSV code now uses one shared helper, `includes/csv.php`.
29. Logout was copied on 10 pages; it's now one shared file, `partials/logout-button.php`.

### 4. Recommended XAMPP settings (machine config, not in this repo)

Applied on the audit machine; worth checking on each teammate's XAMPP:

1. `php.ini` `error_log` should point to an existing folder, e.g. `C:\xampp\php\logs\php_error_log`.
2. `php.ini` paths (`extension_dir`, `session.save_path`, `include_path`, `upload_tmp_dir`, `browscap`,
   `curl.cainfo`, `openssl.cafile`) should point to your own XAMPP folder, not an old install.
3. `php.ini` `date.timezone=Asia/Manila` (the app also sets this itself via `.htaccess` and `config/database.php`).

### 5. Checked and already working

- Bill ownership: one user can't touch another's bills.
- Validation, and safe display of names containing HTML (XSS).
- Dashboard, reports, calendar, reminders and payments all match the database.
- Every link and image loads.
- No JavaScript errors.
