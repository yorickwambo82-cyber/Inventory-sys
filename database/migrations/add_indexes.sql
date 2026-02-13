-- Database Indexes for Performance Optimization
-- Run this script to add indexes to frequently queried fields
-- This will significantly improve query performance

-- Phones table indexes
CREATE INDEX IF NOT EXISTS idx_phones_imei ON phones(iemi_number);
CREATE INDEX IF NOT EXISTS idx_phones_status ON phones(status);
CREATE INDEX IF NOT EXISTS idx_phones_created_at ON phones(created_at);
CREATE INDEX IF NOT EXISTS idx_phones_registered_by ON phones(registered_by);
CREATE INDEX IF NOT EXISTS idx_phones_brand_model ON phones(brand, model);

-- Accessories table indexes
CREATE INDEX IF NOT EXISTS idx_accessories_status ON accessories(status);
CREATE INDEX IF NOT EXISTS idx_accessories_category ON accessories(category);
CREATE INDEX IF NOT EXISTS idx_accessories_created_at ON accessories(created_at);

-- Sales table indexes
CREATE INDEX IF NOT EXISTS idx_sales_date ON sales(sale_date);
CREATE INDEX IF NOT EXISTS idx_sales_sold_by ON sales(sold_by);
CREATE INDEX IF NOT EXISTS idx_sales_item_type ON sales(item_type);
CREATE INDEX IF NOT EXISTS idx_sales_item_id ON sales(item_id);

-- Transfers table indexes
CREATE INDEX IF NOT EXISTS idx_transfers_date ON transfers(transfer_date);
CREATE INDEX IF NOT EXISTS idx_transfers_transferred_by ON transfers(transferred_by);
CREATE INDEX IF NOT EXISTS idx_transfers_item_type ON transfers(item_type);
CREATE INDEX IF NOT EXISTS idx_transfers_destination ON transfers(destination_shop_id);

-- Activity log indexes
CREATE INDEX IF NOT EXISTS idx_activity_user_id ON activity_log(user_id);
CREATE INDEX IF NOT EXISTS idx_activity_type ON activity_log(activity_type);
CREATE INDEX IF NOT EXISTS idx_activity_timestamp ON activity_log(timestamp);

-- Users table indexes
CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_users_active ON users(is_active);

-- Composite indexes for common queries
CREATE INDEX IF NOT EXISTS idx_phones_status_shop ON phones(status, shop_id);
CREATE INDEX IF NOT EXISTS idx_sales_date_sold_by ON sales(sale_date, sold_by);
CREATE INDEX IF NOT EXISTS idx_activity_user_type ON activity_log(user_id, activity_type);

-- Show created indexes
SHOW INDEX FROM phones;
SHOW INDEX FROM accessories;
SHOW INDEX FROM sales;
SHOW INDEX FROM transfers;
SHOW INDEX FROM activity_log;
SHOW INDEX FROM users;
