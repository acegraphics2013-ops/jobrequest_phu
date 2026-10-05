CREATE DATABASE IF NOT EXISTS hpu_job_request CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hpu_job_request;

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(150) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO departments (department_name) VALUES
('Administrative Office'),
('Accounting'),
('Human Resource'),
('Health Promotion Unit'),
('Information Technology'),
('Medical Service'),
('Nursing Service');

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','staff','supervisor','user') NOT NULL DEFAULT 'user',
    department VARCHAR(150) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default password for all demo accounts: password
INSERT IGNORE INTO users (name, email, password, role, department) VALUES
('System Administrator', 'admin@hpu.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'admin', 'Health Promotion Unit'),
('HPU Staff', 'staff@hpu.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'staff', 'Health Promotion Unit'),
('HPU Supervisor', 'supervisor@hpu.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'supervisor', 'Health Promotion Unit'),
('Sample Requestor', 'user@hpu.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'user', 'Medical Service');

CREATE TABLE IF NOT EXISTS job_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_no VARCHAR(50) NOT NULL UNIQUE,
    requestor INT NOT NULL,
    requestor_name VARCHAR(150) NOT NULL,
    department VARCHAR(150) NOT NULL,
    contact VARCHAR(50) NOT NULL,
    date_request DATE NOT NULL,
    date_completion DATE NULL,
    attachment VARCHAR(255) NULL,

    request_types LONGTEXT NULL,
    project_title VARCHAR(255) NOT NULL,
    purpose_objective TEXT NOT NULL,
    event_datetime DATETIME NULL,
    event_datetime_end DATETIME NULL,
    event_not_applicable TINYINT(1) NOT NULL DEFAULT 0,
    venue_location VARCHAR(255) NULL,
    venue_not_applicable TINYINT(1) NOT NULL DEFAULT 0,
    send_to VARCHAR(255) NULL,
    send_to_not_applicable TINYINT(1) NOT NULL DEFAULT 0,

    graphic_design_types LONGTEXT NULL,
    graphic_design_other VARCHAR(255) NULL,
    graphic_colors LONGTEXT NULL,
    publication_methods LONGTEXT NULL,
    publication_other VARCHAR(255) NULL,
    graphic_quantity INT NOT NULL DEFAULT 0,

    video_types LONGTEXT NULL,
    video_type_other VARCHAR(255) NULL,
    video_formats LONGTEXT NULL,
    video_format_other VARCHAR(255) NULL,
    video_quantity INT NOT NULL DEFAULT 0,

    photographers_needed INT NOT NULL DEFAULT 0,
    specific_shots TEXT NULL,
    coverage_formats LONGTEXT NULL,
    coverage_format_other VARCHAR(255) NULL,
    coverage_quantity INT NOT NULL DEFAULT 0,

    request_details TEXT NULL,
    requestor_signature_date DATE NULL,

    reviewed_by VARCHAR(150) NULL,
    reviewed_date DATE NULL,
    disapproved TINYINT(1) NOT NULL DEFAULT 0,
    disapproved_reason TEXT NULL,
    approved_by VARCHAR(255) NULL,
    approved_date DATE NULL,

    initial_draft_date DATE NULL,
    final_approval_date DATE NULL,
    revision_required TINYINT(1) NULL,
    revision_count INT NOT NULL DEFAULT 0,
    distribution_mode VARCHAR(255) NULL,

    delivered_on_time TINYINT(1) NULL,
    objectives_met TINYINT(1) NULL,
    challenges_encountered TINYINT(1) NULL,
    challenges_details TEXT NULL,
    improvement_suggestions TEXT NULL,
    satisfaction_rating TINYINT NULL,

    approval_status VARCHAR(50) NOT NULL DEFAULT 'Pending Review',
    action_log LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_requestor (requestor),
    INDEX idx_status (approval_status),
    CONSTRAINT fk_job_request_user
        FOREIGN KEY (requestor) REFERENCES users(id)
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS job_request_iec_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    material_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_iec_request (request_id),
    CONSTRAINT fk_iec_request
        FOREIGN KEY (request_id) REFERENCES job_requests(id)
        ON DELETE CASCADE
);
