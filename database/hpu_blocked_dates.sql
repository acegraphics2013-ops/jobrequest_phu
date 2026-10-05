CREATE TABLE IF NOT EXISTS hpu_blocked_dates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    service_type VARCHAR(80) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    blocked_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hpu_blocked_range (start_date, end_date),
    INDEX idx_hpu_blocked_service (service_type),
    INDEX idx_hpu_blocked_by (blocked_by)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
