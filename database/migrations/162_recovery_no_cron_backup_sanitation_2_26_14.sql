-- ERP Meli 2.26.14 - actualizador, copias y saneamiento sin cron obligatorio
INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('runtime.recovery_external_backup_choice','1',0,'runtime'),
    ('runtime.recovery_external_ignores_internal_backup','1',0,'runtime'),
    ('backup.browser_interactive_enabled','1',0,'backup'),
    ('backup.browser_step_row_limit','500',0,'backup'),
    ('backup.browser_step_deadline_seconds','2',0,'backup'),
    ('database_maintenance.browser_interactive_enabled','1',0,'maintenance'),
    ('database_maintenance.browser_step_row_limit','500',0,'maintenance'),
    ('database_maintenance.browser_step_deadline_seconds','2',0,'maintenance')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.14',
    'El actualizador respeta respaldo externo aunque existan copias internas pendientes; copias y saneamiento avanzan por micro-lotes de navegador sin cron ni Mercado Libre.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
