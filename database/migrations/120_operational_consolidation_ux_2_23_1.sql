-- ERP Meli 2.23.1 — consolidación operativa, UX y contratos de release.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.legacy_read_only','1','manual_campaign',0),
('manual_campaign.interactive_enabled','0','manual_campaign',0),
('automation.default_view','running','automation',0),
('automation.single_launcher_help','1','automation',0),
('sales_control.compact_month_rows','1','sales_control',0),
('sales_control.context_help_delay_ms','2000','sales_control',0),
('quality.require_mariadb_release_dsn','1','quality',0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','120_operational_consolidation_ux_2_23_1.sql','structural',1),
('sales_control','120_operational_consolidation_ux_2_23_1.sql','structural',1),
('automation_center','120_operational_consolidation_ux_2_23_1.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),enabled=1;

INSERT INTO app_versions (version,notes)
VALUES ('2.23.1','Consolidación operativa, UX humana, documentación y garantía de calidad')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
