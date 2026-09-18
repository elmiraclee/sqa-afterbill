-- AfterBill - Payments Module
-- Adds a `payments` table on top of the existing `bills` table.
-- Run against the existing `afterbill` database (does not touch `bills`, `users`, or `categories`).

CREATE TABLE IF NOT EXISTS payments (
    payment_id     INT AUTO_INCREMENT PRIMARY KEY,
    bill_id        INT NOT NULL,
    payment_date   DATE NOT NULL,
    amount_paid    DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'gcash', 'debit_card', 'credit_card', 'other') NOT NULL,
    notes          VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_bill
        FOREIGN KEY (bill_id) REFERENCES bills(bill_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
