-- ERP Meli 2.27.0 — mantenimiento local unificado.
-- Solo agrega coordinación de protección para saneamiento local. No modifica
-- órdenes, cuentas, OAuth, pagos, packs, cierres ni evidencia fiscal.

ALTER TABLE database_maintenance_sessions
    ADD COLUMN IF NOT EXISTS protection_mode ENUM(
        'erp_backup','external_backup','waived'
    ) NULL AFTER backup_id,
    ADD COLUMN IF NOT EXISTS protection_status ENUM(
        'required','ready','canary_ready','canary_passed'
    ) NOT NULL DEFAULT 'required' AFTER protection_mode,
    ADD COLUMN IF NOT EXISTS protection_confirmed_by BIGINT UNSIGNED NULL
        AFTER protection_status,
    ADD COLUMN IF NOT EXISTS protection_confirmed_at DATETIME(3) NULL
        AFTER protection_confirmed_by,
    ADD COLUMN IF NOT EXISTS protection_note VARCHAR(500) NULL
        AFTER protection_confirmed_at,
    ADD COLUMN IF NOT EXISTS canary_status ENUM(
        'pending','running','passed','failed','not_required'
    ) NOT NULL DEFAULT 'pending' AFTER protection_note,
    ADD COLUMN IF NOT EXISTS canary_checked_at DATETIME(3) NULL
        AFTER canary_status;

SET @idx_database_maintenance_protection_exists = (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
       AND BINARY TABLE_NAME=BINARY 'database_maintenance_sessions'
       AND BINARY INDEX_NAME=BINARY 'idx_database_maintenance_protection'
);
SET @idx_database_maintenance_protection_sql = IF(
    @idx_database_maintenance_protection_exists=0,
    'ALTER TABLE database_maintenance_sessions ADD INDEX idx_database_maintenance_protection (protection_mode,protection_status,status,id)',
    'SELECT 1'
);
PREPARE idx_database_maintenance_protection_stmt FROM @idx_database_maintenance_protection_sql;
EXECUTE idx_database_maintenance_protection_stmt;
DEALLOCATE PREPARE idx_database_maintenance_protection_stmt;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('database_maintenance.unified_local_flow','1',0,'maintenance'),
    ('database_maintenance.allow_external_backup','1',0,'maintenance'),
    ('database_maintenance.allow_backup_waiver','1',0,'maintenance'),
    ('database_maintenance.canary_first_batch','1',0,'maintenance')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group),
    updated_at=UTC_TIMESTAMP();

INSERT INTO app_versions (version,notes)
VALUES (
    '2.27.0',
    'Mantenimiento local unificado: saneamiento con copia ERP, respaldo externo o renuncia explícita sin Cron ni frases repetitivas.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
