PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id TEXT UNIQUE NOT NULL,
  full_name TEXT NOT NULL,
  address TEXT NOT NULL,
  username TEXT,
  password TEXT,
  contact_number TEXT NOT NULL,
  email TEXT UNIQUE,
  qr_code_path TEXT,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'denied')),
  loyalty_points INTEGER NOT NULL DEFAULT 0,
  points_year INTEGER NOT NULL DEFAULT (CAST(strftime('%Y', 'now') AS INTEGER)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_code TEXT UNIQUE NOT NULL,
  item_name TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'Container',
  quantity INTEGER NOT NULL DEFAULT 0 CHECK (quantity >= 0),
  minimum_stock INTEGER NOT NULL DEFAULT 10 CHECK (minimum_stock >= 0),
  unit_price REAL NOT NULL DEFAULT 0 CHECK (unit_price >= 0),
  updated_by TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  transaction_id TEXT UNIQUE NOT NULL,
  user_id TEXT NOT NULL,
  amount REAL NOT NULL CHECK (amount >= 0),
  description TEXT,
  water_type TEXT NOT NULL DEFAULT 'regular' CHECK (water_type IN ('regular', 'nowater')),
  quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity > 0),
  price_per_unit REAL,
  discount REAL NOT NULL DEFAULT 0,
  loyalty_points_earned INTEGER NOT NULL DEFAULT 0,
  notes TEXT,
  amount_tendered REAL,
  change_amount REAL,
  container_size TEXT,
  container_status TEXT,
  fulfillment_method TEXT,
  inventory_item_id INTEGER,
  inventory_reserved INTEGER NOT NULL DEFAULT 0 CHECK (inventory_reserved IN (0, 1)),
  cancellation_reason TEXT,
  payment_method TEXT NOT NULL DEFAULT 'cash',
  payment_reference TEXT,
  payment_status TEXT NOT NULL DEFAULT 'pending',
  payment_date TEXT,
  payment_proof TEXT,
  rider_id TEXT,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'denied')),
  delivery_status TEXT NOT NULL DEFAULT 'pending' CHECK (delivery_status IN ('pending', 'assigned', 'preparing', 'on_way', 'delivered', 'cancelled')),
  approved_by TEXT,
  assigned_rider TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id),
  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
);

CREATE TABLE IF NOT EXISTS admin_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id TEXT UNIQUE NOT NULL,
  username TEXT UNIQUE NOT NULL,
  password TEXT NOT NULL,
  full_name TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'staff' CHECK (role IN ('admin', 'staff')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS activity_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id TEXT,
  action TEXT NOT NULL,
  description TEXT,
  timestamp TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rider_users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  rider_id TEXT UNIQUE NOT NULL,
  username TEXT UNIQUE NOT NULL,
  password TEXT NOT NULL,
  full_name TEXT NOT NULL,
  age INTEGER,
  address TEXT,
  contact_number TEXT,
  status TEXT NOT NULL DEFAULT 'active',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rider_locations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  transaction_id TEXT NOT NULL,
  rider_id TEXT,
  rider_latitude REAL NOT NULL DEFAULT 12.8797,
  rider_longitude REAL NOT NULL DEFAULT 121.7740,
  accuracy REAL,
  speed REAL,
  heading REAL,
  last_update TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id)
);

CREATE TABLE IF NOT EXISTS rider_notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  rider_id TEXT NOT NULL,
  transaction_id TEXT NOT NULL,
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  is_read INTEGER NOT NULL DEFAULT 0 CHECK (is_read IN (0, 1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rider_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  transaction_id TEXT NOT NULL,
  sender TEXT NOT NULL,
  recipient TEXT NOT NULL,
  message TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  payment_id TEXT UNIQUE NOT NULL,
  transaction_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  amount REAL NOT NULL,
  payment_method TEXT NOT NULL,
  payment_reference TEXT,
  payment_status TEXT NOT NULL DEFAULT 'pending',
  payment_proof TEXT,
  gcash_number TEXT,
  maya_number TEXT,
  notes TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS feedback_ratings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  transaction_id TEXT NOT NULL,
  user_id TEXT NOT NULL,
  rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
  feedback_message TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (transaction_id, user_id),
  FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id),
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);

