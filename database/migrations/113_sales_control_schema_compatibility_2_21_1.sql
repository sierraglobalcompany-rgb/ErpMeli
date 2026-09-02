-- ERP Meli 2.21.1 — compatibilidad del Control de ventas con el esquema real.
-- No agrega deleted_at a meli_accounts ni modifica datos comerciales.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_control.schema_contract_enabled','1','sales_control',0),
('sales_control.account_history_visible_when_disconnected','1','sales_control',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.21.1','Compatibilidad de cuentas, contrato de esquema y cola de auditoría corregida');
