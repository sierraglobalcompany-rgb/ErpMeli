INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('update.pre_update_backup_mode','resumable_cli_v3',0,'updates'),
    ('update.pre_update_backup_reuse_hours','24',0,'updates')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.6',
    'El actualizador separa la contraseña del respaldo y prepara copias V3 reanudables mediante CLI.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
