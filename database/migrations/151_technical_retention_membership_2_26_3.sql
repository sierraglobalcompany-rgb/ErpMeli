ALTER TABLE system_cold_archives
    MODIFY dataset_key ENUM(
        'notification_events',
        'notification_success',
        'notification_incidents',
        'api_request_logs',
        'cron_health_checks',
        'financial_job_items',
        'cron_run_steps',
        'process_metrics',
        'work_queue_items',
        'work_queue_runs',
        'api_budget_windows',
        'manual_probe_runs',
        'performance_metrics',
        'system_logs',
        'api_operation_samples',
        'webhook_events'
    ) NOT NULL,
    ADD COLUMN IF NOT EXISTS membership_verified_at DATETIME(3) NULL
        AFTER verified_at;

CREATE TABLE IF NOT EXISTS system_cold_archive_memberships (
    archive_id BIGINT UNSIGNED NOT NULL,
    source_table VARCHAR(100) NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    row_sha256 CHAR(64) NOT NULL,
    archived_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    source_deleted_at DATETIME(3) NULL,
    stale_at DATETIME(3) NULL,
    verification_error VARCHAR(80) NULL,
    PRIMARY KEY (archive_id,source_table,source_id),
    KEY idx_archive_membership_source (source_table,source_id,archive_id),
    KEY idx_archive_membership_live (
        archive_id,source_deleted_at,stale_at
    ),
    CONSTRAINT fk_archive_membership_archive
        FOREIGN KEY (archive_id) REFERENCES system_cold_archives(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE system_cold_archive_memberships
    ADD COLUMN IF NOT EXISTS source_deleted_at DATETIME(3) NULL
        AFTER archived_at,
    ADD COLUMN IF NOT EXISTS stale_at DATETIME(3) NULL
        AFTER source_deleted_at,
    ADD COLUMN IF NOT EXISTS verification_error VARCHAR(80) NULL
        AFTER stale_at;

SET @retention_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'system_cold_archive_memberships'
      AND BINARY INDEX_NAME=BINARY 'idx_archive_membership_live'
);
SET @retention_index_sql = IF(
    @retention_index_exists=0,
    'ALTER TABLE system_cold_archive_memberships
     ADD INDEX idx_archive_membership_live (archive_id,source_deleted_at,stale_at)',
    'SELECT 1'
);
PREPARE retention_index_stmt FROM @retention_index_sql;
EXECUTE retention_index_stmt;
DEALLOCATE PREPARE retention_index_stmt;

CREATE TABLE IF NOT EXISTS system_technical_daily_rollups (
    rollup_date DATE NOT NULL,
    dataset_key VARCHAR(80) NOT NULL,
    dimension_key VARCHAR(120) NOT NULL DEFAULT '',
    outcome_class VARCHAR(80) NOT NULL DEFAULT '',
    records BIGINT UNSIGNED NOT NULL DEFAULT 0,
    error_records BIGINT UNSIGNED NOT NULL DEFAULT 0,
    remote_records BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (rollup_date,dataset_key,dimension_key,outcome_class),
    KEY idx_technical_rollup_retention (rollup_date,dataset_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @retention_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'system_cron_run_steps'
      AND BINARY INDEX_NAME=BINARY 'idx_cron_steps_retention'
);
SET @retention_index_sql = IF(
    @retention_index_exists=0,
    'ALTER TABLE system_cron_run_steps ADD INDEX idx_cron_steps_retention (created_at,id)',
    'SELECT 1'
);
PREPARE retention_index_stmt FROM @retention_index_sql;
EXECUTE retention_index_stmt;
DEALLOCATE PREPARE retention_index_stmt;

SET @retention_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'system_work_queue_runs'
      AND BINARY INDEX_NAME=BINARY 'idx_work_queue_runs_retention'
);
SET @retention_index_sql = IF(
    @retention_index_exists=0,
    'ALTER TABLE system_work_queue_runs
     ADD INDEX idx_work_queue_runs_retention (created_at,id)',
    'SELECT 1'
);
PREPARE retention_index_stmt FROM @retention_index_sql;
EXECUTE retention_index_stmt;
DEALLOCATE PREPARE retention_index_stmt;

SET @retention_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'system_process_metrics'
      AND BINARY INDEX_NAME=BINARY 'idx_process_metrics_retention'
);
SET @retention_index_sql = IF(
    @retention_index_exists=0,
    'ALTER TABLE system_process_metrics ADD INDEX idx_process_metrics_retention (measured_at,id)',
    'SELECT 1'
);
PREPARE retention_index_stmt FROM @retention_index_sql;
EXECUTE retention_index_stmt;
DEALLOCATE PREPARE retention_index_stmt;

