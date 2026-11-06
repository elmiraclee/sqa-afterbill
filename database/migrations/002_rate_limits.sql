-- AfterBill migration 002 — per-IP rate limits (includes/rate_limit.php)
-- Run once against the `afterbill` database (phpMyAdmin > SQL, or:
--   mysql -uroot afterbill < database/migrations/002_rate_limits.sql)
-- Safe to re-run: uses IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `action` varchar(30) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rate_limits_lookup` (`ip`, `action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
