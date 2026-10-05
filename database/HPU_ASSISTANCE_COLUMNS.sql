ALTER TABLE job_requests
    ADD COLUMN hpu_assistance_required TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN hpu_staff_needed INT NULL;
