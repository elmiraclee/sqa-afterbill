<?php
/**
 * AfterBill - Local mail settings (TEMPLATE)
 *
 * 1. Copy this file to config/mail.local.php (that copy is in .gitignore — never commit it).
 * 2. Gmail: turn on 2-Step Verification for the Google account, then create an App Password at
 *    https://myaccount.google.com/apppasswords and paste the 16 characters below.
 * 3. Set 'driver' => 'smtp'.
 *
 * Emails to addresses ending in .test or .localhost are always written to storage/mail.log
 * (those domains can't receive mail), so the test suite never sends real email.
 */

return [
    'driver'        => 'smtp',
    'from_email'    => 'your.address@gmail.com',   // Gmail requires this to be the account below
    'from_name'     => 'AfterBill',
    'smtp_username' => 'your.address@gmail.com',
    'smtp_password' => 'xxxx xxxx xxxx xxxx',      // the 16-character App Password, not your login password
];
