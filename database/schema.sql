-- Create Database
CREATE DATABASE IF NOT EXISTS hydromis;
USE hydromis;

-- Users/Accounts Table
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(255) UNIQUE NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    address TEXT NOT NULL,
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

-- Insert sample admin user (password: admin123)
INSERT INTO admin_users (admin_id, username, password, full_name, role) 
VALUES 
('ADM-001', 'admin', '$2y$10$N9qo8uLOickgx2ZMRZoHyOzyT1FBWzLUruPCiLYvPBa9cdVbNUNl2', 'John Admin', 'admin'),
('STF-001', 'staff1', '$2y$10$N9qo8uLOickgx2ZMRZoHyOzyT1FBWzLUruPCiLYvPBa9cdVbNUNl2', 'Sarah Staff', 'staff');

-- Insert sample user data
INSERT INTO users (user_id, full_name, address, contact_number, email, status) 
VALUES 
('USR-001', 'Juan Dela Cruz', '123 Main Street, Manila', '09171234567', 'juan@example.com', 'approved'),
('USR-002', 'Maria Santos', '456 Oak Avenue, Quezon City', '09175678901', 'maria@example.com', 'pending'),
('USR-003', 'Pedro Reyes', '789 Pine Road, Makati', '09179876543', 'pedro@example.com', 'approved'),
('USR-004', 'Ana Lopez', '321 Elm Street, Cavite', '09172234890', 'ana@example.com', 'denied');

-- Insert sample transactions
INSERT INTO transactions (transaction_id, user_id, amount, description, status, approved_by) 
VALUES 
('TRX-001', 'USR-001', 1500.00, 'Water Service Bill', 'approved', 'STF-001'),
('TRX-002', 'USR-002', 2000.00, 'Monthly Service Fee', 'pending', NULL),
('TRX-003', 'USR-003', 1200.00, 'Installation Fee', 'approved', 'STF-001'),
('TRX-004', 'USR-004', 3000.00, 'Service Upgrade', 'denied', 'STF-001'),
('TRX-005', 'USR-001', 1500.00, 'Monthly Service Fee', 'pending', NULL);
