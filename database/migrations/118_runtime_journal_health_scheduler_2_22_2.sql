-- ERP Meli 2.22.2 — diario atómico, elegibilidad única y lanzador consolidado.

ALTER TABLE system_execution_attempts
    MODIFY COLUMN state ENUM(
        'reserved','local_started','budget_reserved','remote_dispatched',
        'response_received','result_applied','approved','uncertain','failed',
        'dispatch_recorded','completed'
    ) NOT NULL DEFAULT 'reserved',
    ADD COLUMN IF NOT EXISTS result_applied_at DATETIME(3) NULL AFTER response_at,
    ADD COLUMN IF NOT EXISTS approval_sequence BIGINT UNSIGNED NULL AFTER result_applied_at;

ALTER TABLE cron_health_checks
    ADD COLUMN IF NOT EXISTS observed_interval_seconds INT UNSIGNED NULL AFTER next_expected_at;

ALTER TABLE system_work_queue_projection
    ADD COLUMN IF NOT EXISTS company_id BIGINT UNSIGNED NULL AFTER source_id,
    ADD COLUMN IF NOT EXISTS lease_expires_at DATETIME NULL AFTER heartbeat_at,
    ADD COLUMN IF NOT EXISTS eligibility_state ENUM(
        'ready','running','future','waiting_budget','paused','action_required','unknown','terminal'
    ) NOT NULL DEFAULT 'unknown' AFTER display_status,
    ADD COLUMN IF NOT EXISTS eligibility_checked_at DATETIME(3) NULL AFTER eligibility_state,
    ADD COLUMN IF NOT EXISTS eligibility_reason VARCHAR(500) NULL AFTER eligibility_checked_at;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','118_runtime_journal_health_scheduler_2_22_2.sql','structural',1),
('automation_center','118_runtime_journal_health_scheduler_2_22_2.sql','structural',1),
('api_health','118_runtime_journal_health_scheduler_2_22_2.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),enabled=1;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('cron.main_interval_minutes','1','cron',0),
('cron.single_launcher_enabled','1','cron',0),
('cron.notifications_dedicated_enabled','0','cron',0),
('cron.verification_required_streak','2','cron',0),
('automation.eligibility_stale_seconds','180','automation',0),
('api.health.expected_absence_neutral','1','api_health',0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

-- Estados antiguos equivalentes se normalizan sin borrar la trazabilidad.
UPDATE system_execution_attempts
SET state=CASE state
    WHEN 'dispatch_recorded' THEN 'remote_dispatched'
    WHEN 'completed' THEN 'approved'
    ELSE state
END;

ALTER TABLE system_execution_attempts
    MODIFY COLUMN state ENUM(
        'reserved','local_started','budget_reserved','remote_dispatched',
        'response_received','result_applied','approved','uncertain','failed'
    ) NOT NULL DEFAULT 'reserved';

UPDATE api_request_logs
SET outcome_class='expected_absence',actionable=0,risk_signal=0
WHERE http_status=404
  AND method='GET'
  AND endpoint_path REGEXP '^/questions/[^/]+$';

INSERT INTO app_versions (version,notes)
VALUES ('2.22.2','Diario atómico, elegibilidad coherente, salud explicable y lanzador único')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
