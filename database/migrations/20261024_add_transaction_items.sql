CREATE TABLE IF NOT EXISTS transaction_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id VARCHAR(255) NOT NULL,
    container_size VARCHAR(30) NOT NULL,
    container_status VARCHAR(20) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transaction_items_transaction (transaction_id),
    CONSTRAINT fk_transaction_items_transaction FOREIGN KEY (transaction_id)
        REFERENCES transactions(transaction_id) ON DELETE CASCADE
);
