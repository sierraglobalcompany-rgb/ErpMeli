-- ERP Meli 2.19.3 — inteligencia de carga API.
-- Aditiva e idempotente. No almacena cuerpos de respuestas ni datos comerciales.

ALTER TABLE api_request_logs
    ADD COLUMN IF NOT EXISTS operation_key VARCHAR(80) NULL AFTER source_work_id,
    ADD COLUMN IF NOT EXISTS load_class VARCHAR(24) NULL AFTER operation_key,
    ADD COLUMN IF NOT EXISTS wire_bytes BIGINT UNSIGNED NULL AFTER load_class,
    ADD COLUMN IF NOT EXISTS decoded_bytes BIGINT UNSIGNED NULL AFTER wire_bytes,
    ADD COLUMN IF NOT EXISTS workload_units SMALLINT UNSIGNED NULL AFTER decoded_bytes,
    ADD COLUMN IF NOT EXISTS response_item_count INT UNSIGNED NULL AFTER workload_units,
    ADD COLUMN IF NOT EXISTS fanout_count INT UNSIGNED NULL AFTER response_item_count;

CREATE TABLE IF NOT EXISTS api_operation_metrics_hourly (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket_started_at DATETIME NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    operation_key VARCHAR(80) NOT NULL,
    load_class VARCHAR(24) NOT NULL,
    sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    remote_count INT UNSIGNED NOT NULL DEFAULT 0,
    success_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    total_duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    max_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    total_wire_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_decoded_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_fanout BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_api_operation_hour (bucket_started_at,meli_account_id,operation_key),
    KEY idx_api_operation_time (operation_key,bucket_started_at),
    KEY idx_api_operation_account_time (meli_account_id,bucket_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_operation_canary_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_key VARCHAR(80) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    status ENUM('pending','running','passed','failed','blocked') NOT NULL DEFAULT 'pending',
    sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    safe_summary VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_api_operation_canary (operation_key,status,created_at),
    KEY idx_api_operation_canary_account (meli_account_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_api_operation_key_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='api_request_logs'
      AND index_name='idx_api_request_operation_time'
);
SET @sql_api_operation_key_idx = IF(
    @has_api_operation_key_idx=0,
    'ALTER TABLE api_request_logs ADD KEY idx_api_request_operation_time (operation_key,created_at)',
    'SELECT 1'
);
PREPARE stmt_api_operation_key_idx FROM @sql_api_operation_key_idx;
EXECUTE stmt_api_operation_key_idx;
DEALLOCATE PREPARE stmt_api_operation_key_idx;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('api.workload.enabled','1','api_workload',0),
('api.workload.observation_enabled','1','api_workload',0),
('api.workload.observation_started_at',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:%s'),'api_workload',0),
('api.workload.observation_hours','24','api_workload',0),
('api.workload.minimum_samples','20','api_workload',0),
('api.workload.description_initial_batch','1','api_workload',0),
('api.workload.description_max_batch','3','api_workload',0),
('api.workload.description_pause_seconds','20','api_workload',0),
('api.workload.billing_initial_orders','10','api_workload',0),
('api.workload.billing_max_orders','60','api_workload',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.19.3','Inteligencia de carga API, perfiles por operación y observación segura')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
