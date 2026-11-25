-- AfterBill migration 005 — repeat schedules, two-factor login, signed-in devices
-- Run once against the `afterbill` database (phpMyAdmin > SQL, or:
--   mysql -uroot afterbill < database/migrations/005_more_features.sql)
-- Safe to re-run (MariaDB, as shipped with XAMPP). Needs migration 004 first.

-- ---- Repeat schedules ---------------------------------------------------------------------
-- How often a recurring bill repeats, and an optional last date (NULL = never ends).
-- Existing recurring bills keep repeating monthly.
ALTER TABLE `bills` ADD COLUMN IF NOT EXISTS `repeat_interval` enum('weekly','monthly','quarterly','yearly')
    NOT NULL DEFAULT 'monthly' AFTER `is_recurring`;
ALTER TABLE `bills` ADD COLUMN IF NOT EXISTS `repeat_until` date NULL DEFAULT NULL AFTER `repeat_interval`;

-- ---- Two-factor login (authenticator app) -------------------------------------------------
-- totp_secret: base32 key shared with the user's authenticator app; only used once
-- totp_enabled_at is set (after the user proved their app works). totp_last_step stops one
-- code from being used twice.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `totp_secret` varchar(64) NULL DEFAULT NULL AFTER `password`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `totp_enabled_at` datetime NULL DEFAULT NULL AFTER `totp_secret`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `totp_last_step` bigint(20) NULL DEFAULT NULL AFTER `totp_enabled_at`;

-- One-time recovery codes for when the phone is lost. Only SHA-256 hashes are stored.
CREATE TABLE IF NOT EXISTS `user_recovery_codes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_recovery_user` (`user_id`),
  CONSTRAINT `fk_recovery_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---- Signed-in devices ----------------------------------------------------------------------
-- One row per signed-in browser. session_hash = SHA-256 of the PHP session id (the id itself is
-- never stored). Deleting a row signs that browser out on its next request.
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `session_hash` char(64) NOT NULL,
  `auth_fp` char(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_sessions_hash` (`session_hash`),
  KEY `idx_user_sessions_user` (`user_id`, `last_seen_at`),
  CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
