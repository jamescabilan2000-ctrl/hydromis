-- Add payment-related columns to transactions table
ALTER TABLE transactions 
ADD COLUMN IF NOT EXISTS payment_method ENUM('cash', 'gcash', 'maya') DEFAULT 'cash',
ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(255),
ADD COLUMN IF NOT EXISTS payment_status ENUM('pending', 'processing', 'paid', 'failed') DEFAULT 'pending',
ADD COLUMN IF NOT EXISTS payment_date TIMESTAMP NULL,
ADD COLUMN IF NOT EXISTS payment_proof VARCHAR(255),
ADD COLUMN IF NOT EXISTS delivery_status ENUM('pending', 'on_the_way', 'delivered') DEFAULT 'pending',
ADD COLUMN IF NOT EXISTS rider_id VARCHAR(50) NULL;

-- Create payments table for detailed payment tracking
CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    payment_id VARCHAR(255) UNIQUE NOT NULL,
    transaction_id VARCHAR(255) NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('cash', 'gcash', 'maya') NOT NULL,
    payment_reference VARCHAR(255),
    payment_status ENUM('pending', 'processing', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    payment_proof VARCHAR(255),
    gcash_number VARCHAR(20),
    maya_number VARCHAR(20),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);
