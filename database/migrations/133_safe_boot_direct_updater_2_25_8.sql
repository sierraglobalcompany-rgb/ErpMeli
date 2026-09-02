-- ERP Meli 2.25.8 — arranque web degradable y actualizador directo.
-- Metadata corta. No modifica órdenes, pagos, productos, campañas ni datos fiscales.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('runtime.web_connect_timeout_seconds','3','performance',0),
('runtime.web_query_timeout_seconds','5','performance',0),
('runtime.direct_updater_required','1','update',0),
('runtime.nonblocking_file_telemetry','1','performance',0),
('runtime.dashboard_shell_async','1','performance',0),
('notifications.receiver_spool_before_modules','1','notifications',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.8','Arranque degradable, actualizador directo y recuperación local con API bloqueada')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
