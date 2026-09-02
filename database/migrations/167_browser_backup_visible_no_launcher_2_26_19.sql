-- ERP Meli 2.26.19 — copias visibles por navegador sin dependencia de lanzador.
-- Metadata solamente: no modifica datos comerciales ni activa Mercado Libre.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group) VALUES
('backup.browser_status_without_launcher','1',0,'backup'),
('backup.browser_first_step_immediate','1',0,'backup'),
('backup.hide_launcher_wording_for_browser_backups','1',0,'backup')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version, notes, installed_at)
VALUES ('2.26.19','Las copias por navegador inician el primer micro-lote inmediatamente y ya no se muestran como dependientes de Cron o lanzador.',UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
