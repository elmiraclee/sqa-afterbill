<?php
/**
 * AfterBill - Backend Audit Test Suite
 *
 * Drives the running app over HTTP (like a browser would) and checks the database directly.
 * Creates its own throwaway users and deletes them afterwards; real data is never touched.
 *
 * Usage (XAMPP Apache + MySQL must be running):
 *   C:\xampp\php\php.exe tests\backend_audit.php [base_url]
 * Default base_url: http://localhost/sqa_afterbill
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/budgets.php';
require_once __DIR__ . '/../includes/password_policy.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../config/mail.php';

$BASE = rtrim($argv[1] ?? 'http://localhost/sqa_afterbill', '/');
$pdo = getPDO();

// K02 reads only what the server logs from this point on.
// PHP logs to php.ini's error_log; Apache's error.log is where it falls back if that is unwritable.
$errorLogStart = [];
foreach (['C:/xampp/php/logs/php_error_log', 'C:/xampp/apache/logs/error.log'] as $log) {
    $errorLogStart[$log] = is_file($log) ? filesize($log) : 0;
}

// ---------------------------------------------------------------------------------------------
// Minimal HTTP client with its own cookie jar (one instance = one browser).
// ---------------------------------------------------------------------------------------------
final class Client
{
    public array $cookies = [];
    /** Extra raw request headers, e.g. ['Host: evil.example']. */
    public array $headers = [];

    public function __construct(private string $base)
    {
    }

    /** @return array{status:int, location:string, body:string, headers:string} */
    public function request(string $method, string $path, array $data = []): array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($this->headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        }
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "$k=$v";
            }
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException('HTTP request failed: ' . curl_error($ch));
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        foreach (preg_split('/\r\n/', $headers) as $line) {
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
                if ($m[2] === 'deleted' || $m[2] === '') {
                    unset($this->cookies[$m[1]]);
                } else {
                    $this->cookies[$m[1]] = $m[2];
                }
            }
        }
        $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';

        return ['status' => $status, 'location' => $location, 'body' => $body, 'headers' => $headers];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** POST, automatically including the CSRF token found on $formPage (if the app renders one). */
    public function post(string $path, array $data, ?string $formPage = null): array
    {
        $formPage ??= $path;
        $page = $this->get($formPage);
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $page['body'], $m)) {
            $data['csrf_token'] ??= $m[1];
        }
        return $this->request('POST', $path, $data);
    }

    /** POST with no CSRF token at all — what a forged cross-site request looks like. */
    public function postRaw(string $path, array $data): array
    {
        return $this->request('POST', $path, $data);
    }
}

// ---------------------------------------------------------------------------------------------
// Tiny test runner.
// ---------------------------------------------------------------------------------------------
$results = [];
$currentGroup = '';

function group(string $name): void
{
    global $currentGroup;
    $currentGroup = $name;
}

function check(string $id, string $name, callable $fn): void
{
    global $results, $currentGroup;
    clearRateLimits();
    try {
        $msg = $fn();
        $ok = $msg === true || $msg === null;
        $results[] = [$currentGroup, $id, $name, $ok, $ok ? '' : (string)$msg];
    } catch (Throwable $e) {
        $results[] = [$currentGroup, $id, $name, false, get_class($e) . ': ' . $e->getMessage()];
    }
}

/** Returns true, or a failure message. */
function expect(bool $cond, string $failMsg)
{
    return $cond ? true : $failMsg;
}

function phpErrorsIn(string $body): ?string
{
    if (preg_match('/(Fatal error|Warning|Notice|Deprecated|Parse error)<\/b>:\s*(.{0,160})/', $body, $m)) {
        return $m[1] . ': ' . strip_tags($m[2]);
    }
    if (preg_match('/Uncaught\s+\w+/', $body, $m)) {
        return $m[0];
    }
    return null;
}

$stamp = date('YmdHis') . random_int(100, 999);
$PASS = 'Harbor#Lights26';

/** The suite makes far more logins/registrations from localhost than the per-IP limits allow. */
function clearRateLimits(): void
{
    try {
        getPDO()->exec("DELETE FROM rate_limits WHERE ip IN ('127.0.0.1', '::1')");
    } catch (PDOException $e) {
        // migration 002 not run: there are no limits to clear
    }
}

/**
 * Registers like a real user: submits the form, opens the verification link from the email,
 * then logs in on the same client. Returns the registration response. With $verify = false it
 * stops after the form (the account stays unverified and signed out).
 */
function registerUser(Client $c, string $name, string $email, string $pass, bool $verify = true): array
{
    $mark = mailMark();
    $r = $c->post('auth/register.php', [
        'full_name'        => $name,
        'email'            => $email,
        'password'         => $pass,
        'confirm_password' => $pass,
        'terms'            => 'on',
    ]);
    if ($verify && ($link = mailLink($email, 'auth/verify_email.php', $mark)) !== null) {
        $c->get($link);
        login($c, $email, $pass);
    }
    return $r;
}

// ---- Emails: the app's "log" mail driver writes one JSON line per email (config/mail.php).
// Addresses ending in .test always go there, even when real SMTP is configured.

/** Current end of the mail log: pass to mailsTo()/mailLink() to see only newer emails. */
function mailMark(): int
{
    global $MAIL_CONFIG;
    clearstatcache();
    return is_file($MAIL_CONFIG['log_file']) ? filesize($MAIL_CONFIG['log_file']) : 0;
}

/** @return array<int, array{to:string, subject:string, body:string}> emails to $to written after $mark */
function mailsTo(string $to, int $mark = 0): array
{
    global $MAIL_CONFIG;
    clearstatcache();
    if (!is_file($MAIL_CONFIG['log_file'])) {
        return [];
    }
    $out = [];
    foreach (explode("\n", (string)file_get_contents($MAIL_CONFIG['log_file'], false, null, $mark)) as $line) {
        $m = json_decode($line, true);
        if (is_array($m) && strcasecmp($m['to'], $to) === 0) {
            $out[] = $m;
        }
    }
    return $out;
}

/** Path (relative to the app) of the newest emailed link to $page for $to, or null. */
function mailLink(string $to, string $page, int $mark = 0): ?string
{
    global $MAIL_CONFIG;
    $prefix = rtrim($MAIL_CONFIG['app_url'], '/') . '/';
    foreach (array_reverse(mailsTo($to, $mark)) as $m) {
        if (preg_match('#' . preg_quote($prefix . $page, '#') . '\?token=[0-9a-f]{64}#', $m['body'], $x)) {
            return substr($x[0], strlen($prefix));
        }
    }
    return null;
}

function login(Client $c, string $email, string $pass): array
{
    return $c->post('auth/login.php', ['email' => $email, 'password' => $pass]);
}

function userIdByEmail(PDO $pdo, string $email): ?int
{
    $s = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $s->execute([$email]);
    $id = $s->fetchColumn();
    return $id === false ? null : (int)$id;
}

function latestBill(PDO $pdo, int $userId): ?array
{
    $s = $pdo->prepare('SELECT * FROM bills WHERE user_id = ? ORDER BY bill_id DESC LIMIT 1');
    $s->execute([$userId]);
    return $s->fetch() ?: null;
}

function billById(PDO $pdo, int $billId): ?array
{
    $s = $pdo->prepare('SELECT * FROM bills WHERE bill_id = ?');
    $s->execute([$billId]);
    return $s->fetch() ?: null;
}

function insertBill(PDO $pdo, int $userId, string $name, string $due, bool $recurring = false, string $status = 'unpaid',
                    float $amount = 100.00, int $categoryId = 1): int
{
    $s = $pdo->prepare(
        'INSERT INTO bills (user_id, bill_name, amount, due_date, category_id, is_recurring, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $s->execute([$userId, $name, $amount, $due, $categoryId, $recurring ? 1 : 0, $status]);
    return (int)$pdo->lastInsertId();
}

function paymentCount(PDO $pdo, int $billId): int
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE bill_id = ?');
    $s->execute([$billId]);
    return (int)$s->fetchColumn();
}

$emailA = "audit_a_{$stamp}@audit.test";
$emailB = "audit_b_{$stamp}@audit.test";
$createdEmails = [$emailA, $emailB];

$a = new Client($BASE);
$b = new Client($BASE);
$anon = new Client($BASE);

$validBill = fn(array $over = []) => array_merge([
    'bill_name'   => 'Audit Electric',
    'amount'      => '1500.50',
    'due_date'    => date('Y-m-d', strtotime('+5 days')),
    'category_id' => '1',
], $over);

