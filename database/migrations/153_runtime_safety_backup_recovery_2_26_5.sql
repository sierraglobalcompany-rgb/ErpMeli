CREATE TABLE IF NOT EXISTS system_retention_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dataset_key VARCHAR(80) NOT NULL,
    status ENUM('planned','running','completed','partial','failed') NOT NULL
        DEFAULT 'planned',
    rows_reviewed BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rows_archived BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rows_deleted BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_released BIGINT UNSIGNED NOT NULL DEFAULT 0,
    cursor_value VARCHAR(255) NULL,
    summary_json JSON NULL,
    safe_message VARCHAR(500) NULL,
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    heartbeat_at DATETIME(3) NULL,
    completed_at DATETIME(3) NULL,
    KEY idx_retention_runs_dataset (dataset_key,started_at),
    KEY idx_retention_runs_status (status,heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La tabla conserva evidencia técnica de las ejecuciones de retención. No
-- contiene recursos importados que deban eliminarse al reiniciar datos de
-- Mercado Libre y debe quedar clasificada en el inventario cerrado.
INSERT INTO imported_data_reset_table_policy
    (table_name,action,policy_version,note)
VALUES (
    'system_retention_runs',
    'preserve',
    2,
    'Historial técnico de retención; se conserva para auditar saneamientos y no es un recurso importado.'
)
ON DUPLICATE KEY UPDATE
    action=VALUES(action),
    policy_version=VALUES(policy_version),
    note=VALUES(note);

ALTER TABLE system_backup_archives
    MODIFY format_version SMALLINT UNSIGNED NOT NULL DEFAULT 3;

ALTER TABLE database_maintenance_sessions
    MODIFY status ENUM(
        'analyzed','running','pausing','paused','finishing',
        'completed','finished','failed'
    ) NOT NULL DEFAULT 'analyzed',
    ADD COLUMN IF NOT EXISTS lease_owner CHAR(64) NULL
        AFTER control_expires_at,
    ADD COLUMN IF NOT EXISTS lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0
        AFTER lease_owner,
    ADD COLUMN IF NOT EXISTS lease_expires_at DATETIME(3) NULL
        AFTER lease_generation,
    ADD COLUMN IF NOT EXISTS lease_heartbeat_at DATETIME(3) NULL
        AFTER lease_expires_at;

ALTER TABLE database_maintenance_steps
    ADD COLUMN IF NOT EXISTS lease_owner CHAR(64) NULL
        AFTER generation;

SET @maintenance_lease_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'database_maintenance_sessions'
      AND BINARY INDEX_NAME=BINARY 'idx_database_maintenance_lease'
);
SET @maintenance_lease_index_sql = IF(
    @maintenance_lease_index_exists=0,
    'ALTER TABLE database_maintenance_sessions ADD INDEX idx_database_maintenance_lease (status,lease_expires_at,id)',
    'SELECT 1'
);
PREPARE maintenance_lease_index_stmt FROM @maintenance_lease_index_sql;
EXECUTE maintenance_lease_index_stmt;
DEALLOCATE PREPARE maintenance_lease_index_stmt;

SET @maintenance_step_lease_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'database_maintenance_steps'
      AND BINARY INDEX_NAME=BINARY 'idx_database_maintenance_step_lease'
);
SET @maintenance_step_lease_index_sql = IF(
    @maintenance_step_lease_index_exists=0,
    'ALTER TABLE database_maintenance_steps ADD INDEX idx_database_maintenance_step_lease (maintenance_session_id,lease_owner,generation,status)',
    'SELECT 1'
);
PREPARE maintenance_step_lease_index_stmt FROM @maintenance_step_lease_index_sql;
EXECUTE maintenance_step_lease_index_stmt;
DEALLOCATE PREPARE maintenance_step_lease_index_stmt;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('backup.current_format','3',0,'backup'),
    ('backup.chunk_row_limit','5000',0,'backup'),
    ('backup.chunk_byte_limit','8388608',0,'backup'),
    ('backup.chunk_deadline_seconds','15',0,'backup'),
    ('notifications.legacy_normalization_cursor_id','0',0,'notifications'),
    ('notifications.legacy_message_cursor_id','0',0,'notifications'),
    ('runtime.browser_remote_execution_enabled','0',0,'security'),
    ('runtime.single_launcher_required','1',0,'runtime'),
    ('update.allow_classic_overwrite','0',0,'updates')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.5',
    'Recuperación integral: paradas persistentes, respaldo v3 reanudable, saneamiento cercado y restauración segura.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
