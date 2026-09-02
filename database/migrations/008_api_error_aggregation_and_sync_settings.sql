ALTER TABLE api_error_logs
    ADD KEY idx_api_errors_grouping (meli_account_id, method, endpoint_path(190), http_status, error_code, created_at);

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
('sync.max_manual_range_days', '7', 0, 'sync'),
('sync.page_limit', '50', 0, 'sync'),
('sync.pause_between_pages_ms', '400', 0, 'sync'),
('sync.max_orders_per_run', '500', 0, 'sync'),
('sync.backoff_429_seconds', '5', 0, 'sync'),
('sync.backoff_5xx_seconds', '3', 0, 'sync'),
('sync.skip_permanent_404', '1', 0, 'sync')
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);
