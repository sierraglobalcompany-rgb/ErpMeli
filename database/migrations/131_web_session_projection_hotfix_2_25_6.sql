-- ERP Meli 2.25.6 — recuperación web después de diagnósticos pesados.
-- Solo cambia metadata operativa. No modifica datos comerciales ni consulta Mercado Libre.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('automation.web_projection_refresh_enabled','0','automation',0),
('automation.projection_refresh_cursor','0','automation',0),
('cron.status_details_on_demand','1','cron',0),
('performance.release_session_before_heavy_get','1','performance',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','131_web_session_projection_hotfix_2_25_6.sql','metadata',1),
('automation_center','131_web_session_projection_hotfix_2_25_6.sql','metadata',1)
ON DUPLICATE KEY UPDATE
required_migration=VALUES(required_migration),
contract_kind=VALUES(contract_kind),
enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.6','Recuperación web: sesiones liberadas y proyección de colas exclusiva del lanzador')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
