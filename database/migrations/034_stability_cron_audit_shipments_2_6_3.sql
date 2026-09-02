-- ERP Meli / Gestion Pro 2.6.3
-- Estabilizacion: auditoria exacta, cron, envios y visibilidad operativa.

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('sync.audit.exact_required_for_billing', '1', 'sync', 0),
('shipments.default_recent_days', '30', 'shipping', 0),
('shipments.default_list_limit', '200', 'shipping', 0),
('cron.health_stale_minutes', '15', 'sync', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

ALTER TABLE meli_shipments
    ADD KEY idx_shipments_synced_2_6_3 (synced_at),
    ADD KEY idx_shipments_estimated_2_6_3 (estimated_delivery),
    ADD KEY idx_shipments_account_synced_2_6_3 (meli_account_id, synced_at),
    ADD KEY idx_shipments_account_estimated_2_6_3 (meli_account_id, estimated_delivery);

ALTER TABLE sync_sales_audit_remote_ids
    ADD KEY idx_audit_remote_classification_2_6_3 (classification, checked_at);

ALTER TABLE sync_sales_audit_days
    ADD KEY idx_audit_days_consistency_2_6_3 (audit_consistency_status, audit_date);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.6.3', 'Estabiliza auditoria exacta de ventas, optimiza envios iniciales, limpia textos visibles y refuerza diagnostico de cron.');
