-- Dedicated outer Cron receipt on the existing execution journal.
-- No business state, settings, rhythm or Financial capture changes.
ALTER TABLE system_execution_runs
    ADD COLUMN http_receipt_json LONGTEXT NULL;

ALTER TABLE system_execution_attempts
    ADD COLUMN http_request_id CHAR(40) NULL,
    ADD COLUMN http_state VARCHAR(32) NULL,
    ADD COLUMN http_source VARCHAR(96) NULL,
    ADD COLUMN http_method VARCHAR(8) NULL,
    ADD COLUMN http_endpoint VARCHAR(32) NULL,
    ADD COLUMN http_budget_released TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN http_budget_exhausted TINYINT(1) NOT NULL DEFAULT 0,
    ADD UNIQUE KEY uq_execution_outer_http_request (system_execution_run_id,http_request_id);

-- The existing archive registry is an ENUM; register only these two technical
-- datasets. No new table, setting, cleanup engine or execution authority.
ALTER TABLE system_cold_archives
    MODIFY dataset_key ENUM(
        'notification_events','notification_success','notification_incidents',
        'api_request_logs','cron_health_checks','financial_job_items','cron_run_steps',
        'process_metrics','work_queue_items','work_queue_runs','api_budget_windows',
        'manual_probe_runs','performance_metrics','system_logs','api_operation_samples',
        'webhook_events','cron_backlog_snapshots','cron_backlog_run_totals',
        'manual_campaign_events','api_remote_permits','operational_snapshots',
        'outer_http_attempts','outer_http_runs'
    ) NOT NULL;
