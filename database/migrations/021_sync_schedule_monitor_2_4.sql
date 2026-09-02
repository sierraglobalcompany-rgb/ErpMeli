ALTER TABLE sync_batch_chunks
    ADD COLUMN priority TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER sequence_no,
    ADD COLUMN blocked_reason VARCHAR(255) NULL AFTER last_error,
    ADD KEY idx_sync_chunks_schedule (status, next_run_at, priority, meli_account_id);

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('sync.schedule_preview_limit', '3', 'sync', 0),
('sync.process_now_json_enabled', '1', 'sync', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);
