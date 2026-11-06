<?php
/**
 * AfterBill - Server database settings (TEMPLATE)
 *
 * On the hosting server: copy this file to config/database.local.php and fill in the values from
 * the host's database panel (e.g. Hostinger > Databases > MySQL Databases). That copy is in
 * .gitignore — never commit a real password. Your local XAMPP copy needs no such file.
 */

define('DB_HOST', 'localhost');                 // Hostinger shared hosting: localhost
define('DB_NAME', 'u000000000_afterbill');      // full name, including the u…_ prefix
define('DB_USER', 'u000000000_afterbill');
define('DB_PASS', 'your-database-password');
