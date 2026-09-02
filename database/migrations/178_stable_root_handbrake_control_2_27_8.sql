-- ERP Meli 2.27.8 — freno de mano sobre raíz estable.
-- Solo metadata/flags operativos. No modifica datos comerciales.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('emergency_handbrake.stable_installation_root','1',0,'safety'),
    ('emergency_handbrake.stop_php_fallback_reads_installation_root','1',0,'safety'),
    ('emergency_handbrake.version','2.27.8',0,'safety')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.27.8',
    'Freno de mano lee y escribe siempre marcadores en la raíz estable de instalación, incluso desde releases administradas.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
