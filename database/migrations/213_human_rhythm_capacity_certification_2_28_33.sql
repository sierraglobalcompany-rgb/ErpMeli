-- ERP Meli 2.28.33 — configuración humana y contrato de capacidad Cron.
-- Solo agrega defaults y metadata. No modifica campañas, órdenes, pagos,
-- cuentas, credenciales, OAuth, cierres ni evidencia fiscal.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('api.rhythm.human_profiles_enabled','1',0,'api_rhythm'),
('api.rhythm.capacity_protocol_version','1',0,'api_rhythm'),
('cron.explicit_counter_units_enabled','1',0,'cron'),
('cron.human_history_labels_enabled','1',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.33',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('automation_center','213_human_rhythm_capacity_certification_2_28_33.sql','structural',1),
('manual_processing','213_human_rhythm_capacity_certification_2_28_33.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.33','Perfiles Cron 10/20/30/40, capacidad observable y unidades operativas explícitas')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
