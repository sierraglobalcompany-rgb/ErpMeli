-- ERP Meli 2.28.22 — read models progresivos para módulos operativos.
-- Solo registra contratos de presentación. No modifica órdenes, pagos,
-- campañas, cuentas, OAuth, cierres ni evidencia fiscal.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('ui.operations_progressive_read_models','1',0,'ui'),
('ui.operations_section_timeout_ms','8000',0,'ui'),
('ui.operations_parallel_sections','2',0,'ui'),
('app.version','2.28.22',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('operations_progressive_reads','202_progressive_human_modules_2_28_22.sql','metadata',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.22','Read models progresivos para Rentabilidad, Finanzas, Sincronización, Notificaciones y Control de ventas')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
