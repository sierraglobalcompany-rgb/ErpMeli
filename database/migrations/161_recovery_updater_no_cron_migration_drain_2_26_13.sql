-- ERP Meli 2.26.13 - actualizador sin cron y migrador robusto
INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('runtime.recovery_external_backup_choice','1',0,'runtime'),
    ('runtime.migrator_drain_result_sets','1',0,'runtime'),
    ('backup.direct_update_external_waiver','RESPALDO EXTERNO CONFIRMADO',0,'backup'),
    ('backup.direct_update_secure_optional','1',0,'backup')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.13',
    'El actualizador permite usar respaldo externo sin cron y el migrador drena resultados para evitar bloqueos MariaDB 2014.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
