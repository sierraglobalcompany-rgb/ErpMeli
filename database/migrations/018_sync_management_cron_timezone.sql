CREATE TABLE IF NOT EXISTS cron_health_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_name VARCHAR(120) NOT NULL,
    status ENUM('running','success','error') NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    duration_ms INT UNSIGNED NULL,
    processed_chunks INT UNSIGNED NOT NULL DEFAULT 0,
    completed_chunks INT UNSIGNED NOT NULL DEFAULT 0,
    partial_chunks INT UNSIGNED NOT NULL DEFAULT 0,
    error_chunks INT UNSIGNED NOT NULL DEFAULT 0,
    orders_count INT UNSIGNED NOT NULL DEFAULT 0,
    message VARCHAR(500) NULL,
    payload_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cron_health_job_started (job_name, started_at),
    KEY idx_cron_health_status (job_name, status, finished_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sync_batch_chunks
    ADD KEY idx_sync_chunks_batch_status (sync_batch_id, status),
    ADD KEY idx_sync_chunks_running (status, started_at);

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('app.timezone', 'America/Bogota', 'general', 0),
('sync.monitor_refresh_seconds', '12', 'sync', 0),
('sync.manual_process_enabled', '1', 'sync', 0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.3.1', 'Gestión de sincronizaciones, salud de cron, timezone, filtros de pagos y reclamos');
