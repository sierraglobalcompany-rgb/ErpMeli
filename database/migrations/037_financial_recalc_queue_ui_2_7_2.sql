ALTER TABLE order_financial_recalc_jobs
    ADD COLUMN cancelled_at DATETIME NULL AFTER completed_at,
    ADD COLUMN cancelled_by BIGINT UNSIGNED NULL AFTER cancelled_at,
    ADD COLUMN last_error_message VARCHAR(500) NULL AFTER safe_message,
    ADD COLUMN last_processed_at DATETIME NULL AFTER completed_at,
    ADD KEY idx_financial_recalc_status_account_created (status, meli_account_id, created_at),
    ADD KEY idx_financial_recalc_last_processed (last_processed_at),
    ADD CONSTRAINT fk_financial_recalc_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('financial_recalc.batch_limit', '25', 'financial_recalc', 0),
('financial_recalc.assisted_enabled', '1', 'financial_recalc', 0),
('financial_recalc.prevent_duplicate_active_jobs', '1', 'financial_recalc', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.7.2', 'Cola financiera visible, recálculo manual y modo asistido financiero.');
