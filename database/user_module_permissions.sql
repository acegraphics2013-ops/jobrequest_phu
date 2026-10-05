-- HPU Workbench - Per-user module visibility overrides
-- Default behavior: empty = inherit role defaults (visible by role, backward compatible).
-- Override row: is_allowed=1 grant (show even if role denies), is_allowed=0 revoke (hide even if role allows).

CREATE TABLE IF NOT EXISTS user_module_permissions (
    user_id INT NOT NULL,
    module_key VARCHAR(60) NOT NULL,
    is_allowed TINYINT(1) NOT NULL,
    updated_by INT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, module_key),
    KEY idx_ump_user (user_id),
    KEY idx_ump_module (module_key),
    CONSTRAINT fk_ump_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
