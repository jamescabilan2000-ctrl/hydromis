-- Rider accounts and delivery assignment support
CREATE TABLE IF NOT EXISTS rider_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rider_id VARCHAR(50) UNIQUE NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE transactions
ADD COLUMN IF NOT EXISTS rider_id VARCHAR(50) NULL,
ADD INDEX IF NOT EXISTS idx_rider_id (rider_id);

-- Default rider account (password: rider123)
INSERT INTO rider_users (rider_id, username, password, full_name, contact_number, status)
SELECT 'RID-001', 'rider1', '$2y$10$nsEybq.8DaS0wW2YReDrnujuAl/HoQQINKT5LBYvyBDoNXa6TsfMm', 'Default Rider', '09990000001', 'active'
WHERE NOT EXISTS (SELECT 1 FROM rider_users WHERE username = 'rider1');
