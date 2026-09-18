-- AfterBill - Bill Management Module (FR-02)
-- Adds `categories` and `bills` tables on top of the existing `users` table.
-- Run against the existing `afterbill` database (does not touch `users`).

CREATE TABLE IF NOT EXISTS categories (
    category_id   INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bills (
    bill_id       INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    bill_name     VARCHAR(100) NOT NULL,
    amount        DECIMAL(10,2) NOT NULL,
    due_date      DATE NOT NULL,
    category_id   INT NOT NULL,
    is_recurring  TINYINT(1) NOT NULL DEFAULT 0,
    status        ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bills_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_bills_category
        FOREIGN KEY (category_id) REFERENCES categories(category_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO categories (category_name) VALUES
    ('Utilities'),
    ('Internet'),
    ('Housing'),
    ('Education'),
    ('Insurance'),
    ('Subscriptions'),
    ('Others')
ON DUPLICATE KEY UPDATE category_name = category_name;
