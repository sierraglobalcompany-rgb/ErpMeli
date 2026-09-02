-- ERP Meli 2.24.2 — recuperación operativa, collation y estados del scheduler.
-- Aditiva e idempotente. No modifica datos comerciales ni consulta Mercado Libre.

CREATE TABLE IF NOT EXISTS system_cron_boot_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_token CHAR(32) NOT NULL,
    component_key VARCHAR(80) NOT NULL,
    release_version VARCHAR(30) NULL,
    release_build_id VARCHAR(80) NULL,
    execution_source ENUM('scheduled_cli','manual_cli','unknown') NOT NULL DEFAULT 'unknown',
    stage ENUM(
        'invoked','validating_installation','preparing_queues','processing',
        'finished','stopped_before_queues'
    ) NOT NULL DEFAULT 'invoked',
    result_state ENUM('running','success','partial','skipped','error') NOT NULL DEFAULT 'running',
    process_id_hash CHAR(16) NULL,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    reached_remote TINYINT(1) NOT NULL DEFAULT 0,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    heartbeat_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finished_at DATETIME(3) NULL,
    duration_ms INT UNSIGNED NULL,
    UNIQUE KEY uq_cron_boot_attempt_token (run_token),
    KEY idx_cron_boot_component_recent (component_key,started_at),
    KEY idx_cron_boot_state (result_state,stage,heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE system_component_schema_contracts
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE schema_migrations
    MODIFY COLUMN version VARCHAR(100)
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE system_cron_run_steps
    MODIFY COLUMN status ENUM(
        'running','completed','partial','empty','deferred','waiting_budget',
        'waiting_guard','waiting_lock','retry_scheduled','action_required','failed',
        'complete','error'
    ) NOT NULL DEFAULT 'running';

ALTER TABLE system_process_metrics
    MODIFY COLUMN status ENUM(
        'running','completed','partial','empty','deferred','waiting_budget',
        'waiting_guard','waiting_lock','retry_scheduled','action_required','failed',
        'complete','paused','error'
    ) NOT NULL DEFAULT 'running';

ALTER TABLE system_work_queue_projection
    ADD COLUMN IF NOT EXISTS normalized_error_code VARCHAR(80) NULL AFTER diagnostic_id,
    ADD COLUMN IF NOT EXISTS retry_policy ENUM('automatic','scheduled','manual','terminal','unknown')
        NOT NULL DEFAULT 'unknown' AFTER normalized_error_code,
    ADD COLUMN IF NOT EXISTS remediation_key VARCHAR(80) NULL AFTER retry_policy,
    ADD COLUMN IF NOT EXISTS reached_remote TINYINT(1) NULL AFTER remediation_key,
    ADD COLUMN IF NOT EXISTS next_retry_at DATETIME NULL AFTER reached_remote;

SET @has_work_remediation_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='system_work_queue_projection'
      AND index_name='idx_work_projection_remediation'
);
SET @work_remediation_idx_sql = IF(
    @has_work_remediation_idx=0,
    'ALTER TABLE system_work_queue_projection ADD KEY idx_work_projection_remediation (display_status,remediation_key,meli_account_id,created_at_source)',
    'SELECT 1'
);
PREPARE stmt_work_remediation_idx FROM @work_remediation_idx_sql;
EXECUTE stmt_work_remediation_idx;
DEALLOCATE PREPARE stmt_work_remediation_idx;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','124_runtime_collation_scheduler_recovery_2_24_2.sql','structural',1),
('automation_center','124_runtime_collation_scheduler_recovery_2_24_2.sql','structural',1)
ON DUPLICATE KEY UPDATE contract_kind=VALUES(contract_kind),enabled=1;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('cron.single_launcher_enabled','1','cron',0),
('cron.notifications_dedicated_enabled','0','cron',0),
('cron.bootstrap_journal_enabled','1','cron',0),
('cron.main_interval_minutes','1','cron',0),
('automation.guided_remediation_enabled','1','automation',0),
('automation.error_auto_claim_enabled','0','automation',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.24.2','Recuperación operativa certificada, collation compatible y scheduler explicable')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
