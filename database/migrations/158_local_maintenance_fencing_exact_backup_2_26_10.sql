-- ERP Meli 2.26.10 - coordinación local cercada y copia exacta de saneamiento
ALTER TABLE system_backup_archives
    MODIFY status ENUM(
        'prepared','queued','creating','verifying','ready_pending_release',
        'ready','failed','cancel_requested','deleting','deleted'
    ) NOT NULL DEFAULT 'prepared',
    ADD COLUMN IF NOT EXISTS cancel_requested_at DATETIME(3) NULL
        AFTER delete_requested_at,
    ADD COLUMN IF NOT EXISTS control_generation BIGINT UNSIGNED NOT NULL DEFAULT 0
        AFTER context_id,
    ADD COLUMN IF NOT EXISTS maintenance_phase VARCHAR(40) NULL
        AFTER control_generation,
    ADD COLUMN IF NOT EXISTS freeze_released_at DATETIME(3) NULL
        AFTER maintenance_phase;

ALTER TABLE system_backup_jobs
    MODIFY status ENUM(
        'pending','running','cancel_requested','completed','cancelled','failed'
    ) NOT NULL DEFAULT 'pending';

SET @backup_cancel_index_exists = (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
       AND BINARY TABLE_NAME=BINARY 'system_backup_archives'
       AND BINARY INDEX_NAME=BINARY 'idx_backup_archives_control'
);
SET @backup_cancel_index_sql = IF(
    @backup_cancel_index_exists=0,
    'ALTER TABLE system_backup_archives ADD INDEX idx_backup_archives_control (status,control_generation,heartbeat_at,id)',
    'SELECT 1'
);
PREPARE backup_cancel_index_stmt FROM @backup_cancel_index_sql;
EXECUTE backup_cancel_index_stmt;
DEALLOCATE PREPARE backup_cancel_index_stmt;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('backup.local_coordinator_version','2',0,'backup'),
    ('backup.require_exact_sanitation_context','1',0,'backup')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.10',
    'Coordinación local cercada, recuperación exacta y copia exclusiva por sesión de saneamiento.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
