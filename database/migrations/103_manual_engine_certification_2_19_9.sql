-- ERP Meli 2.19.9 — certificación del motor manual y adaptadores exactos.
-- Aditiva e idempotente. No consulta ni modifica Mercado Libre.

CREATE TABLE IF NOT EXISTS manual_engine_probe_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_token CHAR(40) NOT NULL,
    status ENUM('running','passed','failed','interrupted') NOT NULL DEFAULT 'running',
    php_version VARCHAR(30) NOT NULL,
    sapi VARCHAR(30) NOT NULL,
    requested_window_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    observed_window_ms INT UNSIGNED NOT NULL DEFAULT 0,
    sleep_drift_ms INT NOT NULL DEFAULT 0,
    mysql_latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
    storage_ok TINYINT(1) NOT NULL DEFAULT 0,
    lock_ok TINYINT(1) NOT NULL DEFAULT 0,
    overlap_blocked TINYINT(1) NOT NULL DEFAULT 0,
    checkpoint_ok TINYINT(1) NOT NULL DEFAULT 0,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    heartbeat_at DATETIME NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_manual_probe_token (run_token),
    KEY idx_manual_probe_status (status,completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_campaign_adapter_health (
    adapter_key VARCHAR(80) NOT NULL PRIMARY KEY,
    queue_key VARCHAR(80) NOT NULL,
    exact_supported TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('ready','degraded','unsupported','failed') NOT NULL DEFAULT 'unsupported',
    safe_message VARCHAR(500) NULL,
    last_checked_at DATETIME NULL,
    last_success_at DATETIME NULL,
    diagnostic_id VARCHAR(80) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_manual_adapter_queue (queue_key,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_operation_profiles (
    operation_key VARCHAR(80) NOT NULL PRIMARY KEY,
    profile_version VARCHAR(30) NOT NULL,
    human_label VARCHAR(160) NOT NULL,
    endpoint_key VARCHAR(160) NULL,
    uses_api TINYINT(1) NOT NULL DEFAULT 0,
    items_per_call SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    minimum_interval_ms INT UNSIGNED NOT NULL DEFAULT 0,
    recommended_interval_ms INT UNSIGNED NOT NULL DEFAULT 0,
    recommended_block_size SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    maximum_block_size SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    block_pause_ms INT UNSIGNED NOT NULL DEFAULT 0,
    load_class VARCHAR(30) NOT NULL DEFAULT 'local',
    fanout_possible TINYINT(1) NOT NULL DEFAULT 0,
    source_reference VARCHAR(255) NULL,
    verified_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE manual_processing_engine_health
    ADD COLUMN IF NOT EXISTS certification_status VARCHAR(40) NULL AFTER last_check_message,
    ADD COLUMN IF NOT EXISTS certified_at DATETIME NULL AFTER certification_status,
    ADD COLUMN IF NOT EXISTS observed_interval_seconds SMALLINT UNSIGNED NULL AFTER certified_at,
    ADD COLUMN IF NOT EXISTS stable_window_seconds SMALLINT UNSIGNED NULL AFTER observed_interval_seconds,
    ADD COLUMN IF NOT EXISTS probe_success_streak TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER stable_window_seconds,
    ADD COLUMN IF NOT EXISTS last_probe_run_id BIGINT UNSIGNED NULL AFTER probe_success_streak;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.engine_enabled','0','manual_campaign',0),
('manual_campaign.required_probe_streak','3','manual_campaign',0),
('manual_campaign.worker_runtime_seconds','54','manual_campaign',0),
('manual_campaign.accept_work_until_seconds','50','manual_campaign',0),
('manual_campaign.poll_precision_ms','250','manual_campaign',0),
('manual_campaign.max_remote_concurrency','1','manual_campaign',0),
('manual_campaign.require_exact_adapter','1','manual_campaign',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.19.9','Certificación del motor manual, temporización persistente y adaptadores exactos')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
