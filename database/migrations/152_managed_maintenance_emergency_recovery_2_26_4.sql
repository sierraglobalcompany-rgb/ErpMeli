INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    (
        'backup.maintenance.shared_root_resolver',
        '1',
        0,
        'backup'
    ),
    (
        'emergency.panel.single_filesystem_authority',
        '1',
        0,
        'security'
    )
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.4',
    'Recupera el panel de emergencia y el carril exclusivo de copias en releases administradas.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
