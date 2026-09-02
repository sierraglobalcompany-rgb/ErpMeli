-- ERP Meli 2.27.9 — readiness del freno de mano con configuración compartida.
-- Solo metadata/flags operativos. No modifica datos comerciales.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('emergency_handbrake.shared_config_readiness','1',0,'safety'),
    ('emergency_handbrake.env_load_uses_app_paths_config','1',0,'safety'),
    ('emergency_handbrake.version','2.27.9',0,'safety')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.27.9',
    'El panel de emergencia carga la configuración desde AppPaths::configFile para instalaciones administradas con shared/config.env.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
