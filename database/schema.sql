-- Users/Accounts Table
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(255) UNIQUE NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    address TEXT NOT NULL,
    username VARCHAR(255),
    password VARCHAR(255),
    contact_number VARCHAR(20) NOT NULL,
    email VARCHAR(255) UNIQUE,
    qr_code_path VARCHAR(255),
    status ENUM('pending', 'approved', 'denied') DEFAULT 'pending',
    loyalty_points INT DEFAULT 0,
    points_year INT DEFAULT YEAR(CURDATE()),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Purchases/Transactions Table
CREATE TABLE IF NOT EXISTS transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(255) UNIQUE NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    description TEXT,
    water_type ENUM('regular', 'nowater') DEFAULT 'regular',
    quantity INT DEFAULT 1,
    price_per_unit DECIMAL(10, 2),
    discount DECIMAL(10, 2) DEFAULT 0,
    loyalty_points_earned INT DEFAULT 0,
    notes TEXT,
    amount_tendered DECIMAL(10, 2),
    `change` DECIMAL(10, 2),
    container_size VARCHAR(30),
    container_status VARCHAR(20),
    fulfillment_method VARCHAR(20),
    inventory_item_id INT,
    inventory_reserved TINYINT(1) NOT NULL DEFAULT 0,
    cancellation_reason VARCHAR(255),
    payment_method VARCHAR(20) DEFAULT 'cash',
    payment_reference VARCHAR(255),
    payment_status VARCHAR(20) DEFAULT 'pending',
    payment_date TIMESTAMP NULL,
    payment_proof VARCHAR(255),
    rider_id VARCHAR(50),
    status ENUM('pending', 'approved', 'denied') DEFAULT 'pending',
    delivery_status ENUM('pending', 'preparing', 'on_way', 'delivered') DEFAULT 'pending',
    approved_by VARCHAR(255),
    assigned_rider VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Admin Users Table
CREATE TABLE IF NOT EXISTS admin_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Activity Log Table
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id VARCHAR(255),
    action VARCHAR(255) NOT NULL,
    description TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Rider accounts, delivery updates, and live GPS tracking
CREATE TABLE IF NOT EXISTS rider_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rider_id VARCHAR(50) UNIQUE NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    age INT,
    address TEXT,
    contact_number VARCHAR(20),
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rider_locations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(50) NOT NULL,
    rider_id VARCHAR(50),
    rider_latitude DECIMAL(10,8) NOT NULL DEFAULT 12.8797,
    rider_longitude DECIMAL(11,8) NOT NULL DEFAULT 121.7740,
    accuracy FLOAT,
    speed FLOAT,
    heading FLOAT,
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rider_location_transaction (transaction_id),
    INDEX idx_rider_location_rider (rider_id),
    INDEX idx_rider_location_update (last_update)
);

CREATE TABLE IF NOT EXISTS rider_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    rider_id VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rider_notification (rider_id, is_read)
);

CREATE TABLE IF NOT EXISTS rider_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(50) NOT NULL,
    sender VARCHAR(50) NOT NULL,
    recipient VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rider_message_transaction (transaction_id)
);

CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_id VARCHAR(255) UNIQUE NOT NULL,
    transaction_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(20) NOT NULL,
    payment_reference VARCHAR(255),
    payment_status VARCHAR(20) DEFAULT 'pending',
    payment_proof VARCHAR(255),
    gcash_number VARCHAR(20),
    maya_number VARCHAR(20),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Feedback and Ratings Table
CREATE TABLE IF NOT EXISTS feedback_ratings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    rating INT NOT NULL,
    feedback_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_feedback_transaction_user (transaction_id, user_id),
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Reward point conversions and claim history
CREATE TABLE IF NOT EXISTS reward_claims (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(50) UNIQUE NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    reward_code VARCHAR(80) NOT NULL,
    reward_title VARCHAR(255) NOT NULL,
    points_used INT NOT NULL,
    claim_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    claimed_by VARCHAR(80),
    claimed_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reward_claim_status (claim_status),
    INDEX idx_reward_claim_user (user_id)
);

-- Staff-managed container inventory
CREATE TABLE IF NOT EXISTS inventory_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_code VARCHAR(50) UNIQUE NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'Container',
    quantity INT NOT NULL DEFAULT 0,
    minimum_stock INT NOT NULL DEFAULT 10,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    updated_by VARCHAR(80),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_movements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    movement_type VARCHAR(20) NOT NULL,
    quantity_change INT NOT NULL,
    previous_quantity INT NOT NULL,
    new_quantity INT NOT NULL,
    reason VARCHAR(255),
    staff_id VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_item (item_id),
    INDEX idx_inventory_created (created_at)
);

-- In-app and browser notifications for customers
CREATE TABLE IF NOT EXISTS user_notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(255) NOT NULL,
    transaction_id VARCHAR(255),
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    notification_type VARCHAR(30) NOT NULL DEFAULT 'info',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_notification (user_id, is_read, created_at)
);

-- Insert sample admin user (password: admin123)
INSERT IGNORE INTO admin_users (admin_id, username, password, full_name, role)
VALUES 
('ADM-001', 'admin', '$2y$10$N9qo8uLOickgx2ZMRZoHyOzyT1FBWzLUruPCiLYvPBa9cdVbNUNl2', 'John Admin', 'admin'),
('STF-001', 'staff1', '$2y$10$N9qo8uLOickgx2ZMRZoHyOzyT1FBWzLUruPCiLYvPBa9cdVbNUNl2', 'Sarah Staff', 'staff');

-- Insert sample user data
INSERT IGNORE INTO users (user_id, full_name, address, contact_number, email, status)
VALUES 
('USR-001', 'Juan Dela Cruz', '123 Main Street, Manila', '09171234567', 'juan@example.com', 'approved'),
('USR-002', 'Maria Santos', '456 Oak Avenue, Quezon City', '09175678901', 'maria@example.com', 'pending'),
('USR-003', 'Pedro Reyes', '789 Pine Road, Makati', '09179876543', 'pedro@example.com', 'approved'),
('USR-004', 'Ana Lopez', '321 Elm Street, Cavite', '09172234890', 'ana@example.com', 'denied');

-- Insert sample transactions
INSERT IGNORE INTO transactions (transaction_id, user_id, amount, description, status, approved_by)
VALUES 
('TRX-001', 'USR-001', 1500.00, 'Water Service Bill', 'approved', 'STF-001'),
('TRX-002', 'USR-002', 2000.00, 'Monthly Service Fee', 'pending', NULL),
('TRX-003', 'USR-003', 1200.00, 'Installation Fee', 'approved', 'STF-001'),
('TRX-004', 'USR-004', 3000.00, 'Service Upgrade', 'denied', 'STF-001'),
('TRX-005', 'USR-001', 1500.00, 'Monthly Service Fee', 'pending', NULL);