try {
    // =========================================================================================
    group('AUTH — Registration');
    // =========================================================================================
    check('A01', 'Valid registration creates the user, and after email verification they can log in', function () use ($a, $emailA, $PASS, $pdo) {
        $r = registerUser($a, 'Audit User A', $emailA, $PASS);
        if (!str_contains($r['body'], 'Check your inbox')) {
            return "no 'check your inbox' page after registering ({$r['status']} -> {$r['location']})";
        }
        if (userIdByEmail($pdo, $emailA) === null) {
            return 'user row not created';
        }
        return expect($a->get('dashboard.php')['status'] === 200, 'could not use the app after verifying and logging in');
    });

    check('A02', 'Duplicate email is rejected', function () use ($emailA, $PASS, $BASE) {
        $c = new Client($BASE);
        $r = registerUser($c, 'Dup User', $emailA, $PASS);
        return expect(str_contains($r['body'], 'already registered'), 'no duplicate-email error shown');
    });

    check('A03', 'Password shorter than 8 chars is rejected', function () use ($stamp, $BASE) {
        $c = new Client($BASE);
        $r = registerUser($c, 'Short Pass', "short_{$stamp}@audit.test", 'Ab1#xyz');
        return expect(str_contains($r['body'], 'must contain: at least 8 characters'), 'short password accepted');
    });

    check('A04', 'Mismatched confirm password is rejected', function () use ($stamp, $BASE) {
        $c = new Client($BASE);
        $r = $c->post('auth/register.php', [
            'full_name' => 'Mismatch', 'email' => "mm_{$stamp}@audit.test",
            'password' => 'Bluefish#42', 'confirm_password' => 'Bluefish#43', 'terms' => 'on',
        ]);
        return expect(str_contains($r['body'], 'do not match'), 'mismatch accepted');
    });

    check('A05', 'Terms must be accepted', function () use ($stamp, $BASE) {
        $c = new Client($BASE);
        $r = $c->post('auth/register.php', [
            'full_name' => 'No Terms', 'email' => "nt_{$stamp}@audit.test",
            'password' => 'Bluefish#42', 'confirm_password' => 'Bluefish#42',
        ]);
        return expect(str_contains($r['body'], 'Terms of Service'), 'registration without terms accepted');
    });

    check('A06', 'Full name over 120 chars is rejected (not silently truncated)', function () use ($stamp, $BASE, $pdo, &$createdEmails) {
        $c = new Client($BASE);
        $email = "longname_{$stamp}@audit.test";
        $createdEmails[] = $email;
        registerUser($c, str_repeat('N', 150), $email, 'Bluefish#42');
        return expect(userIdByEmail($pdo, $email) === null, 'account created with a 150-char name (DB truncated it to 120)');
    });

    check('A07', 'Password over 72 bytes is rejected (bcrypt would silently truncate it)', function () use ($stamp, $BASE, $pdo, &$createdEmails) {
        $c = new Client($BASE);
        $email = "longpass_{$stamp}@audit.test";
        $createdEmails[] = $email;
        registerUser($c, 'Long Pass', $email, 'Aa1#' . str_repeat('p', 76));
        return expect(userIdByEmail($pdo, $email) === null, 'account created with an 80-byte password');
    });

    check('A08', 'Invalid email format is rejected', function () use ($BASE) {
        $c = new Client($BASE);
        $r = registerUser($c, 'Bad Email', 'not-an-email', 'Bluefish#42');
        return expect(str_contains($r['body'], 'valid email'), 'invalid email accepted');
    });

    // Password strength: each rule on its own, with every other rule satisfied.
    $weak = [
        'A16' => ['no uppercase letter', 'password#123', 'one uppercase letter'],
        'A17' => ['no number',           'Password#abc', 'one number'],
        'A18' => ['no special character','Password1234', 'one special character'],
    ];
    foreach ($weak as $id => [$what, $pw, $msg]) {
        check($id, "Password with {$what} is rejected", function () use ($stamp, $BASE, $pdo, &$createdEmails, $id, $pw, $msg) {
            $c = new Client($BASE);
            $email = strtolower($id) . "_{$stamp}@audit.test";
            $createdEmails[] = $email;
            $r = registerUser($c, 'Weak Pass', $email, $pw);
            if (userIdByEmail($pdo, $email) !== null) {
                return 'account created with a weak password';
            }
            return expect(str_contains($r['body'], $msg), "no '{$msg}' error shown");
        });
    }

    check('A19', 'Registration page lists the password rules', function () use ($BASE) {
        $r = (new Client($BASE))->get('auth/register.php');
        return expect(str_contains($r['body'], 'class="pw-rules"') && str_contains($r['body'], 'One special character'),
            'password rules checklist missing');
    });

    // =========================================================================================
    group('AUTH — Login / Session');
    // =========================================================================================
    check('A09', 'Wrong password shows a generic error', function () use ($emailA, $BASE) {
        $c = new Client($BASE);
        $r = login($c, $emailA, 'WrongPassword!');
        return expect(str_contains($r['body'], 'Invalid email or password'), 'no generic login error');
    });

    check('A10', 'Unknown email shows the same generic error (no user enumeration)', function () use ($stamp, $BASE) {
        $c = new Client($BASE);
        $r = login($c, "nobody_{$stamp}@audit.test", 'WrongPassword!');
        return expect(str_contains($r['body'], 'Invalid email or password'), 'different error for unknown email');
    });

    check('A11', 'Valid login redirects to dashboard', function () use ($emailA, $PASS, $BASE) {
        $c = new Client($BASE);
        $r = login($c, $emailA, $PASS);
        return expect($r['status'] === 302 && str_contains($r['location'], 'dashboard.php'), "got {$r['status']} -> {$r['location']}");
    });

    check('A12', 'Session id is regenerated on login (session fixation)', function () use ($emailA, $PASS, $BASE) {
        $fixed = 'auditfixation' . bin2hex(random_bytes(8));
        $victim = new Client($BASE);
        $victim->cookies['PHPSESSID'] = $fixed;
        login($victim, $emailA, $PASS);

        $attacker = new Client($BASE);
        $attacker->cookies['PHPSESSID'] = $fixed;
        $r = $attacker->get('dashboard.php');
        return expect($r['status'] === 302, 'attacker-chosen session id is logged in after the victim signs in');
    });

    check('A13', 'Session id is regenerated on registration (session fixation)', function () use ($stamp, $BASE, &$createdEmails) {
        $fixed = 'auditfixation' . bin2hex(random_bytes(8));
        $email = "fix_{$stamp}@audit.test";
        $createdEmails[] = $email;
        $victim = new Client($BASE);
        $victim->cookies['PHPSESSID'] = $fixed;
        registerUser($victim, 'Fixation Victim', $email, 'Bluefish#42');

        $attacker = new Client($BASE);
        $attacker->cookies['PHPSESSID'] = $fixed;
        $r = $attacker->get('dashboard.php');
        return expect($r['status'] === 302, 'attacker-chosen session id is logged in after the victim registers');
    });

    check('A14', 'Every protected page redirects anonymous users to login', function () use ($anon) {
        $pages = ['dashboard.php', 'bills.php', 'add_bill.php', 'edit_bill.php?bill_id=1', 'mark_paid.php?bill_id=1',
                  'calendar.php', 'reports.php', 'payments.php', 'reminders.php', 'account.php'];
        foreach ($pages as $p) {
            $r = $anon->get($p);
            if ($r['status'] !== 302 || !str_contains($r['location'], 'login.php')) {
                return "$p returned {$r['status']} -> {$r['location']}";
            }
        }
        foreach (['delete_bill.php', 'undo_payment.php'] as $p) {
            $r = $anon->postRaw($p, ['bill_id' => 1]);
            if ($r['status'] !== 302 || !str_contains($r['location'], 'login.php')) {
                return "POST $p returned {$r['status']} -> {$r['location']}";
            }
        }
        return true;
    });

    check('A15', 'Logout (POST + token) ends the session', function () use ($emailA, $PASS, $BASE) {
        $c = new Client($BASE);
        login($c, $emailA, $PASS);
        $c->post('auth/logout.php', [], 'dashboard.php');
        $r = $c->get('dashboard.php');
        return expect($r['status'] === 302, 'still logged in after logout');
    });

    check('L01', 'Logout via GET does not log the user out (forged link / <img> tag)', function () use ($emailA, $PASS, $BASE) {
        $c = new Client($BASE);
        login($c, $emailA, $PASS);
        $c->get('auth/logout.php');
        $r = $c->get('dashboard.php');
        return expect($r['status'] === 200, 'a plain GET to logout.php ended the session');
    });

    check('L02', 'Logout via POST without a CSRF token does not log the user out', function () use ($emailA, $PASS, $BASE) {
        $c = new Client($BASE);
        login($c, $emailA, $PASS);
        $c->postRaw('auth/logout.php', []);
        $r = $c->get('dashboard.php');
        return expect($r['status'] === 200, 'a forged POST to logout.php ended the session');
    });

    check('L03', 'Every app page renders logout as a POST form with a CSRF token', function () use ($emailA, $PASS, $BASE) {
        $c = new Client($BASE);
        login($c, $emailA, $PASS);
        foreach (['dashboard.php', 'bills.php', 'add_bill.php', 'calendar.php', 'reports.php', 'payments.php', 'reminders.php', 'account.php', 'budgets.php', 'activity.php'] as $p) {
            $body = $c->get($p)['body'];
            if (str_contains($body, 'href="auth/logout.php"')) {
                return "$p still has a GET logout link";
            }
            if (!preg_match('#<form[^>]*method="POST"[^>]*action="auth/logout\.php"[^>]*>\s*<input type="hidden" name="csrf_token"#i', $body)) {
                return "$p has no POST logout form with a CSRF token";
            }
        }
        return true;
    });

    // Second user for ownership tests.
    registerUser($b, 'Audit User B', $emailB, $PASS);
    $userA = userIdByEmail($pdo, $emailA);
    $userB = userIdByEmail($pdo, $emailB);
    if (!$userA || !$userB) {
        throw new RuntimeException('Could not create the audit users — cannot continue.');
    }

    // =========================================================================================
    group('BILLS — Create / Validate');
    // =========================================================================================
    check('B01', 'Valid bill is created', function () use ($a, $pdo, $userA, $validBill) {
        $r = $a->post('add_bill.php', $validBill());
        if ($r['status'] !== 302 || !str_contains($r['location'], 'added=1')) {
            return "expected redirect to bills.php?added=1, got {$r['status']} -> {$r['location']}";
        }
        $bill = latestBill($pdo, $userA);
        return expect($bill && $bill['bill_name'] === 'Audit Electric' && (float)$bill['amount'] === 1500.50, 'bill row not stored correctly');
    });

    $amountCases = ['0', '-5', 'abc', '', '100000000'];
    check('B02', 'Invalid amounts are rejected (0, negative, text, empty, too large)', function () use ($a, $validBill, $amountCases) {
        foreach ($amountCases as $amt) {
            $r = $a->post('add_bill.php', $validBill(['amount' => $amt]));
            if ($r['status'] === 302) {
                return "amount '$amt' was accepted";
            }
        }
        return true;
    });

    check('B03', 'Impossible calendar date (2026-02-30) is rejected', function () use ($a, $validBill) {
        $r = $a->post('add_bill.php', $validBill(['due_date' => '2026-02-30']));
        return expect($r['status'] !== 302, 'Feb 30 accepted');
    });

    check('B04', 'Unknown category is rejected', function () use ($a, $validBill) {
        $r = $a->post('add_bill.php', $validBill(['category_id' => '999']));
        return expect($r['status'] !== 302, 'category 999 accepted');
    });

    check('B05', 'Bill name length is enforced (1 char / 101 chars)', function () use ($a, $validBill) {
        foreach (['X', str_repeat('Y', 101)] as $name) {
            $r = $a->post('add_bill.php', $validBill(['bill_name' => $name]));
            if ($r['status'] === 302) {
                return 'name of length ' . strlen($name) . ' accepted';
            }
        }
        return true;
    });

    check('B06', 'Absurd due-date years are rejected (e.g. 1900, 9999)', function () use ($a, $pdo, $userA, $validBill) {
        foreach (['1900-01-01', '9999-12-31'] as $d) {
            $r = $a->post('add_bill.php', $validBill(['due_date' => $d, 'bill_name' => "Year test $d"]));
            if ($r['status'] === 302) {
                // Remove it right away so the recurring generator never sees it.
                $pdo->prepare('DELETE FROM bills WHERE user_id = ? AND bill_name = ?')->execute([$userA, "Year test $d"]);
                return "due date $d accepted";
            }
        }
        return true;
    });

    check('B07', 'Bill name with HTML is escaped on every page (XSS)', function () use ($a, $pdo, $userA) {
        $payload = '<script>alert("xss")</script>';
        insertBill($pdo, $userA, $payload, date('Y-m-d', strtotime('+1 day')));
        foreach (['bills.php', 'dashboard.php', 'calendar.php', 'reminders.php', 'reports.php'] as $p) {
            $r = $a->get($p);
            if (str_contains($r['body'], $payload)) {
                return "unescaped payload on $p";
            }
        }
        return true;
    });

    check('B08', 'Search / filter params with junk values do not break bills.php', function () use ($a) {
        foreach (['bills.php?status=hacked', 'bills.php?category_id=abc', "bills.php?q=%25'%22--", 'bills.php?q=' . str_repeat('z', 500)] as $p) {
            $r = $a->get($p);
            if ($r['status'] !== 200 || ($e = phpErrorsIn($r['body']))) {
                return "$p -> {$r['status']} " . ($e ?? '');
            }
        }
        return true;
    });

    // =========================================================================================
    group('BILLS — Edit / Pay / Undo / Delete');
    // =========================================================================================
    $billA = insertBill($pdo, $userA, 'Audit Water', date('Y-m-d', strtotime('+3 days')));

    check('B09', 'Owner can edit their bill', function () use ($a, $pdo, $billA) {
        $r = $a->post('edit_bill.php', [
            'bill_id' => $billA, 'bill_name' => 'Audit Water Edited', 'amount' => '222.22',
            'due_date' => date('Y-m-d', strtotime('+4 days')), 'category_id' => '2',
        ], "edit_bill.php?bill_id=$billA");
        $bill = billById($pdo, $billA);
        return expect($r['status'] === 302 && $bill['bill_name'] === 'Audit Water Edited' && (float)$bill['amount'] === 222.22, 'edit not saved');
    });

    check('B10', 'Mark as paid rejects a future payment date', function () use ($a, $billA) {
        $r = $a->post('mark_paid.php', [
            'bill_id' => $billA, 'payment_date' => date('Y-m-d', strtotime('+10 days')),
            'amount_paid' => '222.22', 'payment_method' => 'cash',
        ], "mark_paid.php?bill_id=$billA");
        return expect($r['status'] !== 302, 'future payment date accepted');
    });

    check('B11', 'Mark as paid rejects an unknown payment method', function () use ($a, $billA) {
        $r = $a->post('mark_paid.php', [
            'bill_id' => $billA, 'payment_date' => date('Y-m-d'),
            'amount_paid' => '222.22', 'payment_method' => 'bitcoin',
        ], "mark_paid.php?bill_id=$billA");
        return expect($r['status'] !== 302, 'payment method "bitcoin" accepted');
    });

    check('B12', 'Mark as paid records a payment and flips status to paid', function () use ($a, $pdo, $billA) {
        $a->post('mark_paid.php', [
            'bill_id' => $billA, 'payment_date' => date('Y-m-d'),
            'amount_paid' => '222.22', 'payment_method' => 'gcash', 'notes' => '=HYPERLINK("http://evil.test","click")',
        ], "mark_paid.php?bill_id=$billA");
        return expect(paymentCount($pdo, $billA) === 1 && billById($pdo, $billA)['status'] === 'paid', 'payment not recorded');
    });

    check('B13', 'Already-paid bill cannot be paid twice', function () use ($a, $pdo, $billA) {
        $a->postRaw('mark_paid.php', [
            'bill_id' => $billA, 'payment_date' => date('Y-m-d'),
            'amount_paid' => '1', 'payment_method' => 'cash',
        ]);
        return expect(paymentCount($pdo, $billA) === 1, 'second payment recorded on a paid bill');
    });

    check('B14', 'Bill with payment history cannot be deleted', function () use ($a, $pdo, $billA) {
        $r = $a->post('delete_bill.php', ['bill_id' => $billA], 'bills.php');
        return expect(billById($pdo, $billA) !== null && str_contains($r['location'], 'delete_blocked'), 'paid bill was deleted');
    });

    check('B15', 'Delete via GET does nothing', function () use ($a, $pdo, $billA) {
        $a->get("delete_bill.php?bill_id=$billA");
        return expect(billById($pdo, $billA) !== null, 'bill deleted via GET');
    });

    check('P01', 'CSV export neutralises spreadsheet formulas (CSV injection)', function () use ($a) {
        $r = $a->get('payments.php?export=csv');
        if (!str_contains($r['headers'], 'text/csv')) {
            return 'export did not return CSV';
        }
        foreach (str_getcsv_lines($r['body']) as $row) {
            foreach ($row as $cell) {
                if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true) && !is_numeric($cell)) {
                    return "formula cell exported as-is: $cell";
                }
            }
        }
        return true;
    });

    check('P03', 'CSV export escapes every dangerous prefix: = + - @ tab CR (notes and bill name)', function () use ($a, $pdo, $userA) {
        $prefixes = ['=' => '=1+1', '+' => '+1+1', '-' => '-1+1', '@' => '@SUM(A1)', 'TAB' => "\tcmd", 'CR' => "\rcmd"];
        $ins = $pdo->prepare(
            "INSERT INTO payments (bill_id, payment_date, amount_paid, payment_method, notes) VALUES (?, CURDATE(), 1.00, 'cash', ?)"
        );
        // Stored directly: the forms trim() input, but data can also arrive from imports/older code.
        foreach ($prefixes as $label => $value) {
            $billId = insertBill($pdo, $userA, $value . " bill $label", date('Y-m-d'), false, 'paid');
            $ins->execute([$billId, $value]);
        }
        $rows = str_getcsv_lines($a->get('payments.php?export=csv')['body']);
        $header = array_shift($rows);
        $billCol = array_search('Bill', $header, true);
        $notesCol = array_search('Notes', $header, true);
        $seen = [];
        foreach ($rows as $row) {
            foreach ($prefixes as $label => $value) {
                if ($row[$notesCol] === $value || $row[$notesCol] === "'" . $value) {
                    $seen[$label] = true;
                    if ($row[$notesCol] !== "'" . $value) {
                        return "notes cell with prefix $label exported unescaped";
                    }
                    if ($row[$billCol] !== "'" . $value . " bill $label") {
                        return "bill name cell with prefix $label exported unescaped";
                    }
                }
            }
        }
        $missing = array_diff(array_keys($prefixes), array_keys($seen));
        return expect(!$missing, 'rows not found in export for: ' . implode(', ', $missing));
    });

    check('P02', 'Payments page filters with junk values do not break', function () use ($a) {
        foreach (['payments.php?month=2026-13', 'payments.php?method=bitcoin', 'payments.php?month=abc'] as $p) {
            $r = $a->get($p);
            if ($r['status'] !== 200 || ($e = phpErrorsIn($r['body']))) {
                return "$p -> {$r['status']} " . ($e ?? '');
            }
        }
        return true;
    });

    check('B16', 'Undo payment puts the bill back to unpaid', function () use ($a, $pdo, $billA) {
        $a->post('undo_payment.php', ['bill_id' => $billA], 'bills.php');
        return expect(paymentCount($pdo, $billA) === 0 && billById($pdo, $billA)['status'] === 'unpaid', 'undo failed');
    });

    check('B17', 'Bill without payments can be deleted', function () use ($a, $pdo, $billA) {
        $r = $a->post('delete_bill.php', ['bill_id' => $billA], 'bills.php');
        return expect(billById($pdo, $billA) === null && str_contains($r['location'], 'deleted=1'), 'bill not deleted');
    });

    // =========================================================================================
    group('SECURITY — Ownership (IDOR)');
    // =========================================================================================
    $victimBill = insertBill($pdo, $userA, 'Victim Rent', date('Y-m-d', strtotime('+6 days')));

    check('S01', "Another user cannot view/edit someone else's bill", function () use ($b, $pdo, $victimBill) {
        $r = $b->get("edit_bill.php?bill_id=$victimBill");
        if ($r['status'] !== 302) {
            return 'edit form rendered for a bill the user does not own';
        }
        $b->post('edit_bill.php', [
            'bill_id' => $victimBill, 'bill_name' => 'Hijacked', 'amount' => '1',
            'due_date' => date('Y-m-d'), 'category_id' => '1',
        ], 'add_bill.php');
        return expect(billById($pdo, $victimBill)['bill_name'] === 'Victim Rent', 'bill was modified by another user');
    });

    check('S02', "Another user cannot pay someone else's bill", function () use ($b, $pdo, $victimBill) {
        $b->post('mark_paid.php', [
            'bill_id' => $victimBill, 'payment_date' => date('Y-m-d'), 'amount_paid' => '1', 'payment_method' => 'cash',
        ], 'add_bill.php');
        return expect(paymentCount($pdo, $victimBill) === 0, 'payment recorded by another user');
    });

    check('S03', "Another user cannot delete someone else's bill", function () use ($b, $pdo, $victimBill) {
        $b->post('delete_bill.php', ['bill_id' => $victimBill], 'bills.php');
        return expect(billById($pdo, $victimBill) !== null, 'bill deleted by another user');
    });

    check('S04', "Another user cannot undo someone else's payment", function () use ($a, $b, $pdo, $victimBill) {
        $a->post('mark_paid.php', [
            'bill_id' => $victimBill, 'payment_date' => date('Y-m-d'), 'amount_paid' => '100', 'payment_method' => 'cash',
        ], "mark_paid.php?bill_id=$victimBill");
        $pid = (int)$pdo->query('SELECT MAX(payment_id) FROM payments WHERE bill_id = ' . (int)$victimBill)->fetchColumn();
        $b->post('undo_payment.php', ['payment_id' => $pid, 'return' => 'payments'], 'payments.php');
        return expect(paymentCount($pdo, $victimBill) === 1, 'payment removed by another user');
    });

    // =========================================================================================
    group('SECURITY — CSRF');
    // =========================================================================================
    $csrfBill = insertBill($pdo, $userA, 'CSRF Target', date('Y-m-d', strtotime('+2 days')));

    check('S05', 'Forged POST (no token) cannot delete a bill', function () use ($a, $pdo, $csrfBill) {
        $a->postRaw('delete_bill.php', ['bill_id' => $csrfBill]);
        return expect(billById($pdo, $csrfBill) !== null, 'bill deleted by a request with no CSRF token');
    });

    check('S06', 'Forged POST (no token) cannot add a bill', function () use ($a, $validBill) {
        $r = $a->postRaw('add_bill.php', $validBill(['bill_name' => 'Forged Bill']));
        return expect($r['status'] !== 302, 'bill created by a request with no CSRF token');
    });

    check('S07', 'Forged POST (no token) cannot change the account email', function () use ($a, $pdo, $userA, $emailA) {
        $a->postRaw('account.php', ['action' => 'profile', 'full_name' => 'Audit User A', 'email' => "pwned_{$userA}@audit.test"]);
        $email = $pdo->query('SELECT email FROM users WHERE id = ' . (int)$userA)->fetchColumn();
        if ($email !== $emailA) {
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$emailA, $userA]);
            return 'account email changed by a request with no CSRF token';
        }
        return true;
    });

    check('S08', 'Forged POST (no token) cannot mark a bill paid', function () use ($a, $pdo, $userA) {
        // Own bill: S05 may already have deleted $csrfBill when CSRF protection is missing.
        $csrfBill = insertBill($pdo, $userA, 'CSRF Pay Target', date('Y-m-d', strtotime('+2 days')));
        $a->postRaw('mark_paid.php', [
            'bill_id' => $csrfBill, 'payment_date' => date('Y-m-d'), 'amount_paid' => '1', 'payment_method' => 'cash',
        ]);
        return expect(paymentCount($pdo, $csrfBill) === 0, 'payment recorded by a request with no CSRF token');
    });

    // =========================================================================================
    group('RECURRING BILLS');
    // =========================================================================================
    check('R01', 'Past-due recurring bill generates its next cycles exactly once', function () use ($a, $pdo, $userA) {
        $start = date('Y-m-d', strtotime('-2 months -3 days'));
        insertBill($pdo, $userA, 'Recurring Plain', $start, true);
        $a->get('bills.php');
        $count = fn() => (int)$pdo->query("SELECT COUNT(*) FROM bills WHERE user_id = $userA AND bill_name = 'Recurring Plain'")->fetchColumn();
        $first = $count();
        $a->get('bills.php');
        $a->get('dashboard.php');
        // e.g. root Jul 30 (past) -> Aug 30 (past) -> Sep 30 (past) -> Oct 30 (first future cycle,
        // generation stops there): root + 3 generated.
        if ($first !== 4) {
            return "expected 4 bills (root + 3 cycles up to the first future one), got $first";
        }
        return expect($count() === $first, 'reloading pages generated duplicates');
    });

    check('R02', 'Recurring bill due on the 31st keeps its day (no drift to the 28th)', function () use ($a, $pdo, $userA) {
        // Find the 31st of a month at least 3 months back so the chain crosses a short month.
        $m = new DateTime('first day of -4 months');
        while ((int)$m->format('t') !== 31) {
            $m->modify('-1 month');
        }
        $start = $m->format('Y-m-31');
        insertBill($pdo, $userA, 'Recurring 31st', $start, true);
        $a->get('bills.php');

        $dates = $pdo->query("SELECT due_date FROM bills WHERE user_id = $userA AND bill_name = 'Recurring 31st' ORDER BY due_date")
                     ->fetchAll(PDO::FETCH_COLUMN);
        $cursor = new DateTime($start);
        foreach ($dates as $i => $actual) {
            $target = (clone $cursor)->modify('first day of +' . $i . ' month');
            $expected = $target->format('Y-m-') . min(31, (int)$target->format('t'));
            if ($actual !== $expected) {
                return 'chain ' . implode(', ', $dates) . " — cycle $i expected $expected, got $actual";
            }
        }
        return true;
    });

    check('R03', 'Years-old recurring bill catches up in ONE load, then generation stops', function () use ($a, $pdo, $userA) {
        // Inserted directly: the form now refuses dates before 2000, but legacy rows can exist.
        insertBill($pdo, $userA, 'Recurring Ancient', '2001-01-15', true);
        $count = fn() => (int)$pdo->query("SELECT COUNT(*) FROM bills WHERE user_id = $userA AND bill_name = 'Recurring Ancient'")->fetchColumn();
        $a->get('bills.php');
        $afterFirst = $count();
        $a->get('bills.php');
        $a->get('dashboard.php');
        $afterThird = $count();
        if ($afterThird !== $afterFirst) {
            return "still generating on later loads: $afterFirst after load 1, $afterThird after load 3";
        }
        return expect($afterFirst <= 14, "one series produced $afterFirst bills (expected at most 12 months of back-fill + current)");
    });

    check('R04', 'A deleted recurring cycle is not re-created on the next load', function () use ($a, $pdo, $userA) {
        insertBill($pdo, $userA, 'Recurring Deleted', date('Y-m-d', strtotime('-20 days')), true);
        $a->get('bills.php'); // generates the next cycle
        $ids = fn() => $pdo->query("SELECT bill_id FROM bills WHERE user_id = $userA AND bill_name = 'Recurring Deleted' ORDER BY due_date")
                           ->fetchAll(PDO::FETCH_COLUMN);
        $before = $ids();
        if (count($before) !== 2) {
            return 'setup: expected 2 bills, got ' . count($before);
        }
        $a->post('delete_bill.php', ['bill_id' => end($before)], 'bills.php');
        $a->get('bills.php');
        $a->get('dashboard.php');
        $after = $ids();
        return expect(count($after) === 1, 'deleted cycle came back: ' . count($after) . ' bills after reload');
    });

    check('R05', 'Deleting a middle cycle does not re-create it (rest of the series continues)', function () use ($a, $pdo, $userA) {
        insertBill($pdo, $userA, 'Recurring Middle', date('Y-m-d', strtotime('-50 days')), true);
        $a->get('bills.php');
        $rows = fn() => $pdo->query("SELECT bill_id, due_date FROM bills WHERE user_id = $userA AND bill_name = 'Recurring Middle' ORDER BY due_date")
                            ->fetchAll();
        $before = $rows();
        if (count($before) !== 3) {
            return 'setup: expected 3 bills, got ' . count($before);
        }
        $a->post('delete_bill.php', ['bill_id' => $before[1]['bill_id']], 'bills.php');
        $a->get('bills.php');
        $after = array_column($rows(), 'due_date');
        return expect($after === [$before[0]['due_date'], $before[2]['due_date']],
            'expected ' . $before[0]['due_date'] . ', ' . $before[2]['due_date'] . ' — got ' . implode(', ', $after));
    });

    // =========================================================================================
    group('PAGES — Health');
    // =========================================================================================
    check('H01', 'All app pages load (200) without PHP errors', function () use ($a) {
        foreach (['dashboard.php', 'bills.php', 'add_bill.php', 'calendar.php', 'reports.php', 'payments.php', 'reminders.php', 'account.php', 'budgets.php', 'activity.php', 'index.php'] as $p) {
            $r = $a->get($p);
            if ($r['status'] !== 200 || ($e = phpErrorsIn($r['body']))) {
                return "$p -> {$r['status']} " . ($e ?? '');
            }
        }
        return true;
    });

    check('H02', 'Month / window params with junk values do not break pages', function () use ($a) {
        foreach (['calendar.php?month=2026-13', 'calendar.php?month=abc', 'reports.php?month=0000-01',
                  'reports.php?month=2026-00', 'reminders.php?days=999', 'reminders.php?days=-1'] as $p) {
            $r = $a->get($p);
            if ($r['status'] !== 200 || ($e = phpErrorsIn($r['body']))) {
                return "$p -> {$r['status']} " . ($e ?? '');
            }
        }
        return true;
    });

    // =========================================================================================
    group('ACCOUNT');
    // =========================================================================================
    check('C01', 'Profile email cannot be changed to another user\'s email', function () use ($a, $emailB, $PASS) {
        $r = $a->post('account.php', ['action' => 'profile', 'full_name' => 'Audit User A', 'email' => $emailB, 'current_password' => $PASS]);
        return expect(str_contains($r['body'], 'already used by another account'), 'duplicate email accepted');
    });

    check('C02', 'Password change requires the correct current password', function () use ($a) {
        $r = $a->post('account.php', [
            'action' => 'password', 'current_password' => 'nope-wrong',
            'new_password' => 'NewOcean#2026', 'confirm_password' => 'NewOcean#2026',
        ]);
        return expect(str_contains($r['body'], 'Current password is incorrect'), 'wrong current password accepted');
    });

    check('C03', 'Password change works and the new password logs in', function () use ($a, $emailA, $PASS, $BASE) {
        $r = $a->post('account.php', [
            'action' => 'password', 'current_password' => $PASS,
            'new_password' => 'NewOcean#2026', 'confirm_password' => 'NewOcean#2026',
        ]);
        if (!str_contains($r['location'], 'updated=password')) {
            return "password change failed: {$r['status']} -> {$r['location']}";
        }
        $c = new Client($BASE);
        $l = login($c, $emailA, 'NewOcean#2026');
        return expect($l['status'] === 302 && str_contains($l['location'], 'dashboard.php'), 'new password does not log in');
    });

    check('C04', 'Password change rejects a weak new password', function () use ($a) {
        $r = $a->post('account.php', [
            'action' => 'password', 'current_password' => 'NewOcean#2026',
            'new_password' => 'weakpassword', 'confirm_password' => 'weakpassword',
        ]);
        return expect(!str_contains($r['location'], 'updated=password') && str_contains($r['body'], 'New password must contain'),
            'weak new password accepted');
    });

    // =========================================================================================
    group('NEW MODULE — Budgets');
    // =========================================================================================
    // User B has no bills of its own, so its totals are fully controlled here.
    $budgetRow = function (int $userId, int $catId) use ($pdo) {
        $s = $pdo->prepare('SELECT monthly_limit FROM budgets WHERE user_id = ? AND category_id = ?');
        $s->execute([$userId, $catId]);
        $v = $s->fetchColumn();
        return $v === false ? null : (float)$v;
    };
    $thisMonth1st = date('Y-m-01');
    $thisMonthLast = date('Y-m-t');
    $nextMonth1st = (new DateTime('first day of next month'))->format('Y-m-d');

    check('U01', 'Budgets page loads and lists every category', function () use ($b, $pdo) {
        $r = $b->get('budgets.php');
        if ($r['status'] !== 200 || ($e = phpErrorsIn($r['body']))) {
            return "budgets.php -> {$r['status']} " . ($e ?? '');
        }
        foreach ($pdo->query('SELECT category_name FROM categories')->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (!str_contains($r['body'], htmlspecialchars($name))) {
                return "category $name missing";
            }
        }
        return true;
    });

    check('U02', 'Setting a budget saves it', function () use ($b, $budgetRow, $userB) {
        $r = $b->post('budgets.php', ['action' => 'save', 'category_id' => 1, 'monthly_limit' => '500']);
        if (!str_contains($r['location'], 'saved=1')) {
            return "expected redirect with saved=1, got {$r['status']} -> {$r['location']}";
        }
        return expect($budgetRow($userB, 1) === 500.0, 'budget row not stored as 500');
    });

    check('U03', 'Setting it again updates the same budget (no duplicate row)', function () use ($b, $pdo, $budgetRow, $userB) {
        $b->post('budgets.php', ['action' => 'save', 'category_id' => 1, 'monthly_limit' => '800.50']);
        $rows = (int)$pdo->query("SELECT COUNT(*) FROM budgets WHERE user_id = $userB AND category_id = 1")->fetchColumn();
        return expect($rows === 1 && $budgetRow($userB, 1) === 800.5, "rows=$rows limit=" . $budgetRow($userB, 1));
    });

    check('U04', 'Invalid budget amounts are rejected (0, negative, text, empty, too large)', function () use ($b, $budgetRow, $userB) {
        foreach (['0', '-1', 'abc', '', '100000000'] as $v) {
            $r = $b->post('budgets.php', ['action' => 'save', 'category_id' => 1, 'monthly_limit' => $v]);
            if ($r['status'] === 302 || !str_contains($r['body'], 'Budget must be an amount')) {
                return "amount '$v' accepted or no error shown";
            }
        }
        return expect($budgetRow($userB, 1) === 800.5, 'budget changed by an invalid amount');
    });

    check('U05', 'Unknown category / unknown action are rejected', function () use ($b, $pdo, $userB) {
        $r1 = $b->post('budgets.php', ['action' => 'save', 'category_id' => 999, 'monthly_limit' => '100']);
        $r2 = $b->post('budgets.php', ['action' => 'drop_table', 'category_id' => 1]);
        $rows = (int)$pdo->query("SELECT COUNT(*) FROM budgets WHERE user_id = $userB")->fetchColumn();
        return expect($r1['status'] !== 302 && $r2['status'] !== 302 && $rows === 1, "r1={$r1['status']} r2={$r2['status']} rows=$rows");
    });

    check('U06', '"Spent" = bills due this month in the category, paid + unpaid (unit check)', function () use ($pdo, $userB, $thisMonth1st, $thisMonthLast, $nextMonth1st) {
        insertBill($pdo, $userB, 'B Power', $thisMonth1st, false, 'unpaid', 300.00, 1);
        insertBill($pdo, $userB, 'B Water', $thisMonthLast, false, 'paid', 600.00, 1);
        insertBill($pdo, $userB, 'B Next Month', $nextMonth1st, false, 'unpaid', 1000.00, 1); // other month
        insertBill($pdo, $userB, 'B Internet', $thisMonth1st, false, 'unpaid', 50.00, 2);     // other category
        $u = budgetUsage($pdo, $userB, new DateTime($thisMonth1st))[1];
        if ($u['spent'] !== 900.0 || $u['count'] !== 2) {
            return "spent={$u['spent']} count={$u['count']} (expected 900 / 2)";
        }
        return expect($u['status'] === 'over' && round($u['pct'], 1) === 112.4, "status={$u['status']} pct={$u['pct']}");
    });

    check('U07', 'Page shows over-budget status, negative remaining and the alert', function () use ($b) {
        $body = $b->get('budgets.php')['body'];
        foreach (['Over budget', '−₱99.50', 'over budget in'] as $needle) {
            if (!str_contains($body, $needle)) {
                return "missing \"$needle\" on budgets.php";
            }
        }
        return true;
    });

    check('U08', 'Near-limit status at >= 80%', function () use ($b, $pdo, $userB) {
        $b->post('budgets.php', ['action' => 'save', 'category_id' => 2, 'monthly_limit' => '60']); // 50 / 60 = 83%
        $u = budgetUsage($pdo, $userB, new DateTime(date('Y-m-01')))[2];
        return expect($u['status'] === 'near', "status={$u['status']} pct={$u['pct']}");
    });

    check('U09', 'Sidebar shows an over-budget badge on every page', function () use ($b) {
        $body = $b->get('dashboard.php')['body'];
        return expect((bool)preg_match('/title="Categories over budget this month">\s*1\s*</', $body), 'badge with count 1 not found');
    });

    check('U10', 'Adding a bill that goes over budget warns once on My Bills', function () use ($b, $pdo, $userB, $thisMonth1st) {
        $b->post('budgets.php', ['action' => 'save', 'category_id' => 3, 'monthly_limit' => '500']);
        $r = $b->post('add_bill.php', [
            'bill_name' => 'B Rent', 'amount' => '600', 'due_date' => $thisMonth1st, 'category_id' => '3',
        ]);
        if (!str_contains($r['location'], 'added=1')) {
            return "bill not added: {$r['status']} -> {$r['location']}";
        }
        $first = $b->get('bills.php?added=1')['body'];
        $second = $b->get('bills.php')['body'];
        if (!str_contains($first, 'Housing is now over budget')) {
            return 'no over-budget warning after adding the bill';
        }
        return expect(!str_contains($second, 'is now over budget'), 'warning shown again on the next load');
    });

    check('U11', 'A bill under budget gives no warning', function () use ($b, $thisMonth1st) {
        $b->post('add_bill.php', ['bill_name' => 'B Cheap', 'amount' => '1', 'due_date' => $thisMonth1st, 'category_id' => '4']);
        return expect(!str_contains($b->get('bills.php')['body'], 'is now over budget'), 'warning shown for a category without a budget');
    });

    check('U12', 'Removing a budget deletes it', function () use ($b, $budgetRow, $userB) {
        $r = $b->post('budgets.php', ['action' => 'remove', 'category_id' => 2]);
        return expect(str_contains($r['location'], 'removed=1') && $budgetRow($userB, 2) === null, 'budget not removed');
    });

    check('U13', 'Forged POST (no CSRF token) cannot change a budget', function () use ($b, $budgetRow, $userB) {
        $b->postRaw('budgets.php', ['action' => 'save', 'category_id' => 1, 'monthly_limit' => '1']);
        $b->postRaw('budgets.php', ['action' => 'remove', 'category_id' => 1]);
        return expect($budgetRow($userB, 1) === 800.5, 'budget changed without a CSRF token');
    });

    check('U14', "Budgets are per user: A cannot see or remove B's budget", function () use ($a, $budgetRow, $userA, $userB) {
        $body = $a->get('budgets.php')['body'];
        if (str_contains($body, '800.50')) {
            return "B's budget visible on A's page";
        }
        $a->post('budgets.php', ['action' => 'remove', 'category_id' => 1]);
        return expect($budgetRow($userB, 1) === 800.5 && $budgetRow($userA, 1) === null, "B's budget affected by A");
    });

    check('U15', 'Month navigation and junk month values work', function () use ($b, $pdo, $userB) {
        $next = (new DateTime('first day of next month'))->format('Y-m');
        $r = $b->get("budgets.php?month=$next");
        if (!str_contains($r['body'], (new DateTime("$next-01"))->format('F Y')) || !str_contains($r['body'], '₱1,000.00')) {
            return "next month page does not show next month's ₱1,000.00 bill";
        }
        foreach (['budgets.php?month=2026-13', 'budgets.php?month=abc', 'budgets.php?month[]=1'] as $p) {
            $r = $b->get($p);
            if ($r['status'] !== 200 || ($e = phpErrorsIn($r['body']))) {
                return "$p -> {$r['status']} " . ($e ?? '');
            }
        }
        return true;
    });

    // =========================================================================================
    group('NEW MODULE — Activity Log');
    // =========================================================================================
    $actionCount = function (int $userId, string $action) use ($pdo): int {
        $s = $pdo->prepare('SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND action = ?');
        $s->execute([$userId, $action]);
        return (int)$s->fetchColumn();
    };

    check('V01', 'Activity page loads and shows the user\'s events', function () use ($a) {
        $r = $a->get('activity.php');
        if ($r['status'] !== 200 || ($e = phpErrorsIn($r['body']))) {
            return "activity.php -> {$r['status']} " . ($e ?? '');
        }
        return expect(str_contains($r['body'], 'Account created') && str_contains($r['body'], 'Logged in'), 'registration/login events not shown');
    });

    check('V02', 'Every tracked action wrote a log entry', function () use ($actionCount, $userA, $userB) {
        $expected = [
            [$userA, 'register'], [$userA, 'login'], [$userA, 'login_failed'], [$userA, 'logout'],
            [$userA, 'bill_added'], [$userA, 'bill_updated'], [$userA, 'bill_deleted'],
            [$userA, 'payment_recorded'], [$userA, 'payment_undone'], [$userA, 'password_changed'],
            [$userB, 'budget_set'], [$userB, 'budget_removed'],
        ];
        $missing = [];
        foreach ($expected as [$uid, $action]) {
            if ($actionCount($uid, $action) === 0) {
                $missing[] = $action;
            }
        }
        return expect(!$missing, 'no log entry for: ' . implode(', ', $missing));
    });

    check('V03', 'Profile change is logged with what changed', function () use ($a, $pdo, $userA) {
        $a->post('account.php', ['action' => 'profile', 'full_name' => 'Audit User A Renamed', 'email' => (string)$pdo->query("SELECT email FROM users WHERE id = $userA")->fetchColumn()]);
        $d = $pdo->query("SELECT details FROM activity_log WHERE user_id = $userA AND action = 'profile_updated' ORDER BY log_id DESC LIMIT 1")->fetchColumn();
        return expect($d === 'Changed name', 'details: ' . var_export($d, true));
    });

    check('V04', 'Rejected actions are NOT logged (forged/invalid requests)', function () use ($actionCount, $userB) {
        // U04/U05/U13 sent invalid and forged budget saves; only the 4 valid saves may be logged
        // (U02, U03, U08, U10).
        $n = $actionCount($userB, 'budget_set');
        return expect($n === 4, "expected 4 budget_set entries, got $n");
    });

    check('V05', 'Failed logins are shown as a warning to the account owner', function () use ($a) {
        return expect(str_contains($a->get('activity.php')['body'], 'failed login attempt'), 'no failed-login warning');
    });

    check('V06', "Users only see their own activity", function () use ($b) {
        $body = $b->get('activity.php')['body'];
        return expect(!str_contains($body, 'Audit Water') && !str_contains($body, 'Failed login'), "A's events visible to B");
    });

    check('V07', 'Type filter works; junk filter / page values do not break', function () use ($a) {
        $bills = $a->get('activity.php?type=bills')['body'];
        if (!str_contains($bills, 'Bill added') || str_contains($bills, 'Logged in')) {
            return 'type=bills filter shows wrong entries';
        }
        foreach (['activity.php?type=hacked', 'activity.php?page=999', 'activity.php?page=-1', 'activity.php?page=abc', 'activity.php?type[]=x'] as $p) {
            $r = $a->get($p);
            if ($r['status'] !== 200 || ($e = phpErrorsIn($r['body']))) {
                return "$p -> {$r['status']} " . ($e ?? '');
            }
        }
        return true;
    });

    check('V08', 'Logged bill names are escaped on the activity page (XSS)', function () use ($a) {
        $payload = '<img src=x onerror=alert(1)>';
        $a->post('add_bill.php', ['bill_name' => $payload, 'amount' => '5', 'due_date' => date('Y-m-d', strtotime('+3 days')), 'category_id' => '1']);
        $body = $a->get('activity.php')['body'];
        return expect(!str_contains($body, $payload) && str_contains($body, htmlspecialchars($payload)), 'payload not escaped / not logged');
    });

    check('V09', 'Pagination: 25 per page', function () use ($b, $pdo, $userB) {
        $ins = $pdo->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, 'login', 'pagination test')");
        for ($i = 0; $i < 30; $i++) {
            $ins->execute([$userB]);
        }
        $p1 = $b->get('activity.php')['body'];
        $p2 = $b->get('activity.php?page=2')['body'];
        $rows1 = substr_count($p1, 'class="log-row"');
        if ($rows1 !== 25 || !str_contains($p1, 'Older')) {
            return "page 1 has $rows1 rows";
        }
        return expect(substr_count($p2, 'class="log-row"') > 0 && str_contains($p2, 'Newer'), 'page 2 empty');
    });

    // =========================================================================================
    group('NEW FEATURE — Bills CSV export');
    // =========================================================================================
    check('X01', 'Bills export returns the user\'s bills as CSV', function () use ($b) {
        $r = $b->get('bills.php?export=csv');
        if (!str_contains($r['headers'], 'text/csv') || !str_contains($r['headers'], 'afterbill-bills-')) {
            return 'not a CSV download';
        }
        $rows = str_getcsv_lines($r['body']);
        if ($rows[0] !== ['Bill', 'Category', 'Amount', 'Due Date', 'Status', 'Recurring']) {
            return 'unexpected header: ' . implode(',', $rows[0]);
        }
        $names = array_column(array_slice($rows, 1), 0);
        return expect(in_array('B Power', $names, true) && in_array('B Rent', $names, true), 'expected bills missing');
    });

    check('X02', 'Export follows the current filters', function () use ($b) {
        $rows = array_slice(str_getcsv_lines($b->get('bills.php?export=csv&status=paid')['body']), 1);
        return expect(count($rows) === 1 && $rows[0][0] === 'B Water' && $rows[0][4] === 'Paid', 'filtered export: ' . json_encode($rows));
    });

    check('X03', 'Export never includes another user\'s bills', function () use ($b) {
        $body = $b->get('bills.php?export=csv')['body'];
        return expect(!str_contains($body, 'Victim Rent') && !str_contains($body, 'Audit Electric'), "A's bills in B's export");
    });

    check('X04', 'Export escapes formula-like bill names', function () use ($b, $pdo, $userB) {
        insertBill($pdo, $userB, '=cmd|calc', date('Y-m-d'), false, 'unpaid', 1.00, 1);
        $names = array_column(str_getcsv_lines($b->get('bills.php?export=csv')['body']), 0);
        return expect(in_array("'=cmd|calc", $names, true) && !in_array('=cmd|calc', $names, true), 'formula exported unescaped');
    });

    check('X05', 'Export link is shown on My Bills and keeps the filters', function () use ($b) {
        $body = $b->get('bills.php?status=paid')['body'];
        return expect(str_contains($body, 'bills.php?status=paid&amp;export=csv'), 'export link missing or drops filters');
    });

    // =========================================================================================
    group('SYSTEM — Web exposure');
    // =========================================================================================
    check('W01', 'Source control, editor and snapshot folders are not downloadable', function () use ($anon) {
        foreach (['.git/HEAD', '.git/config', '.vscode/launch.json', '.snapshots/config.json'] as $p) {
            if ($anon->get($p)['status'] === 200) {
                return "/$p is publicly readable";
            }
        }
        return true;
    });

    check('W02', 'Directory listings are disabled', function () use ($anon) {
        foreach (['assets/', 'assets/css/', 'auth/', 'includes/', 'partials/', 'config/', 'tests/', 'database/'] as $p) {
            if (str_contains($anon->get($p)['body'], 'Index of')) {
                return "/$p shows a directory listing";
            }
        }
        return true;
    });

    check('W03', 'Internal files (tests, migrations, notes, includes, partials) are not web-accessible', function () use ($anon) {
        foreach (['tests/last_run.txt', 'tests/backend_audit.php', 'database/migrations/001_budgets_activity_log.sql',
                  'AUDIT_STATUS.md', 'NEW_MODULES.md', 'SYSTEM_AUDIT_STATUS.md', 'config/database.php',
                  'includes/csrf.php', 'includes/budgets.php', 'partials/sidebar.php', 'partials/auth-brand.php',
                  'partials/logout-button.php'] as $p) {
            if ($anon->get($p)['status'] === 200) {
                return "/$p is reachable (200)";
            }
        }
        return true;
    });

    check('W04', 'Public pages and assets still load', function () use ($anon) {
        foreach (['index.php', 'auth/login.php', 'auth/register.php', 'assets/css/app.css', 'assets/css/auth.css',
                  'assets/js/register.js', 'assets/images/logo.png'] as $p) {
            $r = $anon->get($p);
            if ($r['status'] !== 200 || ($e = phpErrorsIn($r['body']))) {
                return "/$p -> {$r['status']} " . ($e ?? '');
            }
        }
        return true;
    });

    // =========================================================================================
    group('SYSTEM — HTTP security');
    // =========================================================================================
    check('W05', 'Security headers are sent (clickjacking, MIME sniffing, referrer)', function () use ($anon) {
        $h = $anon->get('auth/login.php')['headers'];
        foreach (['X-Frame-Options', 'X-Content-Type-Options: nosniff', 'Referrer-Policy'] as $need) {
            if (stripos($h, $need) === false) {
                return "missing header: $need";
            }
        }
        return true;
    });

    check('W06', 'PHP version is not advertised (X-Powered-By)', function () use ($anon) {
        return expect(stripos($anon->get('index.php')['headers'], 'X-Powered-By') === false, 'X-Powered-By header present');
    });

    check('W07', 'Session cookie is HttpOnly and SameSite', function () use ($BASE) {
        $c = new Client($BASE);
        $h = $c->get('auth/login.php')['headers'];
        if (!preg_match('/^Set-Cookie:\s*PHPSESSID=[^\r\n]*/mi', $h, $m)) {
            return 'no session cookie set';
        }
        return expect(stripos($m[0], 'HttpOnly') !== false && stripos($m[0], 'SameSite') !== false, "cookie flags: {$m[0]}");
    });

    check('W08', 'Unknown session ids are rejected (strict session mode)', function () use ($BASE) {
        $c = new Client($BASE);
        $c->cookies['PHPSESSID'] = 'attackerchosen' . bin2hex(random_bytes(6));
        $h = $c->get('auth/login.php')['headers'];
        return expect((bool)preg_match('/^Set-Cookie:\s*PHPSESSID=/mi', $h), 'server accepted an id it never issued');
    });

    check('W09', 'PHP errors are not displayed to visitors', function () use ($anon) {
        // Opening a partial directly used to print "Warning: ... in C:\xampp\..." with full paths.
        $body = $anon->get('partials/sidebar.php')['body'];
        return expect(!str_contains($body, 'C:\\xampp') && phpErrorsIn($body) === null, 'error output with server paths shown');
    });

    // =========================================================================================
    group('SYSTEM — Brute-force protection');
    // =========================================================================================
    check('W10', 'Account locks after 5 failed logins (even the right password is refused)', function () use ($BASE, $stamp, &$createdEmails) {
        $email = "brute_{$stamp}@audit.test";
        $createdEmails[] = $email;
        registerUser(new Client($BASE), 'Brute Target', $email, 'RightPass#2026');
        // registerUser() ends with a successful login, and the lock only counts failures AFTER the
        // last login; activity_log times are whole seconds, so start the failures a second later.
        sleep(1);
        $c = new Client($BASE);
        for ($i = 0; $i < 5; $i++) {
            login($c, $email, "wrong-$i");
        }
        $r = login($c, $email, 'RightPass#2026');
        if ($r['status'] === 302) {
            return 'correct password accepted after 5 failures in a row';
        }
        return expect(str_contains($r['body'], 'Too many failed'), 'no lockout message shown');
    });

    check('W11', 'Lockout expires after 15 minutes', function () use ($BASE, $stamp, $pdo) {
        $email = "brute_{$stamp}@audit.test";
        $pdo->exec("UPDATE activity_log l JOIN users u ON u.id = l.user_id
                    SET l.created_at = l.created_at - INTERVAL 16 MINUTE
                    WHERE u.email = " . $pdo->quote($email) . " AND l.action = 'login_failed'");
        $r = login(new Client($BASE), $email, 'RightPass#2026');
        return expect($r['status'] === 302, 'still locked after the window passed');
    });

    check('W12', 'Lockout does not reveal whether an email exists', function () use ($BASE, $stamp) {
        $c = new Client($BASE);
        for ($i = 0; $i < 6; $i++) {
            $r = login($c, "ghost_{$stamp}@audit.test", "wrong-$i");
        }
        return expect(str_contains($r['body'], 'Invalid email or password') && !str_contains($r['body'], 'Too many failed'),
            'unknown email behaves differently');
    });

    // =========================================================================================
    group('SECURITY — Sessions, email change, rate limits');
    // =========================================================================================
    $secEmail = "sec_{$stamp}@audit.test";
    $secPass  = 'Bluefish#42';
    $createdEmails[] = $secEmail;
    $sec = new Client($BASE);
    clearRateLimits();
    registerUser($sec, 'Security Tester', $secEmail, $secPass);

    check('Z01', 'Changing the email without the current password is refused', function () use ($sec, $pdo, $secEmail, $stamp) {
        $r = $sec->post('account.php', ['action' => 'profile', 'full_name' => 'Security Tester', 'email' => "sec2_{$stamp}@audit.test"]);
        $email = $pdo->query('SELECT email FROM users WHERE id = ' . (int)userIdByEmail($pdo, $secEmail))->fetchColumn();
        return expect($email === $secEmail && str_contains($r['body'], 'Enter your current password'), 'email changed without a password');
    });

    check('Z02', 'Changing the email with a wrong password is refused', function () use ($sec, $pdo, $secEmail, $stamp) {
        $r = $sec->post('account.php', ['action' => 'profile', 'full_name' => 'Security Tester',
            'email' => "sec2_{$stamp}@audit.test", 'current_password' => 'Wrong#Pass99']);
        return expect(userIdByEmail($pdo, $secEmail) !== null && str_contains($r['body'], 'Current password is incorrect'),
            'email changed with a wrong password');
    });

    check('Z03', 'Changing the email with the right password works (name-only changes need no password)', function () use ($sec, $pdo, $secEmail, $secPass, $stamp) {
        $id = userIdByEmail($pdo, $secEmail);
        $r = $sec->post('account.php', ['action' => 'profile', 'full_name' => 'Security Tester',
            'email' => "sec2_{$stamp}@audit.test", 'current_password' => $secPass]);
        // Accepted = a confirmation link is on its way (the change itself is tested in E09).
        $changed = str_contains($r['location'], 'updated=email_pending');
        $r2 = $sec->post('account.php', ['action' => 'profile', 'full_name' => 'Security Tester 2', 'email' => $secEmail]);
        return expect($changed && str_contains($r2['location'], 'updated=profile'), 'right password refused, or name change needed a password');
    });

    check('Z04', 'Changing the password signs out other devices but keeps this one signed in', function () use ($BASE, $sec, $secEmail, $secPass) {
        $other = new Client($BASE);
        login($other, $secEmail, $secPass);
        if ($other->get('dashboard.php')['status'] !== 200) {
            return 'second device could not sign in';
        }
        $r = $sec->post('account.php', ['action' => 'password', 'current_password' => $secPass,
            'new_password' => 'Greenfish#77', 'confirm_password' => 'Greenfish#77']);
        if (!str_contains($r['location'], 'updated=password')) {
            return "password change failed: {$r['status']} -> {$r['location']}";
        }
        $o = $other->get('dashboard.php');
        $s = $sec->get('dashboard.php');
        if ($o['status'] !== 302 || !str_contains($o['location'], 'reason=signed_out')) {
            return 'other device is still signed in after the password change';
        }
        return expect($s['status'] === 200, 'the device that changed the password was signed out');
    });

    check('Z05', 'Login page explains why the user was signed out', function () use ($BASE) {
        $a = (new Client($BASE))->get('auth/login.php?reason=expired')['body'];
        $b = (new Client($BASE))->get('auth/login.php?reason=signed_out')['body'];
        $c = (new Client($BASE))->get('auth/login.php?reason=<script>')['body'];
        return expect(str_contains($a, 'minutes of inactivity') && str_contains($b, 'password was changed') && !str_contains($c, '<script>'),
            'missing or unsafe sign-out message');
    });

    check('Z06', 'Sessions idle for more than 30 minutes expire', function () {
        $now = time();
        return expect(!session_idle_expired($now - 29 * 60, $now) && session_idle_expired($now - 31 * 60, $now) && session_idle_expired(0, $now),
            'idle rule wrong');
    });

    check('Z07', 'Common passwords are refused even when they meet the rules', function () use ($BASE, $pdo, $stamp, &$createdEmails) {
        foreach (['Password1!', 'P@ssw0rd1', 'Welcome2026!', 'Qwerty123!', 'Afterbill#1'] as $i => $pw) {
            $email = "common{$i}_{$stamp}@audit.test";
            $createdEmails[] = $email;
            $r = registerUser(new Client($BASE), 'Common Pass', $email, $pw);
            if (userIdByEmail($pdo, $email) !== null || !str_contains($r['body'], 'too easy to guess')) {
                return "'{$pw}' was accepted";
            }
        }
        return true;
    });

    check('Z08', 'Passwords built on the user\'s own name or email are refused', function () use ($BASE, $pdo, $stamp, &$createdEmails) {
        $email = "marisol_{$stamp}@audit.test";
        $createdEmails[] = $email;
        registerUser(new Client($BASE), 'Marisol Reyes', $email, 'Reyes#2026');
        return expect(userIdByEmail($pdo, $email) === null, 'password containing the surname accepted');
    });

    check('Z09', 'Too many failed logins from one IP are blocked, across different emails', function () use ($BASE, $stamp, $secEmail) {
        $c = new Client($BASE);
        for ($i = 0; $i < RATE_LIMITS['login_failed'][0]; $i++) {
            login($c, "spray{$i}_{$stamp}@audit.test", 'Spring#2026x');   // a different (unknown) email each time
        }
        $r = login(new Client($BASE), $secEmail, 'Greenfish#77');        // right password for a real account
        return expect($r['status'] !== 302 && str_contains($r['body'], 'Too many attempts from your network'),
            'login still allowed after ' . RATE_LIMITS['login_failed'][0] . ' failures from this IP');
    });

    check('Z10', 'Too many registrations from one IP are blocked', function () use ($BASE, $pdo, $stamp, &$createdEmails) {
        for ($i = 0; $i < RATE_LIMITS['register'][0]; $i++) {
            registerUser(new Client($BASE), 'Spam', "spam{$i}_{$stamp}", 'x');   // invalid on purpose: attempts count too
        }
        $email = "spamok_{$stamp}@audit.test";
        $createdEmails[] = $email;
        $r = registerUser(new Client($BASE), 'Spam Ok', $email, 'Bluefish#42');
        return expect(userIdByEmail($pdo, $email) === null && str_contains($r['body'], 'Too many attempts from your network'),
            'registration still allowed after ' . RATE_LIMITS['register'][0] . ' attempts');
    });

    // =========================================================================================
    group('SECURITY — Content-Security-Policy & account deletion');
    // =========================================================================================
    check('Y01', 'Content-Security-Policy only allows script files from this site', function () use ($BASE) {
        $h = (new Client($BASE))->get('auth/login.php')['headers'];
        if (!preg_match('/^Content-Security-Policy:\s*(.+)$/mi', $h, $m)) {
            return 'no Content-Security-Policy header';
        }
        $csp = $m[1];
        if (!preg_match("/script-src 'self'(;|$)/", $csp) || str_contains($csp, 'unsafe-eval')) {
            return "script-src is not locked to 'self': $csp";
        }
        foreach (["object-src 'none'", "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'"] as $need) {
            if (!str_contains($csp, $need)) {
                return "missing $need";
            }
        }
        return true;
    });

    check('Y02', 'No page uses inline scripts or inline event handlers (they would be blocked)', function () use ($BASE, $a) {
        $pages = ['dashboard.php', 'bills.php', 'add_bill.php', 'calendar.php', 'reports.php', 'payments.php',
                  'reminders.php', 'account.php', 'budgets.php', 'activity.php'];
        $anon = new Client($BASE);
        $bodies = ['index.php' => $anon->get('index.php')['body'], 'auth/login.php' => $anon->get('auth/login.php')['body'],
                   'auth/register.php' => $anon->get('auth/register.php')['body']];
        foreach ($pages as $p) {
            $r = $a->get($p);
            if ($r['status'] !== 200) {
                return "$p returned {$r['status']} (audit user A not signed in?)";
            }
            $bodies[$p] = $r['body'];
        }
        foreach ($bodies as $p => $body) {
            if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $body)) {
                return "$p has an inline <script> block";
            }
            // Look at attribute NAMES only: quoted values are blanked first, so escaped user text
            // inside an attribute (e.g. a bill named <img onerror=...>) is not mistaken for one.
            preg_match_all('/<[a-z][^>]*>/i', $body, $tags);
            foreach ($tags[0] as $tag) {
                $names = preg_replace('/"[^"]*"|\'[^\']*\'/', '""', $tag);
                if (preg_match('/\son[a-z]+\s*=/i', $names)) {
                    return "$p has an inline event handler: " . substr($tag, 0, 80);
                }
            }
            if (stripos($body, 'href="javascript:') !== false) {
                return "$p has a javascript: link";
            }
        }
        return true;
    });

    check('Y03', 'Confirmations and auto-submit filters still work (data attributes + app.js)', function () use ($a) {
        $bills = $a->get('bills.php')['body'];
        $pay   = $a->get('payments.php')['body'];
        $ok = str_contains($bills, 'data-autosubmit') && str_contains($pay, 'data-autosubmit')
            && str_contains($bills, 'assets/js/app.js') && str_contains($pay, 'assets/js/app.js')
            && str_contains($a->get('account.php')['body'], 'data-confirm=');
        return expect($ok, 'data attributes or app.js missing');
    });

    $delEmail = "delete_{$stamp}@audit.test";
    $delPass  = 'Seashell#84';
    $createdEmails[] = $delEmail;
    $del = new Client($BASE);
    clearRateLimits();
    registerUser($del, 'Delete Tester', $delEmail, $delPass);
    $delId = userIdByEmail($pdo, $delEmail);
    if ($delId) {
        // Something in every table that hangs off the user, to prove it all goes with them.
        $cat = (int)$pdo->query('SELECT category_id FROM categories ORDER BY category_id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO bills (user_id, bill_name, amount, due_date, category_id, is_recurring, status) VALUES (?, 'Del parent', 100, CURDATE(), ?, 1, 'paid')")->execute([$delId, $cat]);
        $parent = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bills (user_id, bill_name, amount, due_date, category_id, parent_bill_id, is_recurring, status) VALUES (?, 'Del child', 100, CURDATE() + INTERVAL 1 MONTH, ?, ?, 1, 'unpaid')")->execute([$delId, $cat, $parent]);
        $pdo->prepare("INSERT INTO payments (bill_id, payment_date, amount_paid, payment_method) VALUES (?, CURDATE(), 100, 'cash')")->execute([$parent]);
        $pdo->prepare('INSERT INTO budgets (user_id, category_id, monthly_limit) VALUES (?, ?, 500)')->execute([$delId, $cat]);
    }

    check('Y04', 'Deleting the account needs the right password and the confirmation box', function () use ($del, $pdo, $delId, $delPass) {
        if (!$delId) {
            return 'delete-test user was not created';
        }
        $r1 = $del->post('account.php', ['action' => 'delete_account', 'delete_password' => 'Wrong#Pass99', 'confirm_delete' => 'yes']);
        $r2 = $del->post('account.php', ['action' => 'delete_account', 'delete_password' => $delPass]);
        $r3 = $del->postRaw('account.php', ['action' => 'delete_account', 'delete_password' => $delPass, 'confirm_delete' => 'yes']);
        $exists = (bool)$pdo->query("SELECT COUNT(*) FROM users WHERE id = $delId")->fetchColumn();
        if (!$exists) {
            return 'account was deleted by a bad request';
        }
        return expect(str_contains($r1['body'], 'Password is incorrect') && str_contains($r2['body'], 'Tick the box'),
            'missing error messages');
    });

    check('Y05', 'Deleting the account removes the user and all their data, and ends the session', function () use ($BASE, $del, $pdo, $delId, $delEmail, $delPass) {
        if (!$delId) {
            return 'delete-test user was not created';
        }
        $r = $del->post('account.php', ['action' => 'delete_account', 'delete_password' => $delPass, 'confirm_delete' => 'yes']);
        if ($r['status'] !== 302 || !str_contains($r['location'], 'reason=deleted')) {
            return "delete failed: {$r['status']} -> {$r['location']}";
        }
        foreach (['users' => "id = $delId", 'bills' => "user_id = $delId", 'budgets' => "user_id = $delId",
                  'activity_log' => "user_id = $delId",
                  'payments' => "bill_id IN (SELECT bill_id FROM bills WHERE user_id = $delId)"] as $table => $where) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM $table WHERE $where")->fetchColumn() > 0) {
                return "rows left in $table";
            }
        }
        if ($del->get('dashboard.php')['status'] !== 302) {
            return 'session still works after the account was deleted';
        }
        $l = login(new Client($BASE), $delEmail, $delPass);
        $msg = (new Client($BASE))->get('auth/login.php?reason=deleted')['body'];
        return expect($l['status'] !== 302 && str_contains($msg, 'permanently deleted'), 'deleted account can still log in, or no message');
    });

    // =========================================================================================
    group('EMAIL — Verification, password reset, email change');
    // =========================================================================================
    $evEmail = "verify_{$stamp}@audit.test";
    $evPass  = 'Lantern#Bay31';
    $createdEmails[] = $evEmail;
    $ev = new Client($BASE);

    check('E01', 'Registering sends a verification email and does not sign the user in', function () use ($ev, $pdo, $evEmail, $evPass, $MAIL_CONFIG) {
        $mark = mailMark();
        $r = registerUser($ev, 'Verify Tester', $evEmail, $evPass, false);
        $id = userIdByEmail($pdo, $evEmail);
        if (!$id || $pdo->query("SELECT email_verified_at FROM users WHERE id = $id")->fetchColumn() !== null) {
            return 'account missing, or already verified';
        }
        if ($ev->get('dashboard.php')['status'] !== 302) {
            return 'registration signed the user in before verifying';
        }
        $link = mailLink($evEmail, 'auth/verify_email.php', $mark);
        return expect($link !== null && str_contains($r['body'], 'Check your inbox'), 'no verification email with a link');
    });

    check('E02', 'Unverified users cannot log in; the reason is only shown after the right password', function () use ($BASE, $evEmail, $evPass) {
        $right = login(new Client($BASE), $evEmail, $evPass);
        $wrong = login(new Client($BASE), $evEmail, 'Wrong#Pass99');
        if ($right['status'] === 302) {
            return 'unverified account logged in';
        }
        return expect(str_contains($right['body'], 'verify your email') && !str_contains($wrong['body'], 'verify your email')
            && str_contains($wrong['body'], 'Invalid email or password'), 'wrong messages');
    });

    check('E03', 'The verification link works once, then the user can log in', function () use ($BASE, $pdo, $evEmail, $evPass) {
        $link = mailLink($evEmail, 'auth/verify_email.php');
        if ($link === null) {
            return 'no verification link';
        }
        $first  = (new Client($BASE))->get($link);
        $second = (new Client($BASE))->get($link);
        $l = login(new Client($BASE), $evEmail, $evPass);
        $verified = $pdo->query('SELECT email_verified_at FROM users WHERE id = ' . (int)userIdByEmail($pdo, $evEmail))->fetchColumn() !== null;
        return expect(str_contains($first['body'], 'Email verified') && $second['status'] === 400 && $verified
            && $l['status'] === 302 && str_contains($l['location'], 'dashboard.php'), 'link did not verify, worked twice, or login failed');
    });

    check('E04', 'Forgot password: same answer for known and unknown emails; only real accounts get an email', function () use ($BASE, $evEmail, $stamp) {
        $ghost = "ghost_reset_{$stamp}@audit.test";
        $mark = mailMark();
        $known   = (new Client($BASE))->post('auth/forgot_password.php', ['email' => $evEmail]);
        $unknown = (new Client($BASE))->post('auth/forgot_password.php', ['email' => $ghost]);
        $norm = fn ($b, $e) => str_replace(htmlspecialchars($e), 'X', $b);
        if (strip_tags($norm($known['body'], $evEmail)) !== strip_tags($norm($unknown['body'], $ghost))) {
            return 'the page answers differently for a registered email';
        }
        return expect(mailLink($evEmail, 'auth/reset_password.php', $mark) !== null && mailsTo($ghost, $mark) === [],
            'reset email missing, or sent to an unknown address');
    });

    check('E05', 'Reset link: opening it changes nothing; it sets a strong new password once and signs out other sessions', function () use ($BASE, $pdo, $evEmail, $evPass) {
        $signedIn = new Client($BASE);
        login($signedIn, $evEmail, $evPass);
        $link = mailLink($evEmail, 'auth/reset_password.php');
        $token = substr($link, strpos($link, '=') + 1);
        $c = new Client($BASE);
        $form = $c->get($link);
        if (!str_contains($form['body'], 'Choose a new password') || login(new Client($BASE), $evEmail, $evPass)['status'] !== 302) {
            return 'reset form missing, or just opening the link changed the password';
        }
        $weak = $c->post('auth/reset_password.php', ['token' => $token, 'password' => 'Password1!', 'confirm_password' => 'Password1!'], $link);
        if (!str_contains($weak['body'], 'too easy to guess')) {
            return 'weak password accepted on reset';
        }
        $ok = $c->post('auth/reset_password.php', ['token' => $token, 'password' => 'Meadow#Lark62', 'confirm_password' => 'Meadow#Lark62'], $link);
        if (!str_contains($ok['location'], 'reason=reset')) {
            return "reset failed: {$ok['status']} -> {$ok['location']}";
        }
        $again = (new Client($BASE))->get($link);
        $old = login(new Client($BASE), $evEmail, $evPass);
        $new = login(new Client($BASE), $evEmail, 'Meadow#Lark62');
        $kicked = $signedIn->get('dashboard.php')['status'] === 302;
        return expect($again['status'] === 400 && $old['status'] !== 302 && $new['status'] === 302 && $kicked,
            'link reusable, old password still works, new one does not, or other sessions stayed signed in');
    });

    check('E06', 'Expired reset links are refused, and a newer link cancels the older one', function () use ($BASE, $pdo, $evEmail) {
        $c = new Client($BASE);
        $c->post('auth/forgot_password.php', ['email' => $evEmail]);
        $first = mailLink($evEmail, 'auth/reset_password.php');
        $c->post('auth/forgot_password.php', ['email' => $evEmail]);
        $second = mailLink($evEmail, 'auth/reset_password.php');
        if ($first === $second || (new Client($BASE))->get($first)['status'] !== 400) {
            return 'older reset link still works after a new one was sent';
        }
        $pdo->prepare('UPDATE auth_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = ?')
            ->execute([hash('sha256', substr($second, strpos($second, '=') + 1))]);
        return expect((new Client($BASE))->get($second)['status'] === 400, 'expired reset link accepted');
    });

    check('E07', 'Emailed links use the configured site address, not the Host header (link poisoning)', function () use ($BASE, $evEmail, $MAIL_CONFIG) {
        $c = new Client($BASE);
        $c->headers = ['Host: evil.example'];
        $mark = mailMark();
        $c->post('auth/forgot_password.php', ['email' => $evEmail]);
        $mails = mailsTo($evEmail, $mark);
        if ($mails === []) {
            return 'no email sent (could not test)';
        }
        $body = end($mails)['body'];
        return expect(!str_contains($body, 'evil.example') && str_contains($body, $MAIL_CONFIG['app_url']), 'reset link points at the attacker host');
    });

    check('E08', 'Only a hash of each emailed token is stored', function () use ($pdo, $evEmail) {
        $link = mailLink($evEmail, 'auth/reset_password.php');
        $token = substr($link, strpos($link, '=') + 1);
        $plain = (int)$pdo->query('SELECT COUNT(*) FROM auth_tokens WHERE token_hash = ' . $pdo->quote($token))->fetchColumn();
        $hashed = (int)$pdo->query('SELECT COUNT(*) FROM auth_tokens WHERE token_hash = ' . $pdo->quote(hash('sha256', $token)))->fetchColumn();
        return expect($plain === 0 && $hashed === 1, 'token stored in plain text');
    });

    check('E09', 'Email change only happens after the new address confirms; the old address is told', function () use ($BASE, $pdo, $evEmail, $stamp) {
        $c = new Client($BASE);
        login($c, $evEmail, 'Meadow#Lark62');
        $id = userIdByEmail($pdo, $evEmail);
        $newEmail = "verify_new_{$stamp}@audit.test";
        $mark = mailMark();
        $r = $c->post('account.php', ['action' => 'profile', 'full_name' => 'Verify Tester', 'email' => $newEmail, 'current_password' => 'Meadow#Lark62']);
        $stillOld = $pdo->query("SELECT email FROM users WHERE id = $id")->fetchColumn() === $evEmail;
        $link = mailLink($newEmail, 'auth/verify_email.php', $mark);
        $warned = count(mailsTo($evEmail, $mark)) === 1;
        if (!str_contains($r['location'], 'email_pending') || !$stillOld || !$link || !$warned) {
            return 'email changed before confirmation, or emails missing';
        }
        (new Client($BASE))->get($link);
        $now = $pdo->query("SELECT email FROM users WHERE id = $id")->fetchColumn();
        // put it back for the tests that follow
        $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$evEmail, $id]);
        return expect($now === $newEmail, 'confirmation link did not change the email');
    });

    check('E10', 'Too many reset/verification emails from one IP are blocked', function () use ($BASE, $stamp) {
        $c = new Client($BASE);
        for ($i = 0; $i < RATE_LIMITS['email_request'][0]; $i++) {
            $c->post('auth/forgot_password.php', ['email' => "flood{$i}_{$stamp}@audit.test"]);
        }
        $r = $c->post('auth/resend_verification.php', ['email' => "flood_x_{$stamp}@audit.test"]);
        return expect(str_contains($r['body'], 'Too many attempts from your network'), 'no limit on emails');
    });

    check('E11', 'Mail log, Composer packages and mail settings are not web-accessible', function () use ($BASE) {
        $c = new Client($BASE);
        foreach (['storage/mail.log', 'vendor/autoload.php', 'vendor/phpmailer/phpmailer/README.md', 'composer.json',
                  'composer.lock', 'config/mail.php', 'config/mail.local.example.php'] as $path) {
            $r = $c->get($path);
            if ($r['status'] === 200) {
                return "$path is readable over HTTP";
            }
        }
        return true;
    });

    // =========================================================================================
    group('SYSTEM — Time & date consistency');
    // =========================================================================================
    check('T01', 'Dashboard greeting matches the server clock (PHP and MySQL agree on the hour)', function () use ($b, $pdo) {
        $hour = (int)$pdo->query('SELECT HOUR(NOW())')->fetchColumn();
        $expected = $hour >= 5 && $hour < 12 ? 'Good morning' : ($hour >= 12 && $hour < 18 ? 'Good afternoon' : 'Good evening');
        $body = $b->get('dashboard.php')['body'];
        return expect(str_contains($body, $expected), "MySQL hour is $hour so expected \"$expected\"; page shows "
            . (preg_match('/(Good \w+)/', $body, $m) ? $m[1] : '?'));
    });

    check('T02', "Dashboard date and mark-paid default date equal the database's today", function () use ($b, $pdo, $userB) {
        $today = new DateTime((string)$pdo->query('SELECT CURDATE()')->fetchColumn());
        if (!str_contains($b->get('dashboard.php')['body'], $today->format('M j, Y'))) {
            return 'dashboard date badge differs from CURDATE() ' . $today->format('Y-m-d');
        }
        $bill = insertBill($pdo, $userB, 'TZ check', $today->format('Y-m-d'));
        $body = $b->get("mark_paid.php?bill_id=$bill")['body'];
        return expect(str_contains($body, 'value="' . $today->format('Y-m-d') . '"'), 'mark-paid default date differs from CURDATE()');
    });

    // =========================================================================================
    group('SYSTEM — Numbers shown match the database');
    // =========================================================================================
    $emailC = "audit_c_{$stamp}@audit.test";
    $createdEmails[] = $emailC;
    $c3 = new Client($BASE);
    registerUser($c3, 'Audit User C', $emailC, $PASS);
    $userC = userIdByEmail($pdo, $emailC);
    $today = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
    $day = fn(int $offset) => (string)$pdo->query("SELECT CURDATE() + INTERVAL $offset DAY")->fetchColumn();
    $paidBill = insertBill($pdo, $userC, 'C Paid Today', $today, false, 'paid', 1000.00, 1);
    $pdo->prepare("INSERT INTO payments (bill_id, payment_date, amount_paid, payment_method) VALUES (?, ?, 1000.00, 'cash')")
        ->execute([$paidBill, $today]);
    $lateBill = insertBill($pdo, $userC, 'C Paid Late', $day(-3), false, 'paid', 50.00, 2);
    $pdo->prepare("INSERT INTO payments (bill_id, payment_date, amount_paid, payment_method) VALUES (?, ?, 50.00, 'gcash')")
        ->execute([$lateBill, $today]);
    insertBill($pdo, $userC, 'C Soon', $day(3), false, 'unpaid', 200.00, 2);
    insertBill($pdo, $userC, 'C Overdue', $day(-2), false, 'unpaid', 300.00, 3);
    insertBill($pdo, $userC, 'C Later', $day(20), false, 'unpaid', 400.00, 4);

    // Independent oracle: the same figures computed straight from SQL.
    $oracle = $pdo->query(
        "SELECT
            SUM(YEAR(due_date) = YEAR(CURDATE()) AND MONTH(due_date) = MONTH(CURDATE())) AS month_count,
            SUM(CASE WHEN status = 'paid' AND YEAR(due_date) = YEAR(CURDATE()) AND MONTH(due_date) = MONTH(CURDATE()) THEN amount ELSE 0 END) AS paid_sum,
            SUM(CASE WHEN status = 'unpaid' AND YEAR(due_date) = YEAR(CURDATE()) AND MONTH(due_date) = MONTH(CURDATE()) THEN amount ELSE 0 END) AS unpaid_sum,
            SUM(status = 'unpaid' AND due_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY) AS upcoming,
            SUM(status = 'unpaid' AND due_date < CURDATE()) AS overdue
         FROM bills WHERE user_id = $userC"
    )->fetch();
    $statValues = function (string $html): array {
        preg_match_all('/class="stat-value">\s*([^<]*?)\s*</', $html, $m);
        return $m[1];
    };
    $peso = fn($n) => '₱' . number_format((float)$n, 2);

    check('N01', 'Dashboard stat cards match the database', function () use ($c3, $oracle, $statValues, $peso) {
        $v = $statValues($c3->get('dashboard.php')['body']);
        $expected = [
            (string)(int)$oracle['month_count'], $peso($oracle['paid_sum']), $peso($oracle['unpaid_sum']),
            (string)(int)$oracle['upcoming'], (string)(int)$oracle['overdue'], $peso($oracle['paid_sum'] + $oracle['unpaid_sum']),
        ];
        return expect($v === $expected, 'shown ' . json_encode($v, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE));
    });

    check('N02', 'Reports totals for this month match the database', function () use ($c3, $oracle, $peso) {
        $body = $c3->get('reports.php')['body'];
        foreach ([$oracle['paid_sum'] + $oracle['unpaid_sum'], $oracle['paid_sum'], $oracle['unpaid_sum']] as $n) {
            if (!str_contains($body, $peso($n))) {
                return 'reports.php does not show ' . $peso($n);
            }
        }
        return true;
    });

    check('N03', "Calendar shows this month's bills on their due dates", function () use ($c3, $pdo, $userC) {
        $body = $c3->get('calendar.php')['body'];
        $rows = $pdo->query("SELECT bill_name, due_date FROM bills WHERE user_id = $userC
                             AND YEAR(due_date) = YEAR(CURDATE()) AND MONTH(due_date) = MONTH(CURDATE())")->fetchAll();
        foreach ($rows as $r) {
            // Each day's pop-up is headed "Oct 2, 2026" and lists that day's bills right after it.
            $heading = (new DateTime($r['due_date']))->format('M j, Y');
            $at = strpos($body, $heading);
            $next = $at === false ? false : strpos($body, 'day-popup-title', $at + strlen($heading));
            $segment = $at === false ? '' : substr($body, $at, ($next === false ? 3000 : $next - $at));
            if (!str_contains($segment, htmlspecialchars($r['bill_name']))) {
                return "{$r['bill_name']} not listed under $heading on the calendar";
            }
        }
        return true;
    });

    check('N04', 'Reminders put each bill in the right group', function () use ($c3) {
        $body = $c3->get('reminders.php?days=7')['body'];
        $pos = fn($s) => strpos($body, $s);
        if ($pos('C Overdue') === false || $pos('C Soon') === false) {
            return 'overdue / upcoming bill missing';
        }
        if (str_contains($body, 'C Later') || str_contains($body, 'C Paid Today')) {
            return 'bill outside the 7-day window (or a paid bill) is listed';
        }
        return expect($pos('Overdue by 2 days') !== false && $pos('Due in 3 days') !== false, 'due text wrong');
    });

    check('N05', 'Payments page totals and late count match the database', function () use ($c3, $statValues) {
        $v = $statValues($c3->get('payments.php')['body']);
        return expect(in_array('₱1,050.00', $v, true) && in_array('1', $v, true), 'shown ' . json_encode($v, JSON_UNESCAPED_UNICODE));
    });

    // =========================================================================================
    group('SYSTEM — Links & page health');
    // =========================================================================================
    check('K01', 'Every link, script, stylesheet and image on every page resolves (no 404)', function () use ($c3, $anon) {
        $pages = [[$anon, 'index.php'], [$anon, 'auth/login.php'], [$anon, 'auth/register.php']];
        foreach (['dashboard.php', 'bills.php', 'add_bill.php', 'calendar.php', 'payments.php', 'reports.php',
                  'budgets.php', 'reminders.php', 'account.php', 'activity.php'] as $p) {
            $pages[] = [$c3, $p];
        }
        $checked = [];
        foreach ($pages as [$client, $page]) {
            $html = $client->get($page)['body'];
            preg_match_all('/(?:href|src|action)="([^"#][^"]*)"/i', $html, $m);
            foreach (array_unique($m[1]) as $url) {
                if (preg_match('#^(https?:|mailto:|tel:|data:|javascript:|//)#i', $url)) {
                    continue; // external — not ours
                }
                $url = html_entity_decode($url);
                $path = $url === '' ? $page : $url;
                if ($path[0] === '?') {
                    $path = strtok($page, '?') . $path;
                }
                // Resolve relative to the page's folder (auth/ pages use ../).
                $dir = dirname($page) === '.' ? '' : dirname($page) . '/';
                $resolved = $dir . $path;
                while (preg_match('#(^|/)[^/.][^/]*/\.\./#', $resolved)) {
                    $resolved = preg_replace('#(^|/)[^/.][^/]*/\.\./#', '$1', $resolved, 1);
                }
                if (isset($checked[$resolved]) || str_contains($resolved, 'logout.php') || str_contains($resolved, 'export=csv')) {
                    continue;
                }
                $r = $client->get($resolved);
                $checked[$resolved] = $r['status'];
                if ($r['status'] === 404 || $r['status'] >= 500) {
                    return "$page links to $url -> {$r['status']}";
                }
            }
        }
        return true;
    });

    check('K02', 'No PHP warnings/errors were logged on the server during this run', function () {
        global $errorLogStart;
        $new = '';
        foreach ($errorLogStart as $log => $start) {
            if (is_file($log)) {
                $fh = fopen($log, 'r');
                fseek($fh, $start);
                $new .= stream_get_contents($fh);
                fclose($fh);
            }
        }
        $hits = [];
        foreach (explode("\n", $new) as $line) {
            if (str_contains($line, 'sqa_afterbill') && preg_match('/PHP (Warning|Notice|Fatal|Parse|Deprecated)[^,]*/', $line, $m)) {
                $hits[] = trim(preg_replace('/^.*PHP /', 'PHP ', $line));
            }
        }
        $hits = array_values(array_unique($hits));
        return expect(!$hits, count($hits) . ' logged: ' . implode(' | ', array_slice($hits, 0, 3)));
    });
} finally {
    // -----------------------------------------------------------------------------------------
    // Cleanup: remove every audit user and their data.
    // -----------------------------------------------------------------------------------------
    $ids = $pdo->query("SELECT id FROM users WHERE email LIKE '%\\_{$stamp}@audit.test'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $id = (int)$id;
        $pdo->exec("DELETE p FROM payments p JOIN bills b ON b.bill_id = p.bill_id WHERE b.user_id = $id");
        $pdo->exec("UPDATE bills SET parent_bill_id = NULL WHERE user_id = $id");
        $pdo->exec("DELETE FROM bills WHERE user_id = $id");
        $pdo->exec("DELETE FROM users WHERE id = $id");
    }
}

function str_getcsv_lines(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

// ---------------------------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------------------------
$passed = count(array_filter($results, fn($r) => $r[3]));
$failed = count($results) - $passed;
$lastGroup = null;
$report = "AfterBill backend audit — " . date('Y-m-d H:i:s') . " — $BASE\n";
foreach ($results as [$grp, $id, $name, $ok, $msg]) {
    if ($grp !== $lastGroup) {
        $report .= "\n[$grp]\n";
        $lastGroup = $grp;
    }
    $report .= sprintf("  %s  %-4s %s%s\n", $ok ? 'PASS' : 'FAIL', $id, $name, $ok ? '' : "\n              -> $msg");
}
$report .= sprintf("\nTotal: %d   Passed: %d   Failed: %d\n", count($results), $passed, $failed);

echo $report;
file_put_contents(__DIR__ . '/last_run.txt', $report);
exit($failed > 0 ? 1 : 0);
