-- ERP Meli 2.26.11 - gate definitivo de respaldo previo del actualizador
ALTER TABLE system_backup_archives
    MODIFY context_type ENUM('general','database_sanitation','direct_update')
        NOT NULL DEFAULT 'general';

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('backup.direct_update_requires_ready','1',0,'backup'),
    ('backup.direct_update_context','direct_update',0,'backup'),
    ('runtime.recovery_updater_backup_gate','2.26.11',0,'runtime')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.11',
    'Actualizador directo con respaldo previo recuperable, cancelable y separado de la identidad del administrador.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
