-- ERP Meli 2.28.27 — Salud API con alcance y protocolo verificables.
-- Solo registra contratos técnicos y valores por defecto. No modifica datos
-- comerciales, cuentas, OAuth, campañas, pagos, cierres ni evidencia fiscal.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('api.health.incident_protocol_version','2',0,'api_health'),
('api.health.remote_evidence_recent_seconds','900',0,'api_health'),
('api.health.remote_evidence_stale_seconds','3600',0,'api_health'),
('api.health.redacted_application_pause','1',0,'api_health');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.27',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('api_health','207_api_health_scope_protocol_2_28_27.sql','structural',1),
('api_guard','207_api_health_scope_protocol_2_28_27.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.27','Salud API con pausa global redactada, incidentes scoped y lecturas parciales honestas')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
