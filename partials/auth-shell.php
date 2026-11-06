<?php
/**
 * AfterBill - Page frame for the small auth pages (forgot/reset password, verify email, resend)
 *
 * Same card as login/register: brand panel on the left (partials/auth-brand.php), form panel on
 * the right. Pages call auth_shell_start(), print their form, then auth_shell_end().
 */

function auth_shell_start(string $title, string $heading, string $sub): void
{
    $css = '../assets/css/auth.css?v=' . filemtime(__DIR__ . '/../assets/css/auth.css');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> – AfterBill</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%233B82F6'/%3E%3Ctext x='16' y='22' font-family='Arial,sans-serif' font-size='16' font-weight='bold' fill='white' text-anchor='middle'%3EA%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($css) ?>">
</head>
<body>
    <div class="auth-page">
        <div class="auth-card">
            <?php include __DIR__ . '/auth-brand.php'; ?>

            <div class="auth-form-panel">
                <div class="auth-form-inner">
                    <div class="form-logo">
                        <a href="../index.php" title="Back to home"><img src="../assets/images/logo.png" alt="AfterBill"></a>
                    </div>
                    <div class="form-header">
                        <h1><?= htmlspecialchars($heading) ?></h1>
                        <p><?= htmlspecialchars($sub) ?></p>
                    </div>
    <?php
}

/** @param string[] $messages */
function auth_shell_alerts(array $errors, string $success = ''): void
{
    if ($success !== '') {
        echo '<div class="alert alert-success">' . htmlspecialchars($success) . '</div>';
    }
    if ($errors) {
        echo '<div class="alert alert-error"><ul>';
        foreach ($errors as $err) {
            echo '<li>' . htmlspecialchars($err) . '</li>';
        }
        echo '</ul></div>';
    }
}

/** @param string[] $scripts paths under assets/js/ */
function auth_shell_end(array $scripts = []): void
{
    ?>
                    <div class="form-footer">
                        <a href="login.php">&larr; Back to log in</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php foreach ($scripts as $js): ?>
    <script src="../assets/js/<?= $js ?>?v=<?= filemtime(__DIR__ . '/../assets/js/' . $js) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
    <?php
}
