INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('update.identity_confirmation_mode','password_or_fresh_login',0,'updates'),
    ('update.identity_confirmation_ttl_seconds','900',0,'updates')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.7',
    'El actualizador distingue contraseña, sesión y origen, y permite confirmar identidad mediante el inicio de sesión normal.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
