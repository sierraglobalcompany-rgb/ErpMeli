-- ERP Meli 2.26.9 - ciclo de vida recuperable de copias y puente de saneamiento
ALTER TABLE system_backup_archives
    MODIFY status ENUM(
        'prepared','queued','creating','verifying','ready_pending_release',
        'ready','failed','deleting','deleted'
    ) NOT NULL DEFAULT 'prepared',
    ADD COLUMN IF NOT EXISTS delete_requested_at DATETIME(3) NULL AFTER expires_at,
    ADD COLUMN IF NOT EXISTS delete_requested_by BIGINT UNSIGNED NULL AFTER delete_requested_at,
    ADD COLUMN IF NOT EXISTS delete_not_before_at DATETIME(3) NULL AFTER delete_requested_by,
    ADD COLUMN IF NOT EXISTS context_type ENUM('general','database_sanitation')
        NOT NULL DEFAULT 'general' AFTER delete_not_before_at,
    ADD COLUMN IF NOT EXISTS context_id BIGINT UNSIGNED NULL AFTER context_type;

ALTER TABLE system_backup_jobs
    MODIFY job_type ENUM('create','verify','cleanup') NOT NULL,
    MODIFY status ENUM('pending','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS available_at DATETIME(3) NULL AFTER lease_expires_at;

SET @backup_cleanup_index_exists = (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
       AND BINARY TABLE_NAME=BINARY 'system_backup_jobs'
       AND BINARY INDEX_NAME=BINARY 'idx_backup_jobs_available'
);
SET @backup_cleanup_index_sql = IF(
    @backup_cleanup_index_exists=0,
    'ALTER TABLE system_backup_jobs ADD INDEX idx_backup_jobs_available (status,available_at,lease_expires_at,created_at)',
    'SELECT 1'
);
PREPARE backup_cleanup_index_stmt FROM @backup_cleanup_index_sql;
EXECUTE backup_cleanup_index_stmt;
DEALLOCATE PREPARE backup_cleanup_index_stmt;

SET @backup_context_index_exists = (
    SELECT COUNT(*)
      FROM information_schema.STATISTICS
     WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
       AND BINARY TABLE_NAME=BINARY 'system_backup_archives'
       AND BINARY INDEX_NAME=BINARY 'idx_backup_archives_context'
);
SET @backup_context_index_sql = IF(
    @backup_context_index_exists=0,
    'ALTER TABLE system_backup_archives ADD INDEX idx_backup_archives_context (context_type,context_id,status)',
    'SELECT 1'
);
PREPARE backup_context_index_stmt FROM @backup_context_index_sql;
EXECUTE backup_context_index_stmt;
DEALLOCATE PREPARE backup_context_index_stmt;

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.9',
    'Ciclo de vida seguro de copias: recuperación de señal, cancelación cercada y limpieza reanudable.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
