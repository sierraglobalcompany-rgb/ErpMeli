-- ERP Meli 2.10.0 — Navegación orientada a tareas y configuración humana.
-- Aditiva e idempotente. No modifica datos comerciales ni integraciones.

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('ui.navigation.task_oriented','1','ui',0),
    ('ui.settings.section_saves','1','ui',0),
    ('ui.technical_details_collapsed','1','ui',0),
    ('ui.responsive_tables_as_cards','1','ui',0),
    ('ui.accessibility.minimum_target_px','44','ui',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.10.0', 'Navegación por tareas, configuración por secciones y vistas guiadas para salud API, cron, diagnóstico y actualizaciones.');
