ALTER TABLE transactions ADD COLUMN IF NOT EXISTS price_per_unit DECIMAL(10, 2) AFTER quantity;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS loyalty_points_earned INT DEFAULT 0 AFTER discount;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS notes TEXT AFTER loyalty_points_earned;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS amount_tendered DECIMAL(10, 2) AFTER notes;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS `change` DECIMAL(10, 2) AFTER amount_tendered;
