-- ERP Meli 2.28.10 — verdad de ejecución de Cron y campañas.
-- Solo modifica metadatos operativos. No toca órdenes, ventas, pagos ni OAuth.

ALTER TABLE system_cron_run_steps
    ADD COLUMN IF NOT EXISTS lane VARCHAR(24) NULL AFTER step_name,
    ADD COLUMN IF NOT EXISTS campaign_id BIGINT UNSIGNED NULL AFTER lane,
    ADD COLUMN IF NOT EXISTS campaign_item_id BIGINT UNSIGNED NULL AFTER campaign_id,
    ADD COLUMN IF NOT EXISTS selection_reason VARCHAR(120) NULL AFTER campaign_item_id,
    ADD COLUMN IF NOT EXISTS execution_result VARCHAR(60) NULL AFTER selection_reason,
    ADD COLUMN IF NOT EXISTS selected_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS started_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER selected_count,
    ADD COLUMN IF NOT EXISTS inspected_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER started_count,
    ADD COLUMN IF NOT EXISTS deferred_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER inspected_count,
    ADD COLUMN IF NOT EXISTS remote_call_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER deferred_count,
    ADD COLUMN IF NOT EXISTS checkpoint_approved_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER remote_call_count,
    ADD COLUMN IF NOT EXISTS completed_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER checkpoint_approved_count,
    ADD COLUMN IF NOT EXISTS failed_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER completed_count,
    ADD COLUMN IF NOT EXISTS not_started_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER failed_count,
    ADD COLUMN IF NOT EXISTS next_opportunity_at DATETIME(3) NULL AFTER stop_reason;

ALTER TABLE system_work_queue_runs
    ADD COLUMN IF NOT EXISTS started_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER selected_count,
    ADD COLUMN IF NOT EXISTS inspected_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER started_count,
    ADD COLUMN IF NOT EXISTS remote_call_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER inspected_count,
    ADD COLUMN IF NOT EXISTS checkpoint_approved_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER remote_call_count,
    ADD COLUMN IF NOT EXISTS not_started_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER failed_count;

ALTER TABLE system_work_queue_run_items
    MODIFY COLUMN result ENUM('selected','started','inspected','deferred','completed','partial','failed','retried','skipped','not_started') NOT NULL DEFAULT 'selected',
    ADD COLUMN IF NOT EXISTS lane VARCHAR(24) NULL AFTER queue_key,
    ADD COLUMN IF NOT EXISTS campaign_id BIGINT UNSIGNED NULL AFTER source_id,
    ADD COLUMN IF NOT EXISTS campaign_item_id BIGINT UNSIGNED NULL AFTER campaign_id,
    ADD COLUMN IF NOT EXISTS selection_reason VARCHAR(120) NULL AFTER campaign_item_id,
    ADD COLUMN IF NOT EXISTS execution_result VARCHAR(60) NULL AFTER selection_reason,
    ADD COLUMN IF NOT EXISTS inspected_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER estimated_seconds,
    ADD COLUMN IF NOT EXISTS deferred_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER inspected_count,
    ADD COLUMN IF NOT EXISTS checkpoint_approved_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER actual_api_calls,
    ADD COLUMN IF NOT EXISTS started_at DATETIME(3) NULL AFTER position_no,
    ADD COLUMN IF NOT EXISTS finished_at DATETIME(3) NULL AFTER updated_at,
    ADD COLUMN IF NOT EXISTS next_opportunity_at DATETIME(3) NULL AFTER finished_at;

ALTER TABLE manual_campaigns
    ADD COLUMN IF NOT EXISTS last_scheduler_run_token VARCHAR(100) NULL AFTER last_scheduler_reason,
    ADD COLUMN IF NOT EXISTS last_scheduler_started_at DATETIME(3) NULL AFTER last_scheduler_run_token,
    ADD COLUMN IF NOT EXISTS last_scheduler_result VARCHAR(60) NULL AFTER last_scheduler_started_at,
    ADD COLUMN IF NOT EXISTS last_remote_call_at DATETIME(3) NULL AFTER last_scheduler_result;

ALTER TABLE manual_campaign_items
    ADD COLUMN IF NOT EXISTS last_cron_run_token VARCHAR(100) NULL AFTER source_resolution,
    ADD COLUMN IF NOT EXISTS last_attempt_result VARCHAR(60) NULL AFTER last_cron_run_token,
    ADD COLUMN IF NOT EXISTS last_attempt_at DATETIME(3) NULL AFTER last_attempt_result;

-- Corrige únicamente progreso técnico inflado de ítems no terminales sin evidencia remota.
UPDATE manual_campaign_items
SET completed_units=0
WHERE status IN ('pending','waiting','retry','running')
  AND completed_at IS NULL
  AND primary_calls=0
  AND derived_calls=0
  AND completed_units>0;

UPDATE manual_campaigns c
JOIN (
    SELECT manual_campaign_id,
           SUM(status='completed') completed_items_real,
           SUM(status='skipped') skipped_items_real,
           SUM(status='failed') failed_items_real,
           SUM(status IN ('waiting','retry')) retry_items_real,
           COALESCE(SUM(completed_units),0) completed_units_real,
           COALESCE(SUM(failed_units),0) failed_units_real,
           COALESCE(SUM(skipped_units),0) skipped_units_real,
           COALESCE(SUM(primary_calls),0) primary_calls_real,
           COALESCE(SUM(derived_calls),0) derived_calls_real
    FROM manual_campaign_items
    GROUP BY manual_campaign_id
) campaign_stats ON campaign_stats.manual_campaign_id=c.id
SET c.completed_items=campaign_stats.completed_items_real,
    c.skipped_items=campaign_stats.skipped_items_real,
    c.failed_items=campaign_stats.failed_items_real,
    c.retry_items=campaign_stats.retry_items_real,
    c.completed_units=campaign_stats.completed_units_real,
    c.failed_units=campaign_stats.failed_units_real,
    c.skipped_units=campaign_stats.skipped_units_real,
    c.primary_calls=campaign_stats.primary_calls_real,
    c.derived_calls=campaign_stats.derived_calls_real,
    c.outbound_calls=campaign_stats.primary_calls_real+campaign_stats.derived_calls_real;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','190_cron_execution_truth_2_28_10.sql','structural',1),
('automation_center','190_cron_execution_truth_2_28_10.sql','structural',1),
('manual_campaigns','190_cron_execution_truth_2_28_10.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.urgent_lane_seconds','10',0,'cron'),
('cron.directed_lane_seconds','15',0,'cron'),
('cron.safe_close_seconds','10',0,'cron'),
('cron.overview_poll_seconds','10',0,'cron'),
('cron.hidden_poll_seconds','30',0,'cron'),
('commercial_path.version','2.28.10',0,'commercial_path'),
('operational_audit.last_certified_version','2.28.10',0,'audit')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.10','Cron verificable, equitativo y entendible')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
