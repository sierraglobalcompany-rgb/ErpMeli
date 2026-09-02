-- ERP Meli 2.28.59 — recuperación del detalle de ventas.
-- Metadata/versionado únicamente. El runtime deja de leer currency_id desde meli_order_items.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('sales.detail.item_currency_source','orders.currency_id',0,'sales'),
('sales.detail.safe_diagnostic_lookup','1',0,'support');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.59',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES
('sales_detail','239_sales_detail_item_currency_recovery_2_28_59.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.59','Hotfix: ventas abren sin depender de currency_id inexistente en meli_order_items')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
