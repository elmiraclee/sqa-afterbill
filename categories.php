<?php
/**
 * AfterBill - Categories
 * Built-in categories plus the user's own: add, rename and delete custom categories.
 * Deleting one moves its bills to "Others" and removes its budget.
 */

require_once __DIR__ . '/includes/auth.php';
require_login(); // session check, idle timeout, sign-out after a password change

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/activity.php';
require_once __DIR__ . '/includes/categories.php';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));

$pdo = getPDO();
$userId = (int)$_SESSION['user_id'];

// Migration 004 adds categories.user_id; without it custom categories can't be stored.
$migrated = true;
try {
    $pdo->query('SELECT user_id FROM categories LIMIT 0');
} catch (PDOException $e) {
    $migrated = false;
}

$errors = [];
$addValue = '';
$renameId = 0;      // row that shows the submitted (invalid) rename again
$renameValue = '';

if ($migrated && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_valid()) {
        $errors[] = CSRF_ERROR;
    }

    // The category being renamed/deleted must be one of THIS user's custom categories.
    $own = null;
    if ($action === 'rename' || $action === 'delete') {
        $categoryId = $_POST['category_id'] ?? '';
        if (ctype_digit((string)$categoryId)) {
            $stmt = $pdo->prepare('SELECT category_id, category_name FROM categories WHERE category_id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([(int)$categoryId, $userId]);
            $own = $stmt->fetch() ?: null;
        }
        if ($own === null) {
            $errors[] = 'That category was not found, or it is a built-in category that cannot be changed.';
        }
    }

    if ($action === 'add') {
        $name = trim((string)($_POST['category_name'] ?? ''));
        $addValue = $name;
        if (empty($errors)) {
            $nameError = categoryNameError($name);
            if ($nameError !== null) {
                $errors[] = $nameError;
            } elseif (categoryNameTaken($pdo, $userId, $name)) {
                $errors[] = 'You already have a category called "' . $name . '".';
            } else {
                $count = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE user_id = ?');
                $count->execute([$userId]);
                if ((int)$count->fetchColumn() >= CATEGORY_CUSTOM_LIMIT) {
                    $errors[] = 'You can have up to ' . CATEGORY_CUSTOM_LIMIT . ' custom categories. Delete one you no longer use first.';
                }
            }
        }
        if (empty($errors)) {
            try {
                $pdo->prepare('INSERT INTO categories (user_id, category_name) VALUES (?, ?)')->execute([$userId, $name]);
                logActivity($pdo, $userId, 'category_added', $name);
                header('Location: categories.php?added=1');
                exit;
            } catch (PDOException $e) {
                // 1062: the unique key caught a duplicate submitted twice at the same moment.
                $errors[] = ($e->errorInfo[1] ?? null) === 1062
                    ? 'You already have a category called "' . $name . '".'
                    : 'Could not add the category. Please try again later.';
            }
        }
    } elseif ($action === 'rename') {
        $name = trim((string)($_POST['category_name'] ?? ''));
        $renameId = $own ? (int)$own['category_id'] : 0;
        $renameValue = $name;
        if (empty($errors)) {
            $nameError = categoryNameError($name);
            if ($nameError !== null) {
                $errors[] = $nameError;
            } elseif (categoryNameTaken($pdo, $userId, $name, (int)$own['category_id'])) {
                $errors[] = 'You already have a category called "' . $name . '".';
            }
        }
        if (empty($errors)) {
            if ($name === $own['category_name']) {
                header('Location: categories.php');
                exit;
            }
            try {
                $pdo->prepare('UPDATE categories SET category_name = ? WHERE category_id = ? AND user_id = ?')
                    ->execute([$name, (int)$own['category_id'], $userId]);
                logActivity($pdo, $userId, 'category_renamed', $own['category_name'] . ' → ' . $name);
                header('Location: categories.php?renamed=1');
                exit;
            } catch (PDOException $e) {
                $errors[] = ($e->errorInfo[1] ?? null) === 1062
                    ? 'You already have a category called "' . $name . '".'
                    : 'Could not rename the category. Please try again later.';
            }
        }
    } elseif ($action === 'delete') {
        $fallbackId = fallbackCategoryId($pdo);
        if (empty($errors) && $fallbackId === null) {
            $errors[] = 'The built-in "' . CATEGORY_FALLBACK_NAME . '" category is missing, so bills could not be moved. Nothing was deleted.';
        }
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                $moved = $pdo->prepare('UPDATE bills SET category_id = ? WHERE category_id = ? AND user_id = ?');
                $moved->execute([$fallbackId, (int)$own['category_id'], $userId]);
                $movedCount = $moved->rowCount();
                $pdo->prepare('DELETE FROM budgets WHERE category_id = ? AND user_id = ?')
                    ->execute([(int)$own['category_id'], $userId]);
                $pdo->prepare('DELETE FROM categories WHERE category_id = ? AND user_id = ?')
                    ->execute([(int)$own['category_id'], $userId]);
                $pdo->commit();

                logActivity($pdo, $userId, 'category_deleted', $own['category_name']
                    . ($movedCount > 0 ? ' — ' . $movedCount . ' bill' . ($movedCount === 1 ? '' : 's') . ' moved to ' . CATEGORY_FALLBACK_NAME : ''));
                header('Location: categories.php?deleted=' . $movedCount);
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Could not delete the category. Please try again later.';
            }
        }
    } elseif (empty($errors)) {
        $errors[] = 'Unknown action.';
    }
}

