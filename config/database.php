<?php
/**
 * AfterBill - Database Configuration (PDO)
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'afterbill');
define('DB_USER', 'root');
define('DB_PASS', ''); // XAMPP default
define('DB_CHARSET', 'utf8mb4');

// One clock for the whole app. PHP (date(), DateTime) and MySQL (CURDATE(), NOW()) must agree on
// "today", otherwise between midnight and the offset difference a bill due today counts as
// tomorrow, payments made today are "in the future", etc. .htaccess sets the same zone for pages
// that don't load this file.
define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // MySQL follows PHP's offset (e.g. +08:00), whatever the server's own zone is.
            $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
        } catch (PDOException $e) {
            error_log('AfterBill DB connection failed: ' . $e->getMessage());
            die('Database connection failed. Please try again later.');
        }
    }

    return $pdo;
}