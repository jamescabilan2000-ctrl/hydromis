-- HydroMIS schema for Supabase PostgreSQL
-- Run this in Supabase SQL Editor.

-- Enum types
DO $$ BEGIN
    CREATE TYPE user_status AS ENUM ('pending', 'approved', 'denied');
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

DO $$ BEGIN
    CREATE TYPE water_type AS ENUM ('regular', 'nowater');
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

DO $$ BEGIN
    CREATE TYPE delivery_status AS ENUM ('pending', 'preparing', 'on_way', 'delivered');
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

DO $$ BEGIN
    CREATE TYPE admin_role AS ENUM ('admin', 'staff');
EXCEPTION
    WHEN duplicate_object THEN null;
END $$;

-- Tables
CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    user_id VARCHAR(255) UNIQUE NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    address TEXT NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    email VARCHAR(255) UNIQUE,
    qr_code_path VARCHAR(255),
    status user_status DEFAULT 'pending',
    loyalty_points INT DEFAULT 0,
    points_year INT DEFAULT EXTRACT(YEAR FROM CURRENT_DATE),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS transactions (
    id BIGSERIAL PRIMARY KEY,
    transaction_id VARCHAR(255) UNIQUE NOT NULL,
    user_id VARCHAR(255) NOT NULL,
    amount NUMERIC(10, 2) NOT NULL,
    description TEXT,
    water_type water_type DEFAULT 'regular',
    quantity INT DEFAULT 1,
    price_per_unit NUMERIC(10, 2),
    discount NUMERIC(10, 2) DEFAULT 0,
    loyalty_points_earned INT DEFAULT 0,
    notes TEXT,
    status user_status DEFAULT 'pending',
    delivery_status delivery_status DEFAULT 'pending',
    approved_by VARCHAR(255),
    assigned_rider VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

CREATE TABLE IF NOT EXISTS admin_users (
    id BIGSERIAL PRIMARY KEY,
    admin_id VARCHAR(255) UNIQUE NOT NULL,
    username VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    role admin_role DEFAULT 'staff',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id BIGSERIAL PRIMARY KEY,
    admin_id VARCHAR(255),
    action VARCHAR(255) NOT NULL,
    description TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Keep updated_at in sync
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS users_updated_at ON users;
CREATE TRIGGER users_updated_at
BEFORE UPDATE ON users
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

DROP TRIGGER IF EXISTS transactions_updated_at ON transactions;
CREATE TRIGGER transactions_updated_at
BEFORE UPDATE ON transactions
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

-- Seed data
INSERT INTO admin_users (admin_id, username, password, full_name, role)
VALUES
('ADM-001', 'admin', '$2y$10$N9qo8uLOickgx2ZMRZoHyOzyT1FBWzLUruPCiLYvPBa9cdVbNUNl2', 'John Admin', 'admin'),
('STF-001', 'staff1', '$2y$10$N9qo8uLOickgx2ZMRZoHyOzyT1FBWzLUruPCiLYvPBa9cdVbNUNl2', 'Sarah Staff', 'staff')
ON CONFLICT (username) DO NOTHING;

INSERT INTO users (user_id, full_name, address, contact_number, email, status)
VALUES
('USR-001', 'Juan Dela Cruz', '123 Main Street, Manila', '09171234567', 'juan@example.com', 'approved'),
('USR-002', 'Maria Santos', '456 Oak Avenue, Quezon City', '09175678901', 'maria@example.com', 'pending'),
('USR-003', 'Pedro Reyes', '789 Pine Road, Makati', '09179876543', 'pedro@example.com', 'approved'),
('USR-004', 'Ana Lopez', '321 Elm Street, Cavite', '09172234890', 'ana@example.com', 'denied')
ON CONFLICT (user_id) DO NOTHING;

INSERT INTO transactions (transaction_id, user_id, amount, description, status, approved_by)
VALUES
('TRX-001', 'USR-001', 1500.00, 'Water Service Bill', 'approved', 'STF-001'),
('TRX-002', 'USR-002', 2000.00, 'Monthly Service Fee', 'pending', NULL),
('TRX-003', 'USR-003', 1200.00, 'Installation Fee', 'approved', 'STF-001'),
('TRX-004', 'USR-004', 3000.00, 'Service Upgrade', 'denied', 'STF-001'),
('TRX-005', 'USR-001', 1500.00, 'Monthly Service Fee', 'pending', NULL)
ON CONFLICT (transaction_id) DO NOTHING;