CREATE TABLE IF NOT EXISTS reward_claims (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  transaction_id TEXT UNIQUE NOT NULL,
  user_id TEXT NOT NULL,
  reward_code TEXT NOT NULL,
  reward_title TEXT NOT NULL,
  points_used INTEGER NOT NULL,
  claim_status TEXT NOT NULL DEFAULT 'pending',
  claimed_by TEXT,
  claimed_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL,
  movement_type TEXT NOT NULL,
  quantity_change INTEGER NOT NULL,
  previous_quantity INTEGER NOT NULL,
  new_quantity INTEGER NOT NULL,
  reason TEXT,
  staff_id TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES inventory_items(id)
);

CREATE TABLE IF NOT EXISTS user_notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id TEXT NOT NULL,
  transaction_id TEXT,
  title TEXT NOT NULL,
  message TEXT NOT NULL,
  notification_type TEXT NOT NULL DEFAULT 'info',
  is_read INTEGER NOT NULL DEFAULT 0 CHECK (is_read IN (0, 1)),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);

CREATE INDEX IF NOT EXISTS idx_transactions_user ON transactions(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_transactions_delivery ON transactions(delivery_status, created_at);
CREATE INDEX IF NOT EXISTS idx_rider_location_transaction ON rider_locations(transaction_id, last_update);
CREATE INDEX IF NOT EXISTS idx_rider_location_rider ON rider_locations(rider_id);
CREATE INDEX IF NOT EXISTS idx_rider_notification ON rider_notifications(rider_id, is_read);
CREATE INDEX IF NOT EXISTS idx_rider_message_transaction ON rider_messages(transaction_id, created_at);
CREATE INDEX IF NOT EXISTS idx_reward_claim_status ON reward_claims(claim_status);
CREATE INDEX IF NOT EXISTS idx_reward_claim_user ON reward_claims(user_id);
CREATE INDEX IF NOT EXISTS idx_inventory_item ON inventory_movements(item_id);
CREATE INDEX IF NOT EXISTS idx_inventory_created ON inventory_movements(created_at);
CREATE INDEX IF NOT EXISTS idx_user_notification ON user_notifications(user_id, is_read, created_at);

CREATE TRIGGER IF NOT EXISTS users_touch_updated_at
AFTER UPDATE ON users FOR EACH ROW
BEGIN
  UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS transactions_touch_updated_at
AFTER UPDATE ON transactions FOR EACH ROW
BEGIN
  UPDATE transactions SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS rider_users_touch_updated_at
AFTER UPDATE ON rider_users FOR EACH ROW
BEGIN
  UPDATE rider_users SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS inventory_items_touch_updated_at
AFTER UPDATE ON inventory_items FOR EACH ROW
BEGIN
  UPDATE inventory_items SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS payments_touch_updated_at
AFTER UPDATE ON payments FOR EACH ROW
BEGIN
  UPDATE payments SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

INSERT OR IGNORE INTO users (user_id, full_name, address, contact_number, email, status) VALUES
  ('USR-001', 'Juan Dela Cruz', '123 Main Street, Manila', '09171234567', 'juan@example.com', 'approved'),
  ('USR-002', 'Maria Santos', '456 Oak Avenue, Quezon City', '09175678901', 'maria@example.com', 'pending'),
  ('USR-003', 'Pedro Reyes', '789 Pine Road, Makati', '09179876543', 'pedro@example.com', 'approved'),
  ('USR-004', 'Ana Lopez', '321 Elm Street, Cavite', '09172234890', 'ana@example.com', 'denied');

INSERT OR IGNORE INTO transactions (transaction_id, user_id, amount, description, status, approved_by) VALUES
  ('TRX-001', 'USR-001', 1500, 'Water Service Bill', 'approved', 'STF-001'),
  ('TRX-002', 'USR-002', 2000, 'Monthly Service Fee', 'pending', NULL),
  ('TRX-003', 'USR-003', 1200, 'Installation Fee', 'approved', 'STF-001'),
  ('TRX-004', 'USR-004', 3000, 'Service Upgrade', 'denied', 'STF-001'),
  ('TRX-005', 'USR-001', 1500, 'Monthly Service Fee', 'pending', NULL);
