ALTER TABLE meli_billing_capture_runs
    ADD COLUMN financial_job_id BIGINT UNSIGNED NULL AFTER input_version,
    ADD COLUMN financial_claim_generation INT UNSIGNED NULL AFTER financial_job_id,
    ADD COLUMN external_order_id VARCHAR(255) NULL AFTER financial_claim_generation,
    ADD COLUMN billing_resource_key CHAR(64) NULL AFTER external_order_id,
    ADD COLUMN request_id VARCHAR(128) NULL AFTER billing_resource_key,
    ADD COLUMN dispatch_state ENUM('reserved','dispatch_committed','result_durable','aborted_pretransport') NULL AFTER request_id,
    ADD COLUMN dispatch_committed_at DATETIME(6) NULL AFTER dispatch_state,
    ADD COLUMN result_durable_at DATETIME(6) NULL AFTER dispatch_committed_at,
    ADD COLUMN aborted_at DATETIME(6) NULL AFTER result_durable_at,
    ADD COLUMN response_body_raw MEDIUMBLOB NULL AFTER aborted_at,
    ADD COLUMN unresolved_guard TINYINT UNSIGNED
        GENERATED ALWAYS AS (
            CASE WHEN dispatch_state IN ('reserved','dispatch_committed') THEN 1 ELSE NULL END
        ) STORED AFTER response_body_raw,
    ADD UNIQUE KEY uq_billing_capture_unresolved_order
        (company_id,meli_account_id,billing_resource_key,unresolved_guard),
    ADD UNIQUE KEY uq_billing_capture_request_id (request_id),
    ADD KEY idx_billing_capture_financial_generation
        (financial_job_id,financial_claim_generation),
    ADD KEY idx_billing_capture_resource_history
        (company_id,meli_account_id,billing_resource_key,captured_at);
