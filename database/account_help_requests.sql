-- =============================================================
-- HPU Workbench - Account Help Requests
-- Tracked account-support tickets submitted from the login page
-- ("Need account help?") instead of an untracked email.
--
-- INSTALLATION
-- 1. Back up your project and database.
-- 2. Import this file into the hpu_job_request database, OR
--    let request_help.php / staff/account_requests.php
--    auto-create the table on first use (same schema).
--
-- FLOW
-- 1. Visitor submits the Account Help form on index.php
--    (handled by request_help.php, no login required).
--    Issue types: forgot_password, inactive, correction, other.
-- 2. The ticket is stored with status 'Pending'.
-- 3. Admins, supervisors and HPU staff resolve it on
--    staff/account_requests.php (Help tab):
--      temporary password resets -> admin / supervisor only
--      reactivation, corrections, other -> any of the three roles
-- 4. No SMTP is required: temporary passwords are relayed
--    out-of-band (in person / phone) and recorded in the
--    reviewer notes for audit.
-- =============================================================

CREATE TABLE IF NOT EXISTS account_help_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    contact VARCHAR(50) NULL,
    issue_type ENUM('forgot_password','inactive','correction','other') NOT NULL DEFAULT 'other',
    details TEXT NULL,
    status ENUM('Pending','Resolved','Declined') NOT NULL DEFAULT 'Pending',
    reviewed_by INT NULL,
    reviewed_by_name VARCHAR(150) NULL,
    review_notes TEXT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_help_status (status),
    INDEX idx_help_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
