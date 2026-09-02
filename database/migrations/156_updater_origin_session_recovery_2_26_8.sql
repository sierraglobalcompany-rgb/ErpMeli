INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('update.canonical_origin_mode','app_url',0,'updates'),
    ('update.csrf_legacy_session_recovery','enabled',0,'updates'),
    ('update.same_origin_referrer_policy','same-origin',0,'updates')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.8',
    'Corrige el origen público detrás de Hostinger y persiste CSRF antes de liberar sesiones heredadas del actualizador.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
