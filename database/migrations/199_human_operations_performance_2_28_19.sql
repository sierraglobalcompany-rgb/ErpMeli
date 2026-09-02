-- ERP Meli 2.28.19 — experiencia humana y rendimiento operativo.
-- Solo registra defaults de presentación y contratos de lectura. No modifica
-- información comercial, OAuth, campañas, cierres ni evidencia fiscal.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('ui.operational_language_version','2',0,'ui'),
('ui.progressive_section_timeout_ms','8000',0,'ui'),
('ui.mobile_table_card_breakpoint_px','640',0,'ui'),
('ui.permanent_admin_review_enabled','1',0,'security'),
('ui.permanent_admin_inactive_review_days','60',0,'security'),
('cron.observed_throughput_window_minutes','60',0,'cron'),
('cron.eta_minimum_completed_resources','3',0,'cron'),
('app.version','2.28.19',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('automation_center','199_human_operations_performance_2_28_19.sql','metadata',1),
('api_health','199_human_operations_performance_2_28_19.sql','metadata',1),
('backup_center','199_human_operations_performance_2_28_19.sql','metadata',1),
('database_maintenance','199_human_operations_performance_2_28_19.sql','metadata',1),
('module_runtime','199_human_operations_performance_2_28_19.sql','metadata',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.19','Experiencia operativa humana, estados coherentes y rendimiento progresivo')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