$categories = userCategories($pdo, $userId);

// Number of this user's bills in each category.
$countStmt = $pdo->prepare('SELECT category_id, COUNT(*) FROM bills WHERE user_id = ? GROUP BY category_id');
$countStmt->execute([$userId]);
$billCounts = array_map('intval', $countStmt->fetchAll(PDO::FETCH_KEY_PAIR));

$customCount = count(array_filter($categories, fn($c) => $c['is_custom']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories – AfterBill</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        .category-form { display: inline-flex; align-items: center; gap: 6px; margin: 0; }
        .category-form input {
            width: 200px; padding: 7px 10px; border: 1.5px solid var(--border); border-radius: 7px;
            font-size: 0.82rem; font-family: inherit;
        }
        .category-form input.invalid { border-color: var(--danger); }
        .add-panel { padding: 18px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .status-badge.builtin { background: var(--light-gray); color: var(--text-muted); }
        .status-badge.custom { background: #EFF6FF; color: #1D4ED8; }
        .category-actions { flex-wrap: nowrap; }
    </style>
</head>
<body>
<div class="app">

    <?php $activePage = 'categories'; include __DIR__ . '/partials/sidebar.php'; ?>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <h2>Categories</h2>
                <p>Built-in categories plus your own</p>
            </div>
            <div class="topbar-right">
                <?php require __DIR__ . '/partials/logout-button.php'; ?>
            </div>
        </header>

        <main class="page-body">

            <?php if (!$migrated): ?>
                <div class="alert alert-error">Custom categories are not set up in this database yet. Run database/migrations/004_categories_payments_reminders.sql.</div>
            <?php endif; ?>
            <?php if (isset($_GET['added'])): ?>
                <div class="alert alert-success">Category added. You can pick it when adding or editing a bill.</div>
            <?php endif; ?>
            <?php if (isset($_GET['renamed'])): ?>
                <div class="alert alert-success">Category renamed.</div>
            <?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?>
                <?php $moved = (int)$_GET['deleted']; ?>
                <div class="alert alert-success">
                    Category deleted.<?php if ($moved > 0): ?> <?= $moved ?> bill<?= $moved === 1 ? ' was' : 's were' ?> moved to <?= CATEGORY_FALLBACK_NAME ?>.<?php endif; ?>
                </div>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-error"><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>

            <?php if ($migrated): ?>
                <div class="panel add-panel">
                    <div>
                        <div class="panel-title">Add a category</div>
                        <div class="muted"><?= $customCount ?> of <?= CATEGORY_CUSTOM_LIMIT ?> custom categories used</div>
                    </div>
                    <form method="POST" action="" class="category-form" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add">
                        <input type="text" name="category_name" maxlength="<?= CATEGORY_NAME_MAX ?>" placeholder="e.g. Car, Pets, Loans"
                               aria-label="New category name" value="<?= htmlspecialchars($addValue) ?>"
                               class="<?= $addValue !== '' && $errors ? 'invalid' : '' ?>">
                        <button type="submit" class="btn-primary">Add</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="panel">
                <div class="panel-header">
                    <span class="panel-title">All categories</span>
                    <span class="panel-badge blue"><?= count($categories) ?> total</span>
                </div>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Type</th>
                                <th>Your bills</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <?php $id = $cat['category_id']; $bills = $billCounts[$id] ?? 0; ?>
                                <tr>
                                    <td class="strong"><?= htmlspecialchars($cat['category_name']) ?></td>
                                    <td>
                                        <span class="status-badge <?= $cat['is_custom'] ? 'custom' : 'builtin' ?>"><?= $cat['is_custom'] ? 'Custom' : 'Built-in' ?></span>
                                    </td>
                                    <td>
                                        <?php if ($bills > 0): ?>
                                            <a href="bills.php?category_id=<?= $id ?>"><?= $bills ?> bill<?= $bills === 1 ? '' : 's' ?></a>
                                        <?php else: ?>
                                            <span class="muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($cat['is_custom']): ?>
                                            <div class="actions-cell category-actions">
                                                <form method="POST" action="" class="category-form" novalidate>
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="rename">
                                                    <input type="hidden" name="category_id" value="<?= $id ?>">
                                                    <input type="text" name="category_name" maxlength="<?= CATEGORY_NAME_MAX ?>"
                                                           aria-label="New name for <?= htmlspecialchars($cat['category_name']) ?>"
                                                           value="<?= htmlspecialchars($renameId === $id ? $renameValue : $cat['category_name']) ?>"
                                                           class="<?= $renameId === $id && $errors ? 'invalid' : '' ?>">
                                                    <button type="submit" class="btn-outline blue">Rename</button>
                                                </form>
                                                <form method="POST" action="" class="inline-form"
                                                      data-confirm="<?= htmlspecialchars('Delete "' . $cat['category_name'] . '"?'
                                                          . ($bills > 0 ? ' Its ' . $bills . ' bill' . ($bills === 1 ? '' : 's') . ' will move to ' . CATEGORY_FALLBACK_NAME . '.' : '')
                                                          . ' Any budget for it is removed.') ?>">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="category_id" value="<?= $id ?>">
                                                    <button type="submit" class="btn-outline red">Delete</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="muted">Can't be changed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="muted">
                Custom categories are private to your account. They work everywhere built-in ones do: bills, filters,
                budgets and reports. Deleting one moves its bills to <?= CATEGORY_FALLBACK_NAME ?> and removes its budget.
            </p>
        </main>
    </div>
</div>
    <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
