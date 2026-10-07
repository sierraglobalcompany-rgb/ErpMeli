-- Dedicated outer Cron receipt on the existing execution journal.
-- No business state, settings, rhythm or Financial capture changes.
ALTER TABLE system_execution_runs
    ADD COLUMN IF NOT EXISTS http_receipt_json LONGTEXT NULL;

ALTER TABLE system_execution_attempts
    ADD COLUMN IF NOT EXISTS http_request_id CHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS http_state VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS http_source VARCHAR(96) NULL,
    ADD COLUMN IF NOT EXISTS http_method VARCHAR(8) NULL,
    ADD COLUMN IF NOT EXISTS http_endpoint VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS http_budget_released TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS http_budget_exhausted TINYINT(1) NOT NULL DEFAULT 0;

CREATE UNIQUE INDEX IF NOT EXISTS uq_execution_outer_http_request
    ON system_execution_attempts(system_execution_run_id,http_request_id);
