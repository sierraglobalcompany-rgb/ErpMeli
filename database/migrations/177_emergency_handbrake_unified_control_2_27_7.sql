-- ERP Meli 2.27.7 — freno de mano unificado.
-- Solo metadata/flags operativos. No modifica datos comerciales.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('emergency_handbrake.version','2.27.7',0,'safety'),
    ('emergency_handbrake.clear_local_maintenance','1',0,'safety'),
    ('emergency_handbrake.start_local_site_keeps_api_stopped','1',0,'safety'),
    ('emergency_handbrake.automation_start_does_not_require_remote_readiness','1',0,'safety'),
    ('emergency_handbrake.panel_shows_local_read_only_state','1',0,'safety')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.27.7',
    'Freno de mano unificado: activar sitio local, retirar modo lectura local y mantener Mercado Libre bloqueado hasta canario explícito.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
