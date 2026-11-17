-- Per-order delivery destination; the registered customer address is unchanged.
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS delivery_address VARCHAR(1024) NULL;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS delivery_latitude DECIMAL(10,8) NULL;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS delivery_longitude DECIMAL(11,8) NULL;
