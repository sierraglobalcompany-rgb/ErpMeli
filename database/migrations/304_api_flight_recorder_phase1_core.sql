-- Minimal optional Flight Recorder metadata on the existing API request log.
-- No backfill, index, new authority, or business-state rewrite.
ALTER TABLE api_request_logs
    ADD COLUMN trace_id VARCHAR(64) NULL AFTER request_id,
    ADD COLUMN physical_started_at_process DATETIME(3) NULL AFTER trace_id,
    ADD COLUMN rate_limit_headers_json TEXT NULL AFTER physical_started_at_process;
