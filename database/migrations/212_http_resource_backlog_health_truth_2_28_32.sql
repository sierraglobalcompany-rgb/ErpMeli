-- ERP Meli 2.28.32 - backlog, transportes y Salud API con evidencia separada.
-- Solo amplía telemetría técnica. No modifica ni reintenta datos comerciales,
-- campañas, OAuth, pagos, cierres ni evidencia fiscal.

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='previous_pending')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN previous_pending INT UNSIGNED NULL AFTER attention_count',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='api_request_logs' AND column_name='response_count_state')=0,
    'ALTER TABLE api_request_logs ADD COLUMN response_count_state VARCHAR(12) NOT NULL DEFAULT ''unknown'' AFTER response_item_count',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='api_request_logs' AND column_name='response_resource_unit')=0,
    'ALTER TABLE api_request_logs ADD COLUMN response_resource_unit VARCHAR(40) NULL AFTER response_count_state',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='newly_discovered')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN newly_discovered INT UNSIGNED NULL AFTER previous_pending',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='deduplicated')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN deduplicated INT UNSIGNED NULL AFTER newly_discovered',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='finalized')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN finalized INT UNSIGNED NULL AFTER deduplicated',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='current_pending')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN current_pending INT UNSIGNED NULL AFTER finalized',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='http_dispatched')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN http_dispatched INT UNSIGNED NULL AFTER current_pending',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='known_responses')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN known_responses INT UNSIGNED NULL AFTER http_dispatched',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='resources_received')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN resources_received INT UNSIGNED NULL AFTER known_responses',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND column_name='equation_state')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD COLUMN equation_state VARCHAR(20) NOT NULL DEFAULT ''unavailable'' AFTER resources_received',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots' AND index_name='idx_cron_backlog_queue_complete_time')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD INDEX idx_cron_backlog_queue_complete_time (queue_key,measurement_state,measured_at)',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items' AND column_name='newly_discovered_count')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN newly_discovered_count INT UNSIGNED NULL AFTER completed_count',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items' AND column_name='deduplicated_count')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN deduplicated_count INT UNSIGNED NULL AFTER newly_discovered_count',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items' AND column_name='known_response_count')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN known_response_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER actual_api_calls',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;
SET @erp_sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items' AND column_name='resources_received_count')=0,
    'ALTER TABLE system_work_queue_run_items ADD COLUMN resources_received_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER known_response_count',
    'SELECT 1'
);
PREPARE erp_stmt FROM @erp_sql; EXECUTE erp_stmt; DEALLOCATE PREPARE erp_stmt;

CREATE TABLE IF NOT EXISTS api_health_correction_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    api_request_log_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    original_outcome_class VARCHAR(40) NOT NULL,
    corrected_outcome_class VARCHAR(40) NOT NULL,
    correction_reason VARCHAR(80) NOT NULL,
    safe_message VARCHAR(500) NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_health_correction_log_reason (api_request_log_id,correction_reason),
    KEY idx_api_health_correction_scope_time (company_id,meli_account_id,created_at),
    KEY idx_api_health_correction_outcome (corrected_outcome_class,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un HTTP 2xx demuestra que hubo respuesta. Se conserva el log original y se
-- agrega una corrección auditable; no se vuelve a ejecutar la operación.
INSERT IGNORE INTO api_health_correction_events
    (api_request_log_id,company_id,meli_account_id,original_outcome_class,
     corrected_outcome_class,correction_reason,safe_message)
SELECT l.id,l.company_id,l.meli_account_id,l.outcome_class,
       'success','legacy_http_2xx_known_response',
       'Respuesta HTTP correcta comprobada; se conserva el incidente histórico sin reintento.'
FROM api_request_logs l
WHERE l.outcome_class='remote_result_uncertain'
  AND l.reached_remote=1
  AND l.http_status BETWEEN 200 AND 299;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.backlog_equation_protocol','2',0,'cron'),
('api.health.correction_events_enabled','1',0,'api_health');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.32',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','212_http_resource_backlog_health_truth_2_28_32.sql','structural',1),
('automation_center','212_http_resource_backlog_health_truth_2_28_32.sql','structural',1),
('api_health','212_http_resource_backlog_health_truth_2_28_32.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.32','Backlog demostrable, HTTP y recursos separados, y correcciones locales auditables de Salud API')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
