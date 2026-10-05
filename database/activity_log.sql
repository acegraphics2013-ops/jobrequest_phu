CREATE TABLE IF NOT EXISTS request_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    user_id INT NULL,
    action_type VARCHAR(100) NOT NULL,
    action_title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    old_status VARCHAR(100) NULL,
    new_status VARCHAR(100) NULL,
    metadata_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_request (request_id),
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_created (created_at),
    CONSTRAINT fk_activity_request
        FOREIGN KEY (request_id) REFERENCES job_requests(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_activity_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
