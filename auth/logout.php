<?php
/**
 * AfterBill - Logout
 * POST-only with a CSRF token (form in partials/logout-button.php). A GET or a forged POST
 * does nothing, so another site cannot sign the user out with a link or an <img> tag.
 */

session_start();

require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid()) {
    header('Location: ' . (isset($_SESSION['user_id']) ? '../dashboard.php' : 'login.php'));
    exit;
}

if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/activity.php';
    logActivity(getPDO(), (int)$_SESSION['user_id'], 'logout');
}

session_unset();
session_destroy();

header('Location: login.php');
exit;
