-- ERP Meli 2.26.12 - actualizador con respaldo externo explícito
INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('runtime.recovery_external_backup_choice','1',0,'runtime'),
    ('backup.direct_update_external_waiver','RESPALDO EXTERNO CONFIRMADO',0,'backup'),
    ('backup.direct_update_secure_optional','1',0,'backup')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.12',
    'El actualizador no crea respaldos automáticamente después de confirmar identidad; permite usar una copia externa confirmada sin cron ni lanzador.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
