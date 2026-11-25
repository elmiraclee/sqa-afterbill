<?php
/**
 * AfterBill - Categories
 *
 * Built-in categories (user_id NULL) are shared by everyone; custom categories belong to one user
 * and are only ever visible to, and usable by, that user. Every page that lists or validates a
 * category goes through userCategories() so another user's category id is never accepted.
 * Table change: database/migrations/004_categories_payments_reminders.sql
 */

const CATEGORY_NAME_MIN = 2;
const CATEGORY_NAME_MAX = 50;      // categories.category_name is varchar(50)
const CATEGORY_CUSTOM_LIMIT = 20;  // per user
const CATEGORY_FALLBACK_NAME = 'Others'; // where bills go when their custom category is deleted

/**
 * Categories this user may use: built-ins first (in their fixed order), then their own by name.
 * Each row: category_id (int), category_name, is_custom (bool).
 * Before migration 004 there is no user_id column, so only the built-ins are returned.
 */
function userCategories(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT category_id, category_name, user_id IS NOT NULL AS is_custom
             FROM categories
             WHERE user_id IS NULL OR user_id = ?
             ORDER BY user_id IS NOT NULL, IF(user_id IS NULL, category_id, 0), category_name'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        $rows = $pdo->query('SELECT category_id, category_name, 0 AS is_custom FROM categories ORDER BY category_id')->fetchAll();
    }
    foreach ($rows as &$row) {
        $row['category_id'] = (int)$row['category_id'];
        $row['is_custom'] = (bool)$row['is_custom'];
    }
    unset($row);
    return $rows;
}

/** True if $categoryId (raw request value) is one this user may put a bill in. */
function isUsableCategory(array $categories, $categoryId): bool
{
    return ctype_digit((string)$categoryId)
        && in_array((int)$categoryId, array_column($categories, 'category_id'), true);
}

/** Error message for a proposed category name, or null if it is fine. $name must be trimmed. */
function categoryNameError(string $name): ?string
{
    $len = mb_strlen($name);
    if ($len < CATEGORY_NAME_MIN || $len > CATEGORY_NAME_MAX) {
        return 'Category name must be between ' . CATEGORY_NAME_MIN . ' and ' . CATEGORY_NAME_MAX . ' characters.';
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $name) || !mb_check_encoding($name, 'UTF-8')) {
        return 'Category name contains characters that are not allowed.';
    }
    return null;
}

/**
 * True if the name is already taken for this user: by a built-in category or one of their own
 * (the column collation is case-insensitive, so "car" and "Car" clash). $exceptId skips the
 * category being renamed.
 */
function categoryNameTaken(PDO $pdo, int $userId, string $name, int $exceptId = 0): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM categories
         WHERE category_name = ? AND (user_id IS NULL OR user_id = ?) AND category_id != ?
         LIMIT 1'
    );
    $stmt->execute([$name, $userId, $exceptId]);
    return (bool)$stmt->fetchColumn();
}

/** id of the built-in "Others" category (bills are moved there when a custom category is deleted). */
function fallbackCategoryId(PDO $pdo): ?int
{
    $stmt = $pdo->prepare('SELECT category_id FROM categories WHERE category_name = ? AND user_id IS NULL LIMIT 1');
    $stmt->execute([CATEGORY_FALLBACK_NAME]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}
