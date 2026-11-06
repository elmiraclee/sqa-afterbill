-- AfterBill migration 003 — email verification, password reset, confirmed email changes
-- Run once against the `afterbill` database (phpMyAdmin > SQL, or:
--   mysql -uroot afterbill < database/migrations/003_email_verification_password_reset.sql)
-- Safe to re-run.

-- NULL = the user has not clicked the verification link yet (they cannot log in).
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email_verified_at` datetime NULL DEFAULT NULL AFTER `email`;

-- Accounts that existed before verification was introduced are trusted as-is, so nobody is
-- locked out. Only runs while no account has been verified yet (i.e. the first time).
UPDATE `users` SET `email_verified_at` = `created_at`
WHERE `email_verified_at` IS NULL
  AND NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `users` WHERE `email_verified_at` IS NOT NULL) AS v);

-- One row per emailed link. Only a SHA-256 hash of the token is stored, so a leaked database
-- copy can't be used to reset anyone's password. `data` holds the new address for email_change.
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `purpose` enum('verify_email','reset_password','email_change') NOT NULL,
  `token_hash` char(64) NOT NULL,
  `data` varchar(180) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_auth_tokens_hash` (`token_hash`),
  KEY `idx_auth_tokens_user` (`user_id`, `purpose`),
  CONSTRAINT `fk_auth_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
