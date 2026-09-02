ALTER TABLE companies
    ADD COLUMN city VARCHAR(120) NULL AFTER address,
    ADD COLUMN department VARCHAR(120) NULL AFTER city,
    ADD COLUMN person_type ENUM('natural','juridica') NOT NULL DEFAULT 'juridica' AFTER department,
    ADD COLUMN deactivated_at DATETIME NULL AFTER status,
    ADD COLUMN deleted_at DATETIME NULL AFTER deactivated_at,
    ADD KEY idx_companies_deleted_status (deleted_at, status),
    ADD KEY idx_companies_person_type (person_type);
