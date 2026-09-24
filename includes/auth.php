<?php
/**
 * AfterBill - Session / auth guard
 * Include this at the top of any page that requires a logged-in user.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function require_login(): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: auth/login.php');
        exit;
    }
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}
