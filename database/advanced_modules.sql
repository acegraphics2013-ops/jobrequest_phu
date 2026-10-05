/*
|--------------------------------------------------------------------------
| HPU ADVANCED MODULES DATABASE UPDATE
|--------------------------------------------------------------------------
| Import this once in phpMyAdmin.
*/

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
    CONSTRAINT fk_activity_request
        FOREIGN KEY (request_id) REFERENCES job_requests(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_activity_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS hpu_add_column_if_missing;

DELIMITER $$

CREATE PROCEDURE hpu_add_column_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_column_name VARCHAR(64),
    IN p_column_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = p_column_name
    ) THEN
        SET @sql_text = CONCAT(
            'ALTER TABLE `',
            p_table_name,
            '` ADD COLUMN `',
            p_column_name,
            '` ',
            p_column_definition
        );

        PREPARE stmt FROM @sql_text;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL hpu_add_column_if_missing(
    'job_requests',
    'priority',
    "ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal'"
);

CALL hpu_add_column_if_missing(
    'job_requests',
    'assigned_to',
    'INT NULL'
);

CALL hpu_add_column_if_missing(
    'job_requests',
    'assigned_at',
    'DATETIME NULL'
);

CALL hpu_add_column_if_missing(
    'job_requests',
    'public_token',
    'CHAR(64) NULL'
);

CALL hpu_add_column_if_missing(
    'job_requests',
    'archived',
    'TINYINT(1) NOT NULL DEFAULT 0'
);

CALL hpu_add_column_if_missing(
    'job_requests',
    'archived_at',
    'DATETIME NULL'
);

CALL hpu_add_column_if_missing(
    'job_requests',
    'archived_by',
    'INT NULL'
);

DROP PROCEDURE IF EXISTS hpu_add_column_if_missing;

/*
|--------------------------------------------------------------------------
| Add indexes only when missing
|--------------------------------------------------------------------------
*/

DROP PROCEDURE IF EXISTS hpu_add_index_if_missing;

DELIMITER $$

CREATE PROCEDURE hpu_add_index_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_index_name VARCHAR(64),
    IN p_index_sql TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND INDEX_NAME = p_index_name
    ) THEN
        SET @sql_text = p_index_sql;
        PREPARE stmt FROM @sql_text;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

CALL hpu_add_index_if_missing(
    'job_requests',
    'idx_job_public_token',
    'CREATE UNIQUE INDEX idx_job_public_token ON job_requests(public_token)'
);

CALL hpu_add_index_if_missing(
    'job_requests',
    'idx_job_archived',
    'CREATE INDEX idx_job_archived ON job_requests(archived)'
);

CALL hpu_add_index_if_missing(
    'job_requests',
    'idx_job_assigned_to',
    'CREATE INDEX idx_job_assigned_to ON job_requests(assigned_to)'
);

DROP PROCEDURE IF EXISTS hpu_add_index_if_missing;
