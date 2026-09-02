-- ERP Meli 2.28.53 — ritmo HTTP sin permiso global destructivo.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('api.rhythm.release_if_http_not_started','1',0,'api_rhythm'),
('api.rhythm.known_http_never_uncertain_by_cleanup','1',0,'api_rhythm'),
('api.rhythm.report_blocking_scope','1',0,'api_rhythm');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.53',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.53','Permisos HTTP compensables y diagnóstico de rhythm/account/endpoint/deadline')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
