# AfterBill — Whole-System Audit Status

Live status log for the whole-system audit. Updated at the end of every phase.
Earlier rounds: [AUDIT_STATUS.md](AUDIT_STATUS.md) (backend), [FEATURES_STATUS.md](FEATURES_STATUS.md) (new modules).

| Phase | Name | Status |
|---|---|---|
| W1 | Scope — server/PHP/DB config, every page, every file reachable from the web | ✅ Done |
| W2 | Audit — automated checks across the whole system | ✅ Done — 21 new system checks (106 total) |
| W3 | Findings — record every error / problem / issue | ✅ Done — 11 issues |
| W4 | Fix | ✅ Done — 10 fixed in code, 1 machine-config note |
| W5 | Verify — full suite + browser check, all green | ✅ Done — **106 / 106 pass** |

**Current system status: whole-system audit complete. 106 / 106 checks passing (2026-10-02).**

---

## W1 — Scope ✅

Audited: every page (10 app pages, 3 public pages, 2 new modules), every file/folder reachable over HTTP,
Apache/PHP/MySQL configuration, HTTP headers and cookies, date/time handling, numbers shown vs database,
links/assets, server error log, login abuse.

Environment facts found:

| Setting | Value | Impact |
|---|---|---|
| PHP `date.timezone` | `Europe/Berlin` (UTC+2) | PHP is 6 h behind MySQL/Windows (UTC+8) |
| MySQL time zone | SYSTEM = UTC+8 | `CURDATE()` and PHP `date()` disagree between 00:00 and 06:00 |
| `display_errors` | On | Warnings shown to visitors with full server paths |
| `session.cookie_httponly` / `samesite` / `use_strict_mode` | off / unset / 0 | Cookie readable by JS, sent cross-site, any id accepted |
| Apache `Options Indexes` | On for htdocs | Folder listings visible |
| `error_log` in php.ini | points to a non-existent folder (old XAMPP path) | PHP errors fall back to `apache/logs/error.log` |

## W2 — Audit ✅

21 system checks added to `tests/backend_audit.php` (groups `SYSTEM — …`):
web exposure (W01–W04), HTTP security (W05–W09), brute force (W10–W12), time consistency (T01–T02),
numbers vs database (N01–N05), links & server log (K01–K02).

First run: **94 pass / 12 fail** — 1 was a mistake in the test (N03 looked for `2026-10-02`, the calendar
shows `Oct 2, 2026`; test corrected). 11 real issues below.

## W3 — Findings ✅

| # | ID | Issue | Severity |
|---|---|---|---|
| 1 | W01 | `/.git/` is downloadable — anyone can pull the full source code and history | High |
| 2 | W02 | Directory listings on `/assets/`, `/auth/`, `/includes/`, `/partials/`, `/config/`, `/tests/`, `/database/` | Medium |
| 3 | W03 | Internal files readable: `tests/last_run.txt`, migration SQL, `*.md` notes, partials run directly | Medium |
| 4 | W09, K02 | Opening `partials/sidebar.php` directly prints PHP warnings with `C:\xampp\...` paths (`display_errors=On`) | Medium |
| 5 | W05 | No `X-Frame-Options` / `X-Content-Type-Options` / `Referrer-Policy` → pages can be framed (clickjacking) | Medium |
| 6 | W06 | `X-Powered-By: PHP/8.0.30` advertises the PHP version | Low |
| 7 | W07 | Session cookie has no `HttpOnly` / `SameSite` | Medium |
| 8 | W08 | Server accepts session ids it never issued (`use_strict_mode=0`) | Medium |
| 9 | W10 | No brute-force protection: unlimited password guesses | High |
| 10 | T01 | PHP and MySQL clocks 6 h apart: dashboard says "Good afternoon" at 6:30 PM; between 00:00–06:00 PHP's "today" is yesterday (wrong date badge, today's payment rejected as "in the future", overdue badges off by a day) | High |
| 11 | — | `php.ini` `error_log` points to a missing folder | Info (machine config, not in the repo) |

Passed already (no change needed): reports, payments, reminders and dashboard numbers match SQL;
every link/asset on every page resolves; all earlier 85 checks still green.

## W4 — Fixes ✅

