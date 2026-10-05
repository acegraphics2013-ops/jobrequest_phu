-- Run this SQL once before opening client_experience_survey.php.

CREATE TABLE IF NOT EXISTS job_request_client_experience (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id INT NOT NULL,
    user_id INT NOT NULL,
    age TINYINT UNSIGNED NOT NULL,
    sex VARCHAR(30) NOT NULL,
    date_of_visit DATE NOT NULL,
    time_of_visit TIME NULL,
    department_visited VARCHAR(255) NOT NULL,
    service_types TEXT NOT NULL,
    service_other VARCHAR(255) NULL,
    timeliness TINYINT UNSIGNED NOT NULL,
    responsiveness TINYINT UNSIGNED NOT NULL,
    quality_output TINYINT UNSIGNED NOT NULL,
    professionalism TINYINT UNSIGNED NOT NULL,
    communication TINYINT UNSIGNED NOT NULL,
    service_met_needs TINYINT UNSIGNED NOT NULL,
    overall_satisfaction TINYINT UNSIGNED NOT NULL,
    outcome_assessment VARCHAR(20) NOT NULL,
    phu_did_well TEXT NULL,
    phu_improve TEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_client_experience_request (request_id),
    KEY idx_client_experience_user (user_id),
    KEY idx_client_experience_submitted (submitted_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
