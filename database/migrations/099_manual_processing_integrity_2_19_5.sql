-- ERP Meli 2.19.5 — cierre de integridad del procesamiento manual.
-- Aditiva e idempotente. No modifica datos comerciales ni realiza llamadas remotas.

ALTER TABLE manual_processing_sessions
    MODIFY COLUMN status ENUM(
        'draft','active','paused','finishing','completed',
        'completed_with_issues','abandoned','failed'
    ) NOT NULL DEFAULT 'draft',
    ADD COLUMN IF NOT EXISTS worker_heartbeat_at DATETIME NULL AFTER heartbeat_at,
    ADD COLUMN IF NOT EXISTS last_worker_result VARCHAR(40) NULL AFTER worker_heartbeat_at,
    ADD COLUMN IF NOT EXISTS last_worker_message VARCHAR(500) NULL AFTER last_worker_result;

ALTER TABLE manual_processing_items
    ADD COLUMN IF NOT EXISTS next_eligible_at DATETIME NULL AFTER lease_expires_at;

CREATE TABLE IF NOT EXISTS api_operation_metric_samples (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket_started_at DATETIME NOT NULL,
    account_scope_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
    meli_account_id BIGINT UNSIGNED NULL,
    operation_key VARCHAR(80) NOT NULL,
    load_class VARCHAR(24) NOT NULL,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    wire_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    decoded_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    response_item_count INT UNSIGNED NOT NULL DEFAULT 0,
    fanout_count INT UNSIGNED NOT NULL DEFAULT 0,
    http_status SMALLINT UNSIGNED NULL,
    reached_remote TINYINT(1) NOT NULL DEFAULT 0,
    successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_api_metric_sample_operation (operation_key,created_at),
    KEY idx_api_metric_sample_account (account_scope_key,operation_key,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE api_operation_metrics_hourly
    ADD COLUMN IF NOT EXISTS account_scope_key BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER bucket_started_at;

UPDATE api_operation_metrics_hourly
SET account_scope_key=COALESCE(meli_account_id,0)
WHERE account_scope_key=0 AND meli_account_id IS NOT NULL;

-- MariaDB permite múltiples NULL en el índice anterior. Consolidar únicamente
-- telemetría agregada antes de crear la clave determinista por cuenta.
DROP TEMPORARY TABLE IF EXISTS tmp_api_operation_metrics_2195;
CREATE TEMPORARY TABLE tmp_api_operation_metrics_2195 AS
SELECT
    bucket_started_at,
    COALESCE(meli_account_id,0) account_scope_key,
    MAX(meli_account_id) meli_account_id,
    operation_key,
    MAX(load_class) load_class,
    SUM(sample_count) sample_count,
    SUM(remote_count) remote_count,
    SUM(success_count) success_count,
    SUM(error_count) error_count,
    SUM(total_duration_ms) total_duration_ms,
    MAX(max_duration_ms) max_duration_ms,
    SUM(total_wire_bytes) total_wire_bytes,
    SUM(total_decoded_bytes) total_decoded_bytes,
    SUM(total_items) total_items,
    SUM(total_fanout) total_fanout
FROM api_operation_metrics_hourly
GROUP BY bucket_started_at,COALESCE(meli_account_id,0),operation_key;

DELETE FROM api_operation_metrics_hourly;

INSERT INTO api_operation_metrics_hourly
(bucket_started_at,account_scope_key,meli_account_id,operation_key,load_class,sample_count,remote_count,
 success_count,error_count,total_duration_ms,max_duration_ms,total_wire_bytes,total_decoded_bytes,
 total_items,total_fanout,updated_at)
SELECT bucket_started_at,account_scope_key,meli_account_id,operation_key,load_class,sample_count,remote_count,
       success_count,error_count,total_duration_ms,max_duration_ms,total_wire_bytes,total_decoded_bytes,
       total_items,total_fanout,UTC_TIMESTAMP()
FROM tmp_api_operation_metrics_2195;

DROP TEMPORARY TABLE tmp_api_operation_metrics_2195;

SET @has_old_metric_unique = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='api_operation_metrics_hourly'
      AND index_name='uq_api_operation_hour'
);
SET @drop_old_metric_unique = IF(
    @has_old_metric_unique>0,
    'ALTER TABLE api_operation_metrics_hourly DROP INDEX uq_api_operation_hour',
    'SELECT 1'
);
PREPARE stmt_drop_old_metric_unique FROM @drop_old_metric_unique;
EXECUTE stmt_drop_old_metric_unique;
DEALLOCATE PREPARE stmt_drop_old_metric_unique;

SET @has_safe_metric_unique = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='api_operation_metrics_hourly'
      AND index_name='uq_api_operation_hour_scope'
);
SET @add_safe_metric_unique = IF(
    @has_safe_metric_unique=0,
    'ALTER TABLE api_operation_metrics_hourly ADD UNIQUE KEY uq_api_operation_hour_scope (bucket_started_at,account_scope_key,operation_key)',
    'SELECT 1'
);
PREPARE stmt_add_safe_metric_unique FROM @add_safe_metric_unique;
EXECUTE stmt_add_safe_metric_unique;
DEALLOCATE PREPARE stmt_add_safe_metric_unique;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_processing.preview_max_jobs','5000','manual_processing',0),
('manual_processing.require_all_selected_profiles','1','manual_processing',0),
('api.workload.sample_retention_days','30','api_workload',0),
('api.workload.safe_p95_duration_ms','5000','api_workload',0),
('api.workload.safe_p95_decoded_bytes','1048576','api_workload',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.19.5','Integridad del procesamiento manual, cierre automático y telemetría p95 segura')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
