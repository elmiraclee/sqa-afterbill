<?php
/**
 * AfterBill - Password policy
 *
 * The single source of truth for password strength: the rules below, plus a common-password
 * check. auth/register.php and account.php validate with password_policy_errors(), and both render password_rules_checklist() so the browser shows
 * the same rules live (assets/js/password-rules.js reads each rule's pattern from the markup,
 * so the PHP and JavaScript checks can't drift apart).
 */

// bcrypt (PASSWORD_DEFAULT) only uses the first 72 bytes; anything longer would be silently
// truncated by password_hash(), so it is rejected instead.
const PASSWORD_MAX_BYTES = 72;

/**
 * Each rule: key => [label shown to the user, regex body valid in both PHP (PCRE, /u) and JS].
 * @return array<string, array{0:string, 1:string}>
 */
function password_rules(): array
{
    return [
        'length'  => ['At least 8 characters', '^[\s\S]{8,}$'],
        'upper'   => ['One uppercase letter (A–Z)', '[A-Z]'],
        'number'  => ['One number (0–9)', '[0-9]'],
        'special' => ['One special character (e.g. ! @ # $ %)', '[^A-Za-z0-9\s]'],
    ];
}

// Words attackers try first. A password is "common" when, after undoing look-alike swaps
// (P@ssw0rd → password) and dropping the digits/symbols added to satisfy the rules
// (Password1! → password), what is left is one of these.
const COMMON_PASSWORD_WORDS = [
    'password', 'passw', 'pass', 'passwd', 'passcode', 'qwerty', 'qwertyuiop', 'asdf', 'asdfgh',
    'asdfghjkl', 'zxcvbnm', 'qazwsx', 'abc', 'abcd', 'abcde', 'abcdef', 'abcdefg', 'abcdefgh',
    'welcome', 'admin', 'administrator', 'root', 'user', 'login', 'letmein', 'iloveyou', 'loveyou',
    'love', 'lovely', 'monkey', 'dragon', 'master', 'sunshine', 'princess', 'football', 'baseball',
    'basketball', 'soccer', 'superman', 'batman', 'starwars', 'pokemon', 'shadow', 'michael',
    'jordan', 'hello', 'helloworld', 'freedom', 'whatever', 'trustno', 'secret', 'changeme',
    'default', 'test', 'tester', 'testing', 'guest', 'summer', 'winter', 'spring', 'autumn',
    'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september', 'october',
    'november', 'december', 'monday', 'friday', 'sunday', 'computer', 'internet', 'samsung',
    'iphone', 'google', 'facebook', 'chocolate', 'cookie', 'cheese', 'pepper', 'ginger', 'flower',
    'angel', 'baby', 'babygirl', 'jesus', 'god', 'christ', 'blessed', 'family', 'mother',
    'father', 'philippines', 'pilipinas', 'manila', 'mahalkita', 'iloveyouu', 'afterbill', 'bill',
    'bills', 'money', 'qwe', 'qweasd', 'aaa', 'aaaa', 'aaaaaa', 'xyz',
];

/** The word a password is built on: lowercase, look-alikes undone, digits/symbols removed. */
function password_base_word(string $password): string
{
    $s = strtolower($password);
    $s = preg_replace('/^[^a-z]+|[^a-z]+$/', '', $s);          // Password1! → password, 123abc! → abc
    $s = strtr($s, ['@' => 'a', '4' => 'a', '3' => 'e', '1' => 'i', '!' => 'i', '0' => 'o',
                    '$' => 's', '5' => 's', '7' => 't', '+' => 't']);
    return preg_replace('/[^a-z]/', '', $s);
}

/**
 * True when the password is a common one, or is built on the user's own name or email name.
 * @param string[] $personal e.g. [full name, email]
 */
function password_is_common(string $password, array $personal = []): bool
{
    $base = password_base_word($password);
    if ($base === '' || in_array($base, COMMON_PASSWORD_WORDS, true)) {
        return true;
    }
    // '1' also stands for 'l' (pa55wor1d is rare, but he11o → hello is not).
    if (in_array(str_replace('i', 'l', $base), COMMON_PASSWORD_WORDS, true)) {
        return true;
    }
    $lower = strtolower($password);
    foreach ($personal as $value) {
        foreach (preg_split('/[^a-z]+/', strtolower(explode('@', (string)$value)[0])) as $part) {
            if (strlen($part) >= 4 && (str_contains($lower, $part) || $base === $part)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * @param string[] $personal The user's own name/email: a password built on them is rejected.
 * @return string[] One message per broken rule; empty when the password is acceptable.
 */
function password_policy_errors(string $password, string $label = 'Password', array $personal = []): array
{
    if (strlen($password) > PASSWORD_MAX_BYTES) {
        return ["{$label} must be " . PASSWORD_MAX_BYTES . ' characters or fewer.'];
    }

    $missing = [];
    foreach (password_rules() as [$text, $pattern]) {
        if (preg_match('/' . $pattern . '/u', $password) !== 1) {
            $missing[] = lcfirst($text);
        }
    }
    if ($missing !== []) {
        return ["{$label} must contain: " . implode(', ', $missing) . '.'];
    }

    if (password_is_common($password, $personal)) {
        return ["{$label} is too easy to guess (a common password, or based on your name or email). Please choose something less predictable."];
    }
    return [];
}

/** Checklist under a password input; password-rules.js ticks items off as the user types. */
function password_rules_checklist(string $inputId): string
{
    $html = '<ul class="pw-rules" data-for="' . htmlspecialchars($inputId) . '" aria-live="polite">';
    foreach (password_rules() as $key => [$text, $pattern]) {
        $html .= '<li data-rule="' . $key . '" data-pattern="' . htmlspecialchars($pattern) . '">'
            . htmlspecialchars($text) . '</li>';
    }
    return $html . '</ul>';
}
