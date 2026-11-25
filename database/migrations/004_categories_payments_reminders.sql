-- AfterBill migration 004 — custom categories, payment receipts, email reminder settings
-- Run once against the `afterbill` database (phpMyAdmin > SQL, or:
--   mysql -uroot afterbill < database/migrations/004_categories_payments_reminders.sql)
-- Safe to re-run (MariaDB, as shipped with XAMPP).

-- ---- Custom categories --------------------------------------------------------------------
-- user_id NULL = built-in category everyone sees; otherwise the category belongs to one user.
ALTER TABLE `categories` ADD COLUMN IF NOT EXISTS `user_id` int(10) unsigned NULL DEFAULT NULL AFTER `category_id`;

-- Names were unique across the whole table; now they only need to be unique per owner (two users
-- may both create "Car"). The app also stops a custom name from repeating a built-in one.
ALTER TABLE `categories` DROP INDEX IF EXISTS `category_name`;
ALTER TABLE `categories` ADD UNIQUE KEY IF NOT EXISTS `uniq_categories_user_name` (`user_id`, `category_name`);
ALTER TABLE `categories` ADD CONSTRAINT `fk_categories_user`
    FOREIGN KEY IF NOT EXISTS (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- ---- Payment receipts ---------------------------------------------------------------------
-- Random file name inside storage/receipts/ (never served directly; see receipt.php).
ALTER TABLE `payments` ADD COLUMN IF NOT EXISTS `receipt_file` varchar(64) NULL DEFAULT NULL AFTER `notes`;

-- ---- Email reminders ----------------------------------------------------------------------
-- On by default; reminder_days = how many days ahead a bill counts as "due soon".
-- last_reminder_on stops cron/send_reminders.php from emailing anyone twice on the same day.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `reminder_emails` tinyint(1) NOT NULL DEFAULT 1 AFTER `password`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `reminder_days` tinyint(3) unsigned NOT NULL DEFAULT 3 AFTER `reminder_emails`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_reminder_on` date NULL DEFAULT NULL AFTER `reminder_days`;
