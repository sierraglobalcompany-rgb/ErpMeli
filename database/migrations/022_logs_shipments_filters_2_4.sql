ALTER TABLE meli_shipments
    ADD KEY idx_shipments_filters (meli_account_id, logistic_type, status, synced_at),
    ADD KEY idx_shipments_estimated (meli_account_id, estimated_delivery);

ALTER TABLE meli_payments
    ADD KEY idx_payments_filters_2_4 (meli_account_id, status, payment_method_id, payment_type, date_approved);

ALTER TABLE system_logs
    ADD KEY idx_system_logs_created (created_at);

ALTER TABLE api_error_logs
    ADD KEY idx_api_errors_filters_2_4 (meli_account_id, http_status, created_at);
