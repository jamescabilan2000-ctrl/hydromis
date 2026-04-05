-- Create rider_locations table to store real-time rider GPS data
CREATE TABLE IF NOT EXISTS rider_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(50) NOT NULL UNIQUE,
    rider_latitude DECIMAL(10, 8) NOT NULL DEFAULT 12.8797,
    rider_longitude DECIMAL(11, 8) NOT NULL DEFAULT 121.7740,
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE,
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_last_update (last_update)
);

-- Add rider_id column to transactions table if it doesn't exist
-- ALTER TABLE transactions ADD COLUMN rider_id INT DEFAULT NULL;
