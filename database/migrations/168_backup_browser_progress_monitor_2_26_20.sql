-- ERP Meli 2.26.20 — monitor confiable de respaldo por navegador.
-- Metadata solamente: no modifica datos comerciales ni activa Mercado Libre.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group) VALUES
('backup.browser_progress_monitor_enabled','1',0,'backup'),
('backup.browser_progress_mode','browser',0,'backup'),
('backup.browser_eta_minimum_chunks','2',0,'backup'),
('backup.browser_stale_after_seconds','90',0,'backup')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version, notes, installed_at)
VALUES ('2.26.20','Monitor visual de copias por navegador con porcentaje, tabla actual, filas, fragmentos, tiempo y ETA aproximada.',UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
