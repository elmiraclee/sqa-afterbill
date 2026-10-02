<?php
/**
 * AfterBill - CSRF protection
 *
 * One random token per session. Every POST form renders csrf_field(), and every POST handler
 * calls csrf_valid() before changing anything, so a form auto-submitted from another site
 * (which cannot read the token) is rejected. Requires session_start() to have run.
 */

const CSRF_ERROR = 'Your session expired or the form was submitted from somewhere else. Please try again.';

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
}
