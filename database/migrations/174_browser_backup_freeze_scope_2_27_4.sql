-- ERP Meli 2.27.4 — copias por navegador independientes de Cron.
-- Solo defaults/metadata de UI y coordinación. No modifica datos comerciales.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('backup.browser_freeze_scope','owner_visible_cancelable',0,'backup'),
    ('backup.browser_progress_extended','1',0,'backup'),
    ('backup.browser_step_max_rows','500',0,'backup'),
    ('backup.browser_step_max_seconds','2',0,'backup'),
    ('backup.browser_never_publishes_cli_marker','1',0,'backup'),
    ('backup.browser_status_preserve_nonzero_progress','1',0,'backup')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.27.4',
    'Copias por navegador: progreso extendido, freeze accionable y cero dependencia de Cron/lanzador para crear o verificar.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
