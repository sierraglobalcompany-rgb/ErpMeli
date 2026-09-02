-- ERP Meli 2.19.7 — evidencia de carga aislada por cuenta.
-- Aditiva e idempotente. No modifica datos comerciales ni realiza llamadas remotas.

SET @has_metric_scope_remote_index = (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='api_operation_metric_samples'
      AND index_name='idx_api_metric_scope_remote'
);
SET @add_metric_scope_remote_index = IF(
    @has_metric_scope_remote_index=0,
    'ALTER TABLE api_operation_metric_samples ADD KEY idx_api_metric_scope_remote (account_scope_key,operation_key,reached_remote,created_at,id)',
    'SELECT 1'
);
PREPARE stmt_add_metric_scope_remote_index FROM @add_metric_scope_remote_index;
EXECUTE stmt_add_metric_scope_remote_index;
DEALLOCATE PREPARE stmt_add_metric_scope_remote_index;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('api.workload.account_scoped_profiles','1','api_workload',0),
('api.workload.require_percentile_samples','1','api_workload',0),
('api.workload.block_batch_growth_on_local_failure','1','api_workload',0),
('manual_processing.conservative_bootstrap_enabled','1','manual_processing',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.19.7','Telemetría por cuenta, canarios conservadores y reservas manuales completas')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
