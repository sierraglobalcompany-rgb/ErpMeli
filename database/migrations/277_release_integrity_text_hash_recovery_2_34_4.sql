-- ERP Meli 2.34.4
-- Release completa: integridad tolerante a finales de línea para archivos
-- de texto declarados en el manifest. Solo metadata/versionado.

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('app.version','2.34.4','system',0),
('release_integrity.text_lf_hash_recovery','1','system',0),
('release_integrity.partial_packages_blocked','1','system',0)
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  setting_group = VALUES(setting_group),
  is_encrypted = VALUES(is_encrypted);

INSERT INTO app_versions (`version`, `notes`)
VALUES ('2.34.4', 'Release completa con verificación de componentes de texto tolerante a finales de línea, sin permitir paquetes parciales.')
ON DUPLICATE KEY UPDATE
  `notes` = VALUES(`notes`),
  installed_at = UTC_TIMESTAMP();