SET @retention_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'system_work_queue_run_items'
      AND BINARY INDEX_NAME=BINARY 'idx_work_run_items_retention'
);
SET @retention_index_sql = IF(
    @retention_index_exists=0,
    'ALTER TABLE system_work_queue_run_items ADD INDEX idx_work_run_items_retention (created_at,id)',
    'SELECT 1'
);
PREPARE retention_index_stmt FROM @retention_index_sql;
EXECUTE retention_index_stmt;
DEALLOCATE PREPARE retention_index_stmt;

SET @retention_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'api_budget_windows'
      AND BINARY INDEX_NAME=BINARY 'idx_api_budget_retention'
);
SET @retention_index_sql = IF(
    @retention_index_exists=0,
    'ALTER TABLE api_budget_windows ADD INDEX idx_api_budget_retention (window_started_at,id)',
    'SELECT 1'
);
PREPARE retention_index_stmt FROM @retention_index_sql;
EXECUTE retention_index_stmt;
DEALLOCATE PREPARE retention_index_stmt;

SET @retention_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'manual_engine_probe_runs'
      AND BINARY INDEX_NAME=BINARY 'idx_manual_probe_retention'
);
SET @retention_index_sql = IF(
    @retention_index_exists=0,
    'ALTER TABLE manual_engine_probe_runs ADD INDEX idx_manual_probe_retention (started_at,id)',
    'SELECT 1'
);
PREPARE retention_index_stmt FROM @retention_index_sql;
EXECUTE retention_index_stmt;
DEALLOCATE PREPARE retention_index_stmt;

SET @retention_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'api_operation_metric_samples'
      AND BINARY INDEX_NAME=BINARY 'idx_api_metric_retention'
);
SET @retention_index_sql = IF(
    @retention_index_exists=0,
    'ALTER TABLE api_operation_metric_samples ADD INDEX idx_api_metric_retention (created_at,id)',
    'SELECT 1'
);
PREPARE retention_index_stmt FROM @retention_index_sql;
EXECUTE retention_index_stmt;
DEALLOCATE PREPARE retention_index_stmt;

SET @retention_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
      AND BINARY TABLE_NAME=BINARY 'meli_webhook_events'
      AND BINARY INDEX_NAME=BINARY 'idx_webhook_retention'
);
SET @retention_index_sql = IF(
    @retention_index_exists=0,
    'ALTER TABLE meli_webhook_events ADD INDEX idx_webhook_retention (received_at,id)',
    'SELECT 1'
);
PREPARE retention_index_stmt FROM @retention_index_sql;
EXECUTE retention_index_stmt;
DEALLOCATE PREPARE retention_index_stmt;

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.3',
    'Retención técnica verificable por fila, resúmenes visibles y crecimiento acotado.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);

-- La 150 congela un inventario cerrado. Estas tablas nacen después de ese
-- inventario y deben clasificarse expresamente; no se adopta ningún futuro
-- conjunto de forma automática.
INSERT INTO imported_data_reset_table_policy (table_name,action,policy_version,note)
VALUES
    (
        'system_cold_archive_memberships',
        'preserve',
        1,
        'Membresía auditable de archivos técnicos; no corresponde a datos importados de Mercado Libre.'
    ),
    (
        'system_technical_daily_rollups',
        'preserve',
        1,
        'Resumen técnico retenido para diagnóstico; no contiene recursos comerciales reiniciables.'
    )
ON DUPLICATE KEY UPDATE
    action=VALUES(action),
    policy_version=VALUES(policy_version),
    note=VALUES(note);
