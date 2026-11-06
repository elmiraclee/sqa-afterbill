<?php
/**
 * AfterBill - Mail configuration
 *
 * Defaults are safe to commit: emails are written to storage/mail.log instead of being sent,
 * so password reset and verification work on any machine without an email account.
 *
 * To send real email, copy config/mail.local.example.php to config/mail.local.php (git ignores
 * it — never commit a password) and fill in your SMTP details there.
 */

$MAIL_CONFIG = [
    // 'log'  = write emails to storage/mail.log (open the links from there)
    // 'smtp' = send through the SMTP server below (e.g. Gmail)
    'driver'     => 'log',

    // Base URL used in emailed links. Fixed here on purpose: building links from the request's
    // Host header would let an attacker get reset links pointing at their own site.
    'app_url'    => 'http://localhost/sqa-afterbill',

    'from_email' => 'no-reply@afterbill.test',
    'from_name'  => 'AfterBill',

    'smtp_host'     => 'smtp.gmail.com',
    'smtp_port'     => 587,          // 587 = STARTTLS
    'smtp_username' => '',
    'smtp_password' => '',

    'log_file'   => __DIR__ . '/../storage/mail.log',
];

if (is_file(__DIR__ . '/mail.local.php')) {
    $MAIL_CONFIG = array_merge($MAIL_CONFIG, require __DIR__ . '/mail.local.php');
}
