-- ERP Meli 2.28.39 — snapshots operativos coherentes, incidentes materializados
-- y retención técnica CLI. No modifica información comercial.

CREATE TABLE IF NOT EXISTS system_operational_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_kind VARCHAR(40) NOT NULL,
    scope_signature VARCHAR(120) NOT NULL,
    generation VARCHAR(40) NOT NULL,
    run_token VARCHAR(100) NULL,
    protocol ENUM('complete','partial','authoritative_empty','unavailable') NOT NULL,
    payload_json JSON NOT NULL,
    measured_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_operational_snapshot_latest (snapshot_kind,scope_signature,measured_at,id),
    KEY idx_operational_snapshot_retention (measured_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_incident_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_key CHAR(64) NOT NULL,
    scope_key VARCHAR(80) NOT NULL,
    scope_kind ENUM('application','company','account') NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    outcome_class VARCHAR(40) NOT NULL,
    method VARCHAR(10) NOT NULL,
    endpoint_path VARCHAR(255) NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    error_type VARCHAR(80) NULL,
    error_code VARCHAR(120) NULL,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    reached_remote TINYINT(1) NOT NULL DEFAULT 0,
    actionable TINYINT(1) NOT NULL DEFAULT 0,
    risk_signal TINYINT(1) NOT NULL DEFAULT 0,
    first_seen_at DATETIME(3) NOT NULL,
    last_seen_at DATETIME(3) NOT NULL,
    repetitions BIGINT UNSIGNED NOT NULL DEFAULT 1,
    last_log_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_incident_group_scope (incident_key,scope_key,outcome_class,method),
    KEY idx_api_incident_group_page (last_seen_at,id),
    KEY idx_api_incident_group_account (meli_account_id,last_seen_at,id),
    KEY idx_api_incident_group_company (company_id,last_seen_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_incident_materializer_state (
    singleton_id TINYINT UNSIGNED NOT NULL,
    last_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    heartbeat_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (singleton_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO api_incident_materializer_state (singleton_id,last_log_id) VALUES (1,0);

CREATE TABLE IF NOT EXISTS system_retention_cli_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lane_key VARCHAR(40) NOT NULL,
    dataset_position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    lease_owner VARCHAR(160) NULL,
    lease_expires_at DATETIME(3) NULL,
    last_dataset VARCHAR(80) NULL,
    last_stage VARCHAR(40) NULL,
    last_processed INT UNSIGNED NOT NULL DEFAULT 0,
    heartbeat_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_retention_cli_lane (lane_key),
    KEY idx_retention_cli_lease (lease_expires_at,generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE system_cold_archives
    MODIFY dataset_key ENUM(
        'notification_events','notification_success','notification_incidents',
        'api_request_logs','cron_health_checks','financial_job_items','cron_run_steps',
        'process_metrics','work_queue_items','work_queue_runs','api_budget_windows',
        'manual_probe_runs','performance_metrics','system_logs','api_operation_samples',
        'webhook_events','cron_backlog_snapshots','cron_backlog_run_totals',
        'manual_campaign_events','api_remote_permits','operational_snapshots'
    ) NOT NULL;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema=DATABASE() AND table_name='system_work_queue_runs'
       AND index_name='idx_work_queue_runs_history')=0,
    'ALTER TABLE system_work_queue_runs ADD INDEX idx_work_queue_runs_history (started_at,id)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('retention.cli_enabled','1',0,'retention'),
('retention.api_remote_permit_success_days','15',0,'retention'),
('retention.api_remote_permit_incident_days','90',0,'retention'),
('cron.operational_snapshots_enabled','1',0,'cron'),
('api_health.materialized_incidents_enabled','1',0,'api_health');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.39',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','219_operational_snapshots_health_retention_2_28_39.sql','structural',1),
('automation_center','219_operational_snapshots_health_retention_2_28_39.sql','metadata',1),
('api_health','219_operational_snapshots_health_retention_2_28_39.sql','structural',1),
('database_maintenance','219_operational_snapshots_health_retention_2_28_39.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.39','Cron rápido con snapshots coherentes, Salud materializada y retención técnica verificable')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
