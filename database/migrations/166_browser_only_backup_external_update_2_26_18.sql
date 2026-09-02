-- ERP Meli 2.26.18 — copias por navegador sin cron y respaldo externo siempre accesible.
-- Metadata solamente: no modifica datos comerciales ni activa Mercado Libre.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group) VALUES
('backup.browser_mode_without_cli_signal','1',0,'backup'),
('backup.direct_update_external_choice_visible_with_pending_internal','1',0,'backup'),
('backup.default_creation_mode','browser',0,'backup')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version, notes, installed_at)
VALUES ('2.26.18','Las copias creadas desde navegador no publican señal para Cron y el actualizador permite respaldo externo aunque exista una copia interna pendiente.',UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
