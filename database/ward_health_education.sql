-- HPU Workbench - Ward Health Education (Ward Class) tables.
-- Import once for fresh installs; the page also self-heals via
-- CREATE TABLE IF NOT EXISTS so upgraded installs converge.

CREATE TABLE IF NOT EXISTS ward_health_education_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submitted_by INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    session_date DATE NOT NULL,
    activity_time VARCHAR(120) NULL,
    ward_area VARCHAR(190) NOT NULL,
    lead_office VARCHAR(190) NOT NULL,
    health_topic VARCHAR(255) NOT NULL,
    facilitator VARCHAR(255) NOT NULL,
    target_participants VARCHAR(190) NULL,
    participant_count INT NOT NULL DEFAULT 0,
    teaching_method VARCHAR(190) NULL,
    objectives LONGTEXT NOT NULL,
    key_outputs LONGTEXT NULL,
    impact_significance LONGTEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'For Review',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_whe_date(session_date),
    INDEX idx_whe_status(status),
    INDEX idx_whe_ward(ward_area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ward_health_education_outputs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    output_type VARCHAR(120) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    extension VARCHAR(20) NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_whe_output_session(session_id),
    CONSTRAINT fk_whe_output_session FOREIGN KEY(session_id)
        REFERENCES ward_health_education_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
