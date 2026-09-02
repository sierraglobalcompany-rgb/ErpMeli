CREATE TABLE IF NOT EXISTS system_database_growth_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    captured_at DATETIME(3) NOT NULL DEFAULT UTC_TIMESTAMP(3),
    database_name_hash CHAR(64) NOT NULL,
    table_count INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
    data_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    index_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    free_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source ENUM('manual_cli','scheduled_cli','test') NOT NULL DEFAULT 'manual_cli',
    capture_version VARCHAR(30) NOT NULL,
    KEY idx_growth_snapshots_captured (captured_at),
    KEY idx_growth_snapshots_source (source, captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_database_growth_tables (
    snapshot_id BIGINT UNSIGNED NOT NULL,
    table_name VARCHAR(190) NOT NULL,
    data_class ENUM('commercial','fiscal','operational','event','log','temporary','technical','unknown') NOT NULL DEFAULT 'unknown',
    estimated_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
    data_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    index_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    free_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    average_row_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (snapshot_id, table_name),
    KEY idx_growth_tables_size (snapshot_id, data_bytes, index_bytes),
    KEY idx_growth_tables_class (data_class, snapshot_id),
    CONSTRAINT fk_growth_tables_snapshot
        FOREIGN KEY (snapshot_id) REFERENCES system_database_growth_snapshots(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_storage_producer_catalog (
    producer_key VARCHAR(120) PRIMARY KEY,
    dataset_key VARCHAR(120) NOT NULL,
    table_pattern VARCHAR(190) NOT NULL,
    service_class VARCHAR(190) NOT NULL,
    execution_source ENUM('webhook','cli','web','local_maintenance','mixed') NOT NULL,
    retention_class ENUM('commercial','fiscal','operational','success_15d','incident_90d','summary_12m','temporary','manual') NOT NULL,
    remote_transport_possible TINYINT(1) NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    KEY idx_storage_producers_dataset (dataset_key),
    KEY idx_storage_producers_table (table_pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_query_performance_rollups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_date DATE NOT NULL,
    route_hash CHAR(64) NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    duration_total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_max_ms INT UNSIGNED NOT NULL DEFAULT 0,
    statements_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    rows_read_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    temporary_tables_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    disk_temporary_tables_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    filesort_rows_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_query_rollup_date_route (metric_date, route_hash),
    KEY idx_query_rollup_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_storage_producer_catalog
    (producer_key,dataset_key,table_pattern,service_class,execution_source,retention_class,remote_transport_possible)
VALUES
    ('notification_events','notifications','meli_notification_events','App\\Services\\WebhookService','cli','success_15d',0),
    ('notification_work','notifications','meli_notification_work_items','App\\Services\\NotificationWorkItemService','cli','operational',1),
    ('api_requests','api','api_request_logs','App\\Services\\ApiGuardService','mixed','success_15d',1),
    ('cron_runs','automation','cron_health_checks','App\\Services\\CronHealthService','cli','success_15d',0),
    ('orders','sales','meli_orders','App\\Services\\OrderSyncService','cli','commercial',1),
    ('order_items','sales','meli_order_items','App\\Services\\OrderSyncService','cli','commercial',1),
    ('payments','sales','meli_payments','App\\Services\\OrderSyncService','cli','commercial',1),
    ('shipments','sales','meli_shipments','App\\Services\\OrderSyncService','cli','commercial',1),
    ('billing_details','finance','meli_order_billing_details','App\\Services\\OrderBillingImportService','cli','fiscal',1),
    ('financial_jobs','finance','order_financial_recalc_job_items','App\\Services\\OrderFinancialRecalcJobService','cli','incident_90d',1),
    ('manual_generations','campaigns','manual_%','App\\Services\\ManualCampaignService','cli','manual',1)
ON DUPLICATE KEY UPDATE
    dataset_key=VALUES(dataset_key),
    table_pattern=VALUES(table_pattern),
    service_class=VALUES(service_class),
    execution_source=VALUES(execution_source),
    retention_class=VALUES(retention_class),
    remote_transport_possible=VALUES(remote_transport_possible),
    enabled=1;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('database.growth_snapshot_retention_days','365',0,'maintenance'),
    ('database.growth_snapshot_min_interval_hours','24',0,'maintenance'),
    ('performance.query_profile_enabled','0',0,'performance'),
    ('performance.query_rollup_retention_days','365',0,'performance')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.18','Observabilidad local de crecimiento, productores, consultas y procesos sin limpiar datos.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
