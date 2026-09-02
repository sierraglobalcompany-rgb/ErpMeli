ALTER TABLE meli_payments
    ADD COLUMN detail_status ENUM('summary','expanded','unavailable','error') NOT NULL DEFAULT 'summary' AFTER synced_at,
    ADD COLUMN detail_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER detail_status,
    ADD COLUMN detail_last_attempt_at DATETIME NULL AFTER detail_attempts,
    ADD COLUMN detail_unavailable_at DATETIME NULL AFTER detail_last_attempt_at,
    ADD COLUMN detail_error_code VARCHAR(120) NULL AFTER detail_unavailable_at,
    ADD COLUMN detail_error_message VARCHAR(500) NULL AFTER detail_error_code,
    ADD KEY idx_payments_detail_status (meli_account_id, detail_status, detail_last_attempt_at);
