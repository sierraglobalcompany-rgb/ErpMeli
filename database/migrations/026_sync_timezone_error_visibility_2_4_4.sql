ALTER TABLE sync_batch_chunks
    ADD COLUMN error_type VARCHAR(80) NULL AFTER blocked_reason,
    ADD COLUMN error_http_status INT NULL AFTER error_type,
    ADD COLUMN error_endpoint VARCHAR(255) NULL AFTER error_http_status,
    ADD COLUMN error_recommendation VARCHAR(500) NULL AFTER error_endpoint,
    ADD KEY idx_sync_chunks_overdue_2_4_4 (sync_type, status, next_run_at, meli_account_id),
    ADD KEY idx_sync_chunks_errors_2_4_4 (meli_account_id, status, error_type, updated_at);

ALTER TABLE sync_chunk_runs
    ADD KEY idx_sync_chunk_runs_status_2_4_4 (meli_account_id, status, started_at);

ALTER TABLE cron_health_checks
    ADD KEY idx_cron_health_finished_2_4_4 (job_name, finished_at);

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('sync.assisted_mode_enabled', '1', 'sync', 0),
('sync.time_diagnostics_enabled', '1', 'sync', 0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.4.4', 'Auditoría de cron, timezone UTC y errores visibles en sincronizaciones');
