-- ERP Meli 2.28.15 — ritmo remoto global y permisos cercados.
-- Solo agrega infraestructura operativa; no modifica datos comerciales.

CREATE TABLE IF NOT EXISTS api_rhythm_states (
    scope_key VARCHAR(64) NOT NULL,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,
    block_started_at DATETIME(3) NULL,
    next_allowed_at DATETIME(3) NULL,
    block_pause_until DATETIME(3) NULL,
    last_dispatched_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (scope_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_remote_permits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    permit_token CHAR(40) NOT NULL,
    owner_token CHAR(32) NOT NULL,
    generation BIGINT UNSIGNED NOT NULL,
    run_token VARCHAR(100) NULL,
    work_key VARCHAR(120) NULL,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    endpoint_key VARCHAR(120) NOT NULL,
    job_type VARCHAR(80) NOT NULL,
    method VARCHAR(10) NOT NULL,
    status ENUM('reserved','dispatched','completed','released','expired') NOT NULL DEFAULT 'reserved',
    requested_interval_ms INT UNSIGNED NOT NULL,
    effective_interval_ms INT UNSIGNED NOT NULL,
    blocking_scope VARCHAR(80) NULL,
    http_status SMALLINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL,
    dispatched_at DATETIME(3) NULL,
    completed_at DATETIME(3) NULL,
    released_at DATETIME(3) NULL,
    expires_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_remote_permit_token (permit_token),
    KEY idx_api_remote_permit_active (status,expires_at),
    KEY idx_api_remote_permit_run (run_token,status),
    KEY idx_api_remote_permit_scope (company_id,meli_account_id,endpoint_key,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE order_resource_enrichment_jobs
    ADD COLUMN IF NOT EXISTS last_error_diagnostic_id VARCHAR(80) NULL AFTER last_error_message;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('api.rhythm.mode','recovery',0,'api_rhythm'),
('api.rhythm.calls_per_block','40',0,'api_rhythm'),
('api.rhythm.interval_ms','1000',0,'api_rhythm'),
('api.rhythm.block_pause_ms','20000',0,'api_rhythm'),
('api.rhythm.short_wait_ceiling_ms','1500',0,'api_rhythm'),
('api.rhythm.adaptive_enabled','1',0,'api_rhythm'),
('cron.notification_batch_limit','5',0,'cron'),
('cron.order_enrichment_batch_limit','5',0,'cron'),
('cron.pack_reconciliation_batch_limit','5',0,'cron'),
('cron.financial_reconciliation_batch_limit','3',0,'cron'),
('cron.max_tasks_per_run','4',0,'cron'),
('cron.max_api_tasks_per_run','3',0,'cron'),
('app.version','2.28.15',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=CASE
        WHEN setting_key='app.version' THEN VALUES(setting_value)
        WHEN setting_key='cron.max_tasks_per_run' AND CAST(setting_value AS UNSIGNED)<=3 THEN VALUES(setting_value)
        WHEN setting_key='cron.max_api_tasks_per_run' AND CAST(setting_value AS UNSIGNED)<=1 THEN VALUES(setting_value)
        ELSE setting_value
    END,
    setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','195_cron_rhythm_scheduler_recovery_2_28_15.sql','structural',1),
('api_rhythm','195_cron_rhythm_scheduler_recovery_2_28_15.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.15','Motor Cron con ritmo API global, permisos cercados y colas drenables')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
