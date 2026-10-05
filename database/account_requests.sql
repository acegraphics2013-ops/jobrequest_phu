-- =============================================================
-- HPU Workbench - Account Requests
-- Public account requests approved by HPU personnel
-- (admin, supervisor and staff).
--
-- INSTALLATION
-- 1. Back up your project and database.
-- 2. Import this file into the hpu_job_request database, OR
--    let request_account.php / staff/account_requests.php
--    auto-create the table on first use (same schema).
--
-- FLOW
-- 1. Visitor submits the Request Account form on index.php
--    (handled by request_account.php, no login required).
-- 2. The request is stored with status 'Pending'.
-- 3. Admins, supervisors and HPU staff review it on
--    staff/account_requests.php:
--      staff      -> may approve 'user' requests only
--      supervisor -> may approve 'user' and 'staff' requests
--      admin      -> may approve any request
-- 4. On approval a real row in `users` is created and the
--    requestor can sign in immediately with the password
--    chosen in the request form.
-- =============================================================

CREATE TABLE IF NOT EXISTS account_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    contact VARCHAR(50) NULL,
    department VARCHAR(150) NOT NULL,
    position VARCHAR(150) NULL,
    requested_role ENUM('user','staff','supervisor') NOT NULL DEFAULT 'user',
    reason TEXT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('Pending','Approved','Declined') NOT NULL DEFAULT 'Pending',
    reviewed_by INT NULL,
    reviewed_by_name VARCHAR(150) NULL,
    review_notes TEXT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_account_status (status),
    INDEX idx_account_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
