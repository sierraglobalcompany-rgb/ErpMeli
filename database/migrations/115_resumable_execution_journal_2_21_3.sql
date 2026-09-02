-- ERP Meli 2.21.3 — diario de ejecución recuperable y campañas dirigidas por CLI.

ALTER TABLE manual_campaigns
    MODIFY COLUMN execution_mode ENUM('legacy_cli','interactive_web','directed_cli')
        NOT NULL DEFAULT 'legacy_cli',
    ADD COLUMN IF NOT EXISTS last_approved_step BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER step_sequence,
    ADD COLUMN IF NOT EXISTS uncertain_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_approved_step,
    ADD COLUMN IF NOT EXISTS interrupted_runs INT UNSIGNED NOT NULL DEFAULT 0 AFTER uncertain_attempts,
    ADD COLUMN IF NOT EXISTS observed_safe_window_ms INT UNSIGNED NULL AFTER interrupted_runs,
    ADD COLUMN IF NOT EXISTS last_clean_run_at DATETIME(3) NULL AFTER observed_safe_window_ms,
    ADD COLUMN IF NOT EXISTS next_launcher_at DATETIME(3) NULL AFTER last_clean_run_at;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES ('process_sync_queue','115_resumable_execution_journal_2_21_3.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),enabled=1;

CREATE TABLE IF NOT EXISTS system_execution_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_token CHAR(40) NOT NULL,
    component_key VARCHAR(80) NOT NULL,
    manual_campaign_id BIGINT UNSIGNED NULL,
    status ENUM('running','completed','interrupted','failed','skipped') NOT NULL DEFAULT 'running',
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    heartbeat_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    stop_acquiring_at DATETIME(3) NULL,
    finished_at DATETIME(3) NULL,
    observed_runtime_ms INT UNSIGNED NOT NULL DEFAULT 0,
    approved_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    uncertain_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    end_reason VARCHAR(80) NULL,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    UNIQUE KEY uq_system_execution_run_token (run_token),
    KEY idx_system_execution_run_recovery (component_key,status,heartbeat_at),
    KEY idx_system_execution_run_campaign (manual_campaign_id,status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_execution_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_execution_run_id BIGINT UNSIGNED NOT NULL,
    manual_campaign_id BIGINT UNSIGNED NULL,
    manual_campaign_item_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    queue_key VARCHAR(80) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    operation_key VARCHAR(80) NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    lease_generation INT UNSIGNED NOT NULL DEFAULT 0,
    state ENUM('reserved','dispatch_recorded','response_received','approved','completed','uncertain','failed')
        NOT NULL DEFAULT 'reserved',
    budget_reserved TINYINT(1) NOT NULL DEFAULT 0,
    reached_remote TINYINT(1) NULL,
    primary_calls SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    derived_calls SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    response_status SMALLINT UNSIGNED NULL,
    response_fingerprint CHAR(64) NULL,
    checkpoint_json TEXT NULL,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    reserved_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    dispatched_at DATETIME(3) NULL,
    response_at DATETIME(3) NULL,
    approved_at DATETIME(3) NULL,
    completed_at DATETIME(3) NULL,
    UNIQUE KEY uq_execution_attempt_idempotency (idempotency_key),
    KEY idx_execution_attempt_recovery (state,reserved_at),
    KEY idx_execution_attempt_campaign (manual_campaign_id,manual_campaign_item_id,state),
    CONSTRAINT fk_execution_attempt_run FOREIGN KEY (system_execution_run_id)
        REFERENCES system_execution_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Las campañas web activas se conservan, pero se entregan al plano CLI.
UPDATE manual_campaigns
SET execution_mode='directed_cli',
    control_owner=NULL,
    control_heartbeat_at=NULL,
    control_expires_at=NULL,
    last_engine_state=IF(status='active','waiting_cli',last_engine_state),
    safe_message=IF(
        status='active',
        'Campaña preparada. El lanzador del ERP continuará desde el último resultado aprobado.',
        safe_message
    )
WHERE execution_mode='interactive_web'
  AND status IN ('active','pausing','paused','finishing');

UPDATE manual_campaign_reservations r
JOIN manual_campaigns c ON c.id=r.manual_campaign_id
SET r.expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE)
WHERE c.execution_mode='directed_cli' AND r.status='active';

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('execution.journal_enabled','1','automation',0),
('execution.default_observed_window_ms','20000','automation',0),
('execution.minimum_safe_window_ms','5000','automation',0),
('execution.recovery_margin_ms','5000','automation',0),
('execution.max_uncertain_attempts','3','automation',0),
('manual_campaign.cli_directed_enabled','1','manual_campaign',0),
('manual_campaign.interactive_enabled','0','manual_campaign',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.21.3','Diario recuperable, último estado aprobado y campañas dirigidas por CLI')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
