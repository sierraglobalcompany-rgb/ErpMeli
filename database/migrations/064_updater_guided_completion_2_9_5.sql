-- ERP Meli 2.9.5 — Actualizador guiado y cierre secuencial de migraciones.
-- Aditiva e idempotente. No modifica datos comerciales.

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('ui.update.guided_mode','1','ui',0),
    ('ui.update.advanced_collapsed','1','ui',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.9.5', 'Actualizador guiado con acción persistente para completar todas las migraciones seguras pendientes.');
