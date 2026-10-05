CREATE TABLE IF NOT EXISTS request_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    sender_id INT NOT NULL,
    sender_name VARCHAR(150) NOT NULL,
    sender_role VARCHAR(30) NOT NULL DEFAULT 'user',
    message TEXT NULL,
    attachment VARCHAR(255) NULL,
    attachment_original_name VARCHAR(255) NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    reply_to BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request_messages_request (request_id, id),
    INDEX idx_request_messages_sender (sender_id),
    CONSTRAINT fk_request_messages_reply
        FOREIGN KEY (reply_to) REFERENCES request_messages(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_message_reads (
    request_id INT NOT NULL,
    user_id INT NOT NULL,
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id, user_id),
    INDEX idx_request_message_reads_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
