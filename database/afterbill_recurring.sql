-- AfterBill - Recurring Bills (completes FR-02)
-- Adds bills.parent_bill_id so a recurring bill's auto-generated next cycle can be traced back
-- to the bill that produced it, and duplicate generation can be detected by a simple lookup
-- instead of matching on name/date (which breaks if the bill is later edited).
-- Run against the existing `afterbill` database (does not touch users/categories/payments).

ALTER TABLE bills ADD COLUMN IF NOT EXISTS parent_bill_id INT NULL AFTER category_id;

-- A bill can have at most one auto-generated child. NULL is allowed to repeat freely (every
-- non-recurring bill, and every recurring bill's very first cycle, has parent_bill_id = NULL).
ALTER TABLE bills ADD UNIQUE KEY IF NOT EXISTS uniq_bills_parent_bill_id (parent_bill_id);

-- MariaDB 10.4 does not support "ADD CONSTRAINT ... IF NOT EXISTS" for foreign keys, so the
-- FK is added conditionally via information_schema to keep this migration re-runnable.
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bills'
      AND CONSTRAINT_NAME = 'fk_bills_parent_bill'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE bills ADD CONSTRAINT fk_bills_parent_bill
        FOREIGN KEY (parent_bill_id) REFERENCES bills(bill_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
