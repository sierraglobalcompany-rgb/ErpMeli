-- ERP Meli 2.28.13 — Reauditoría adversarial de Cron y Salud API.
-- Solo amplía contratos e índices técnicos. No modifica datos comerciales.

SET @idx_api_request_created_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'api_request_logs'
      AND BINARY INDEX_NAME=BINARY 'idx_api_request_retention_created'
);
SET @idx_api_request_created_sql = IF(
    @idx_api_request_created_exists=0,
    'ALTER TABLE api_request_logs ADD INDEX idx_api_request_retention_created (created_at,id)',
    'SELECT 1'
);
PREPARE idx_api_request_created_stmt FROM @idx_api_request_created_sql;
EXECUTE idx_api_request_created_stmt;
DEALLOCATE PREPARE idx_api_request_created_stmt;

SET @idx_work_runs_origin_finished_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'system_work_queue_runs'
      AND BINARY INDEX_NAME=BINARY 'idx_work_runs_origin_finished'
);
SET @idx_work_runs_origin_finished_sql = IF(
    @idx_work_runs_origin_finished_exists=0,
    'ALTER TABLE system_work_queue_runs ADD INDEX idx_work_runs_origin_finished (origin,finished_at,id)',
    'SELECT 1'
);
PREPARE idx_work_runs_origin_finished_stmt FROM @idx_work_runs_origin_finished_sql;
EXECUTE idx_work_runs_origin_finished_stmt;
DEALLOCATE PREPARE idx_work_runs_origin_finished_stmt;

SET @idx_work_runs_status_finished_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'system_work_queue_runs'
      AND BINARY INDEX_NAME=BINARY 'idx_work_runs_status_finished'
);
SET @idx_work_runs_status_finished_sql = IF(
    @idx_work_runs_status_finished_exists=0,
    'ALTER TABLE system_work_queue_runs ADD INDEX idx_work_runs_status_finished (status,finished_at,started_at,id)',
    'SELECT 1'
);
PREPARE idx_work_runs_status_finished_stmt FROM @idx_work_runs_status_finished_sql;
EXECUTE idx_work_runs_status_finished_stmt;
DEALLOCATE PREPARE idx_work_runs_status_finished_stmt;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','193_cron_api_health_adversarial_2_28_13.sql','structural',1),
('automation_center','193_cron_api_health_adversarial_2_28_13.sql','structural',1),
('api_health','193_cron_api_health_adversarial_2_28_13.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.adversarial_contract','2.28.13',0,'cron'),
('cron.api_health_read_cache_seconds','12',0,'cron'),
('api.health.tenant_scope_contract','2.28.13',0,'api_health'),
('commercial_path.version','2.28.13',0,'commercial_path'),
('operational_audit.last_certified_version','2.28.13',0,'audit')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.13','Cron reauditoria adversarial, Salud API aislada y lecturas operativas optimizadas')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
