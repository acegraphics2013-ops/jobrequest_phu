ALTER TABLE job_requests
ADD COLUMN final_design_drive_link TEXT NULL
AFTER distribution_mode;