| # | Fix | Files |
|---|---|---|
| 1–3 | Root `.htaccess`: `Options -Indexes`; any path starting with a dot returns 404 (`.git`, `.vscode`, `.snapshots`); `*.md *.txt *.sql *.log *.json *.ini *.bak *.lock` denied. `Require all denied` in every server-side-only folder. | `.htaccess`, `config/.htaccess`, `includes/.htaccess`, `partials/.htaccess`, `tests/.htaccess`, `database/.htaccess` |
| 4 | `display_errors Off`, `log_errors On` — errors go to the server log only. Partials can no longer be opened directly. | `.htaccess` |
| 5–6 | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`; `X-Powered-By` removed. | `.htaccess` |
| 7–8 | Session cookie `HttpOnly` + `SameSite=Lax`; `session.use_strict_mode On`. | `.htaccess` |
| 9 | Login lock: 5 failed logins within 15 min (since the last successful login) → that account refuses logins for 15 min with "Too many failed login attempts. Please wait N minutes…". Attempts during the lock are not logged, so it ends on time. Unknown emails behave exactly as before. | `includes/activity.php`, `auth/login.php` |
| 10 | One app timezone `Asia/Manila` (PHP via `config/database.php` + `.htaccess`); every DB connection runs `SET time_zone` to PHP's offset, so `CURDATE()`/`NOW()` and `date()` always agree. DB connection errors are now written to the server log. | `config/database.php`, `.htaccess` |
| 11 | **Fixed (follow-up, machine config):** `php.ini` line 972 → `error_log="C:\xampp\php\logs\php_error_log"`, folder `C:\xampp\php\logs` created, Apache restarted. Verified from CLI and web. Backup: `C:\xampp\php\php.ini.bak-20261002-183644`. Test K02 now reads this log too. ServerTokens intentionally left as-is. | `C:\xampp\php\php.ini` (outside repo), `tests/backend_audit.php` |

## W5 — Verification ✅

- Full suite: **106 / 106 pass** (85 earlier checks + 21 system checks). `tests/last_run.txt` updated.
- N03 needed a second test correction (it cut the calendar text too early); the calendar itself was correct.
- Headless Chrome: `index.php`, `auth/login.php`, `auth/register.php` — 0 console errors; login page renders normally.
- Cleanup verified: 0 `@audit.test` users left; real data unchanged (5 users, 2 bills).

## W6 — php.ini cleanup (follow-up) ✅

PHP was still reading 7 paths from an old second XAMPP install (`C:\Users\63906\Downloads\XAMPP`), and its
default timezone was `Europe/Berlin`.

| Setting | Before | After |
|---|---|---|
| `include_path` | `…\Downloads\XAMPP\php\PEAR` | `C:\xampp\php\PEAR` |
| `extension_dir` | `…\Downloads\XAMPP\php\ext` | `C:\xampp\php\ext` |
| `upload_tmp_dir` | `…\Downloads\XAMPP\tmp` | `C:\xampp\tmp` |
| `browscap` | `…\Downloads\XAMPP\php\extras\browscap.ini` | `C:\xampp\php\extras\browscap.ini` |
| `session.save_path` | `…\Downloads\XAMPP\tmp` | `C:\xampp\tmp` |
| `curl.cainfo` | `…\Downloads\XAMPP\apache\bin\curl-ca-bundle.crt` | `C:\xampp\apache\bin\curl-ca-bundle.crt` |
| `openssl.cafile` | `…\Downloads\XAMPP\apache\bin\curl-ca-bundle.crt` | `C:\xampp\apache\bin\curl-ca-bundle.crt` |
| `date.timezone` | `Europe/Berlin` | `Asia/Manila` |

Backups: `C:\xampp\php\php.ini.bak-20261002-183644` (original), `php.ini.bak-20261002-183934` (before this step).

Verification:

| Check | Result |
|---|---|
| All 11 enabled extensions exist in `C:\xampp\php\ext`, same build (8.0.30) | ✅ |
| CLI: startup warnings / loaded modules vs before | ✅ 0 warnings, same 38 modules |
| CLI: HTTPS via the new CA bundle | ✅ 200 |
| Apache restarted; PHP startup warnings in Apache log | ✅ 0 |
| Web: paths, Manila time, session file written to `C:\xampp\tmp` | ✅ |
| phpMyAdmin | ✅ loads |
| Full audit suite | ✅ **106 / 106** |
| Headless Chrome: public pages console errors / logged-in page render | ✅ 0 errors, renders with Manila times |

No remaining reference to `Downloads\XAMPP` in php.ini, Apache config or MySQL; running `httpd.exe` and
`mysqld.exe` are both from `C:\xampp`.

### Notes

- The `.htaccess` files are part of the project, so teammates get the same protection on `git pull`
  (XAMPP's default `AllowOverride All` for htdocs makes them active).
- `Server: Apache/2.4.58 (Win64) … PHP/8.0.30` is still sent: `ServerTokens` can only be changed in
  `C:\xampp\apache\conf\extra\httpd-default.conf` (`ServerTokens Prod`, `ServerSignature Off`), not per project.
- The login lock is per account, so someone who knows an email can lock that account for 15 minutes
  by guessing wrong 5 times. That is the usual trade-off for this kind of protection.
