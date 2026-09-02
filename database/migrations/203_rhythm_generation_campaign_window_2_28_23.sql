-- ERP Meli 2.28.23 — generación del ritmo y ventana dirigida coherente.
-- Solo agrega defaults operativos. No modifica datos comerciales ni credenciales.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('api.rhythm.generation_fence_enabled','1',0,'api_rhythm'),
('cron.directed_operation_reserve_seconds','15',0,'cron'),
('cron.directed_lane_guard_ms','1500',0,'cron'),
('app.version','2.28.23',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','203_rhythm_generation_campaign_window_2_28_23.sql','metadata',1),
('manual_campaign','203_rhythm_generation_campaign_window_2_28_23.sql','metadata',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.23','Permisos remotos cercados y ventana dirigida suficiente antes de iniciar HTTP')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
