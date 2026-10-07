-- Dedicated outer Cron receipt on the existing execution journal.
-- No business state, settings, rhythm or Financial capture changes.
ALTER TABLE system_execution_runs
    ADD COLUMN http_receipt_json LONGTEXT NULL,
    ADD KEY idx_execution_http_retention (component_key,id);
