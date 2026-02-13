-- Database Foreign Keys for Referential Integrity
-- Run this script to add foreign key constraints
-- IMPORTANT: Backup your database before running this script!

-- Note: This script assumes your data is already consistent
-- If you have orphaned records, clean them up first

-- Add foreign keys to phones table
ALTER TABLE phones
ADD CONSTRAINT fk_phones_registered_by 
FOREIGN KEY (registered_by) 
REFERENCES users(user_id) 
ON DELETE SET NULL 
ON UPDATE CASCADE;

-- Add foreign keys to accessories table
ALTER TABLE accessories
ADD CONSTRAINT fk_accessories_registered_by 
FOREIGN KEY (registered_by) 
REFERENCES users(user_id) 
ON DELETE SET NULL 
ON UPDATE CASCADE;

-- Add foreign keys to sales table
ALTER TABLE sales
ADD CONSTRAINT fk_sales_sold_by 
FOREIGN KEY (sold_by) 
REFERENCES users(user_id) 
ON DELETE SET NULL 
ON UPDATE CASCADE;

-- Note: We cannot add FK for item_id because it references different tables
-- based on item_type (phones or accessories). This is a polymorphic relationship.

-- Add foreign keys to transfers table
ALTER TABLE transfers
ADD CONSTRAINT fk_transfers_transferred_by 
FOREIGN KEY (transferred_by) 
REFERENCES users(user_id) 
ON DELETE SET NULL 
ON UPDATE CASCADE;

-- Note: Same polymorphic relationship issue for item_id in transfers

-- Add foreign keys to activity_log table
ALTER TABLE activity_log
ADD CONSTRAINT fk_activity_log_user_id 
FOREIGN KEY (user_id) 
REFERENCES users(user_id) 
ON DELETE CASCADE 
ON UPDATE CASCADE;

-- Show foreign keys
SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM
    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE
    REFERENCED_TABLE_SCHEMA = DATABASE()
    AND TABLE_SCHEMA = DATABASE()
ORDER BY
    TABLE_NAME;
