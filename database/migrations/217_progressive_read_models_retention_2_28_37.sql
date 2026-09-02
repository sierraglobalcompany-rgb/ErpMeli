-- ERP Meli 2.28.37 — read models O(1), retención verificable y soporte
-- de shell progresivo. No modifica órdenes, pagos, campañas, cuentas, OAuth,
-- cierres ni evidencia fiscal.

CREATE TABLE IF NOT EXISTS system_cron_backlog_run_totals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_token VARCHAR(100) NOT NULL,
    measurement_state ENUM('complete','partial','unavailable') NOT NULL DEFAULT 'unavailable',
    coverage_signature TEXT NOT NULL,
    total_pending INT UNSIGNED NOT NULL DEFAULT 0,
    measured_queues SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    partial_queues SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    unavailable_queues SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    previous_pending INT UNSIGNED NULL,
    current_pending INT UNSIGNED NULL,
    newly_discovered INT UNSIGNED NULL,
    deduplicated INT UNSIGNED NULL,
    finalized INT UNSIGNED NULL,
    http_dispatched INT UNSIGNED NULL,
    known_responses INT UNSIGNED NULL,
    resources_received INT UNSIGNED NULL,
    equation_complete_queues SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    equation_partial_queues SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    measured_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cron_backlog_totals_run (run_token),
    KEY idx_cron_backlog_totals_measured (measured_at,run_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='system_cron_backlog_snapshots'
       AND index_name='idx_cron_backlog_retention')=0,
    'ALTER TABLE system_cron_backlog_snapshots ADD INDEX idx_cron_backlog_retention (measured_at,id)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='system_work_queue_run_items'
       AND index_name='idx_work_run_items_latest_batch')=0,
    'ALTER TABLE system_work_queue_run_items ADD INDEX idx_work_run_items_latest_batch (queue_key,finished_at,id)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='manual_campaign_events'
       AND index_name='idx_manual_campaign_event_retention')=0,
    'ALTER TABLE manual_campaign_events ADD INDEX idx_manual_campaign_event_retention (created_at,id)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='system_work_queue_projection'
       AND index_name='idx_work_projection_scope_status_queue')=0,
    'ALTER TABLE system_work_queue_projection ADD INDEX idx_work_projection_scope_status_queue (display_status,company_id,meli_account_id,queue_key)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.read_model_single_flight','1',0,'cron'),
('cron.read_model_stale_while_revalidate','1',0,'cron'),
('cron.backlog_run_totals_enabled','1',0,'cron'),
('retention.cron_backlog_detail_days','15',0,'retention'),
('manual_campaign.event_retention_days','30',0,'manual_campaign');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.37',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('automation_center','217_progressive_read_models_retention_2_28_37.sql','structural',1),
('api_health','217_progressive_read_models_retention_2_28_37.sql','metadata',1),
('database_maintenance','217_progressive_read_models_retention_2_28_37.sql','metadata',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.37','Read models O(1), caché single-flight y retención de telemetría Cron')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
