CREATE TABLE IF NOT EXISTS staff_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(190) NOT NULL UNIQUE,
    transaction_id VARCHAR(50) NOT NULL,
    rider_id VARCHAR(50) NULL,
    notification_type VARCHAR(40) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_staff_notification_unread (is_read, created_at)
);
