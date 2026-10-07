-- Run once against the existing HydroMIS MySQL/MariaDB database before deploying PHP changes.
ALTER TABLE transactions
    ADD COLUMN order_water_subtotal DECIMAL(12,2) NULL,
    ADD COLUMN cap_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN cap_unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN cap_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00;
-- No example cap price is seeded. Configure it in Admin > Settings > Pricing.
