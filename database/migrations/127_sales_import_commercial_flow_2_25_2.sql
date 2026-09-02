-- ERP Meli 2.25.2 — flujo comercial de importacion anual de ventas ML.
-- Aditiva e idempotente. No modifica ventas existentes ni consulta Mercado Libre.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_import.default_scope','year','sales_import',0),
('sales_import.auto_repair_missing','1','sales_import',0),
('sales_import.auto_repair_dates','1','sales_import',0),
('sales_import.show_advanced_to_operators','0','sales_import',0),
('sales_import.remote_window_months_source','sales_control.remote_window_months','sales_import',0),
('sales_import.commercial_entry_enabled','1','sales_import',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES ('sales_import','127_sales_import_commercial_flow_2_25_2.sql','metadata',1)
ON DUPLICATE KEY UPDATE
required_migration=VALUES(required_migration),
contract_kind=VALUES(contract_kind),
enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.2','Flujo comercial para importar ventas anuales de Mercado Libre con evidencia avanzada')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
