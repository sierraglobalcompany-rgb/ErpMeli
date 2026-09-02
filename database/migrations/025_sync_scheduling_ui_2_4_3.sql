ALTER TABLE sync_batch_chunks
    ADD KEY idx_sync_chunks_schedule_2_4_3 (status, next_run_at, meli_account_id);

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('sync.default_enqueue_delay_minutes', '5', 'sync', 0),
('sync.allow_custom_schedule', '1', 'sync', 0),
('sync.manual_overlay_enabled', '1', 'sync', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.4.3', 'Programación flexible de sincronizaciones y preloader manual');
