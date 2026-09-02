-- ERP Meli 2.27.1 — copias por navegador aisladas de Cron y saneamiento desbloqueado.
-- No modifica datos comerciales, órdenes, cuentas, OAuth, pagos, packs, cierres ni evidencia fiscal.

ALTER TABLE system_backup_archives
    ADD COLUMN IF NOT EXISTS execution_mode ENUM('browser','cli')
        NOT NULL DEFAULT 'browser' AFTER context_id;

UPDATE system_backup_archives
   SET execution_mode='browser'
 WHERE execution_mode IS NULL OR execution_mode='';

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('backup.browser_execution_mode','1',0,'backup'),
    ('backup.browser_never_requires_cron','1',0,'backup'),
    ('database_maintenance.protect_allowed_during_snapshot','1',0,'maintenance')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.27.1',
    'Desbloqueo real de copias por navegador y protección de saneamiento sin respaldo interno.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
