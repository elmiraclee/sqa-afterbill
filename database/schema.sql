-- ============================================================
-- AfterBill - Database Schema
-- Run this in phpMyAdmin (database: afterbill) if you need to
-- (re)create the schema manually. Safe to re-run: uses
-- IF NOT EXISTS / ADD COLUMN IF NOT EXISTS guards.
-- ============================================================

-- Existing `users` table (created by registration flow) is extended
-- with profile/preference fields used by Settings.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS username VARCHAR(50) NULL UNIQUE AFTER full_name,
    ADD COLUMN IF NOT EXISTS currency VARCHAR(3) NOT NULL DEFAULT 'PHP' AFTER password,
    ADD COLUMN IF NOT EXISTS reminder_days_before TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER currency,
    ADD COLUMN IF NOT EXISTS notify_in_app TINYINT(1) NOT NULL DEFAULT 1 AFTER reminder_days_before;

-- Bills: one row per bill. `status` tracks paid/unpaid; "overdue" and
-- "upcoming" are derived from status + due_date at query time.
CREATE TABLE IF NOT EXISTS bills (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'Other',
    amount DECIMAL(12,2) NOT NULL,
    due_date DATE NOT NULL,
    frequency VARCHAR(20) NOT NULL DEFAULT 'One-time',
    status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    notes TEXT NULL,
    reminder_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bills_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_bills_user (user_id),
    INDEX idx_bills_due (due_date),
    INDEX idx_bills_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reminders: user-created reminders, optionally linked to a bill.
CREATE TABLE IF NOT EXISTS reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    bill_id INT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    remind_at DATETIME NOT NULL,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reminders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reminders_bill FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL,
    INDEX idx_reminders_user (user_id),
    INDEX idx_reminders_remind_at (remind_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
