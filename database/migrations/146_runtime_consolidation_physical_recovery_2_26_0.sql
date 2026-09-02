SET @drop_redundant_shipment_index = IF(
    (
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE BINARY table_schema=BINARY DATABASE()
          AND table_name='meli_shipments'
          AND index_name='idx_shipments_account_estimated_2_6_3'
    )>0,
    'ALTER TABLE meli_shipments DROP INDEX idx_shipments_account_estimated_2_6_3',
    'SELECT 1'
);
PREPARE stmt_drop_redundant_shipment_index FROM @drop_redundant_shipment_index;
EXECUTE stmt_drop_redundant_shipment_index;
DEALLOCATE PREPARE stmt_drop_redundant_shipment_index;

DROP TABLE IF EXISTS system_retention_runs;

ALTER TABLE system_cold_archives
    MODIFY dataset_key ENUM(
        'notification_events',
        'api_request_logs',
        'cron_health_checks',
        'financial_job_items'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS system_table_maintenance_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(190) NOT NULL,
    action_key ENUM('inspect','archive','rebuild') NOT NULL,
    status ENUM('planned','completed','failed','not_required') NOT NULL,
    data_bytes_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
    index_bytes_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
    free_bytes_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
    data_bytes_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
    index_bytes_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
    free_bytes_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
    safe_message VARCHAR(500) NULL,
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    completed_at DATETIME(3) NULL,
    KEY idx_table_maintenance_table (table_name,started_at),
    KEY idx_table_maintenance_status (status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('cron.single_launcher_required','1',0,'cron'),
    ('cron.legacy_job_execution_enabled','0',0,'cron'),
    ('maintenance.physical_rebuild_automatic','0',0,'maintenance'),
    ('maintenance.minimum_rebuild_free_bytes','10485760',0,'maintenance'),
    ('runtime.browser_remote_execution_enabled','0',0,'security')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.26.0','Un solo lanzador, trabajos heredados inactivos y recuperación física controlada.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
