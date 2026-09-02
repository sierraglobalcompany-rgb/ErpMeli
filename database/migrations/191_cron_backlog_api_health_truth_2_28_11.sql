-- ERP Meli 2.28.11 — backlog, lotes y Salud API con evidencia verificable.
-- Solo amplía telemetría técnica. No modifica datos comerciales ni credenciales.

ALTER TABLE cron_task_state
    ADD COLUMN IF NOT EXISTS last_work_observed_at DATETIME(3) NULL AFTER oldest_due_at,
    ADD COLUMN IF NOT EXISTS last_batch_started INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_processed,
    ADD COLUMN IF NOT EXISTS last_batch_completed INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_batch_started,
    ADD COLUMN IF NOT EXISTS last_batch_deferred INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_batch_completed,
    ADD COLUMN IF NOT EXISTS last_batch_remote_calls INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_batch_deferred;

ALTER TABLE system_work_queue_run_items
    ADD COLUMN IF NOT EXISTS backlog_before INT UNSIGNED NULL AFTER selection_reason,
    ADD COLUMN IF NOT EXISTS backlog_after INT UNSIGNED NULL AFTER backlog_before,
    ADD COLUMN IF NOT EXISTS batch_limit INT UNSIGNED NULL AFTER backlog_after,
    ADD COLUMN IF NOT EXISTS work_unit VARCHAR(40) NULL AFTER batch_limit,
    ADD COLUMN IF NOT EXISTS completed_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER deferred_count;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','191_cron_backlog_api_health_truth_2_28_11.sql','structural',1),
('automation_center','191_cron_backlog_api_health_truth_2_28_11.sql','structural',1),
('api_health','191_cron_backlog_api_health_truth_2_28_11.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.spool_batch_limit','20',0,'cron'),
('cron.spool_counter_reconcile_seconds','300',0,'cron'),
('cron.task_truth_enabled','1',0,'cron'),
('api_health.automation_evidence_enabled','1',0,'api_health'),
('commercial_path.version','2.28.11',0,'commercial_path'),
('operational_audit.last_certified_version','2.28.11',0,'audit')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.11','Cron con backlog y lotes claros; Salud API coherente con evidencia de automatización')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
