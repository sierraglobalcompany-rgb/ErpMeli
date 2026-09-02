-- ERP Meli 2.28.42 — ritmo HTTP real por ventana rodante.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('api.rhythm.rolling_window_authority','1',0,'api'),
('api.rhythm.return_unused_permits','1',0,'api'),
('api.rhythm.known_http_preserves_result','1',0,'api');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.42',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO system_component_schema_contracts (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','222_http_rhythm_rolling_window_2_28_42.sql','metadata',1),
('api_client','222_http_rhythm_rolling_window_2_28_42.sql','metadata',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.42','Ritmo HTTP por ventana rodante y permisos devueltos cuando no inicia transporte')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
