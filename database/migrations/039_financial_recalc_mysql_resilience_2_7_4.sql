-- ERP Meli / Gestion Pro 2.7.4
-- Recálculo financiero resiliente a cortes MySQL en hosting compartido.

ALTER TABLE order_financial_recalc_jobs
    ADD COLUMN last_db_reconnect_at DATETIME NULL AFTER last_processed_at,
    ADD COLUMN db_reconnect_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_db_reconnect_at,
    ADD COLUMN last_db_error_message VARCHAR(500) NULL AFTER db_reconnect_count;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('financial_recalc.orders_per_run', '10', 'financial_recalc', 0),
('financial_recalc.batch_limit', '10', 'financial_recalc', 0),
('financial_recalc.billing_order_ids_per_request', '20', 'financial_recalc', 0),
('financial_recalc.pause_between_requests_ms', '800', 'financial_recalc', 0),
('financial_recalc.reconnect_between_steps', '1', 'financial_recalc', 0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.7.4', 'Recálculo financiero resiliente a cortes MySQL y pasos asistidos más pequeños.');
