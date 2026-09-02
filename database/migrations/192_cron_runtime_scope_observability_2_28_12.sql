-- ERP Meli 2.28.12 — Cron seguro, observable y aislado por tenant.
-- Solo amplía metadatos técnicos. No modifica órdenes, pagos, cierres ni OAuth.

ALTER TABLE order_resource_enrichment_jobs
    ADD COLUMN IF NOT EXISTS last_error_diagnostic_id VARCHAR(64) NULL AFTER last_error_message,
    ADD COLUMN IF NOT EXISTS last_error_code VARCHAR(80) NULL AFTER last_error_diagnostic_id,
    ADD COLUMN IF NOT EXISTS failure_class VARCHAR(80) NULL AFTER last_error_code,
    ADD COLUMN IF NOT EXISTS reached_remote TINYINT(1) NULL AFTER failure_class;

CREATE TABLE IF NOT EXISTS question_sync_account_state (
    meli_account_id BIGINT UNSIGNED PRIMARY KEY,
    last_attempt_at DATETIME NULL,
    last_success_at DATETIME NULL,
    next_sync_at DATETIME NULL,
    last_error_message VARCHAR(500) NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_question_account_due (next_sync_at,meli_account_id),
    CONSTRAINT fk_question_sync_account
        FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE system_cron_run_steps
    ADD COLUMN IF NOT EXISTS attempted_remote_call_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER deferred_count,
    ADD COLUMN IF NOT EXISTS blocked_remote_call_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER remote_call_count;

ALTER TABLE system_work_queue_runs
    ADD COLUMN IF NOT EXISTS attempted_remote_call_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER inspected_count,
    ADD COLUMN IF NOT EXISTS blocked_remote_call_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER remote_call_count;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','192_cron_runtime_scope_observability_2_28_12.sql','structural',1),
('automation_center','192_cron_runtime_scope_observability_2_28_12.sql','structural',1),
('api_health','192_cron_runtime_scope_observability_2_28_12.sql','structural',1),
('order_enrichment','192_cron_runtime_scope_observability_2_28_12.sql','structural',1),
('questions','192_cron_runtime_scope_observability_2_28_12.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.runtime_scope_contract','2.28.12',0,'cron'),
('cron.remote_attempt_visibility','1',0,'cron'),
('questions.account_cooldown_enabled','1',0,'questions'),
('commercial_path.version','2.28.12',0,'commercial_path'),
('operational_audit.last_certified_version','2.28.12',0,'audit')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.12','Cron con selección cercada, telemetría remota verificable y aislamiento exacto')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
