-- Add rider_locations table for live GPS tracking
CREATE TABLE IF NOT EXISTS rider_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(255) NOT NULL,
    rider_id VARCHAR(50) NOT NULL,
    rider_latitude DECIMAL(10, 8) NOT NULL,
    rider_longitude DECIMAL(11, 8) NOT NULL,
    accuracy FLOAT,
    speed FLOAT,
    heading FLOAT,
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
    FOREIGN KEY (rider_id) REFERENCES rider_users(rider_id),
    UNIQUE KEY unique_transaction_rider (transaction_id, rider_id),
    INDEX idx_rider_id (rider_id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_last_update (last_update)
);
