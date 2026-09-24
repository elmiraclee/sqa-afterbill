<?php
/**
 * AfterBill - Shared helpers, constants and small data-access functions.
 */

require_once __DIR__ . '/../config/database.php';

const BILL_CATEGORIES = [
    'Electricity', 'Water', 'Internet', 'Phone', 'Rent',
    'Insurance', 'Subscription', 'Transportation', 'Other',
];

const BILL_FREQUENCIES = ['One-time', 'Weekly', 'Monthly', 'Quarterly', 'Yearly'];

const CURRENCIES = [
    'PHP' => '₱',
    'USD' => '$',
    'EUR' => '€',
    'JPY' => '¥',
];

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function currency_symbol(?string $code): string
{
    return CURRENCIES[$code] ?? '₱';
}

function format_money(float $amount, ?string $currency = 'PHP'): string
{
    return currency_symbol($currency) . number_format($amount, 2);
}

/**
 * Derives the effective display status of a bill.
 * DB only stores 'unpaid' | 'paid' — overdue/upcoming are computed here
 * so a bill automatically becomes "overdue" once its due date passes.
 */
function bill_display_status(string $status, string $dueDate): string
{
    if ($status === 'paid') {
        return 'paid';
    }
    return $dueDate < date('Y-m-d') ? 'overdue' : 'upcoming';
}

function status_badge_class(string $displayStatus): string
{
    return match ($displayStatus) {
        'paid' => 'paid',
        'overdue' => 'overdue',
        default => 'pending',
    };
}

function status_label(string $displayStatus): string
{
    return match ($displayStatus) {
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        default => 'Upcoming',
    };
}

function category_icon(string $category): string
{
    return match ($category) {
        'Electricity' => '⚡',
        'Water' => '💧',
        'Internet' => '🌐',
        'Phone' => '📱',
        'Rent' => '🏠',
        'Insurance' => '🛡️',
        'Subscription' => '🔁',
        'Transportation' => '🚗',
        default => '🧾',
    };
}

/* ── Flash messages (PRG pattern: set on action, read + clear on next render) ── */

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][$type][] = $message;
}

function flash_get_all(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/* ── User lookup ── */

function get_user(int $userId): ?array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}
