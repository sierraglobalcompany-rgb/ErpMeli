-- ERP Meli 2.11.2 — Rendimiento progresivo, medición y worker guiado.
-- Aditiva, idempotente y sin cambios en datos comerciales.

CREATE TABLE IF NOT EXISTS system_performance_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source ENUM('server','client') NOT NULL,
    metric_name VARCHAR(80) NOT NULL,
    metric_value DECIMAL(18,3) NOT NULL DEFAULT 0,
    route_path VARCHAR(190) NULL,
    section_name VARCHAR(80) NULL,
    cache_status VARCHAR(30) NULL,
    user_id BIGINT UNSIGNED NULL,
    user_role VARCHAR(30) NULL,
    context_json JSON NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_performance_metric_time (metric_name,recorded_at),
    KEY idx_performance_route_time (route_path,recorded_at),
    KEY idx_performance_recorded (recorded_at),
    CONSTRAINT fk_performance_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @idx_payments_order_status_date = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_payments' AND INDEX_NAME='idx_payments_order_status_date'
);
SET @sql_payments_order_status_date = IF(
    @idx_payments_order_status_date=0,
    'ALTER TABLE meli_payments ADD INDEX idx_payments_order_status_date (meli_order_id,status,date_approved)',
    'SELECT 1'
);
PREPARE stmt_payments_order_status_date FROM @sql_payments_order_status_date;
EXECUTE stmt_payments_order_status_date;
DEALLOCATE PREPARE stmt_payments_order_status_date;

SET @idx_shipments_order_updated = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_shipments' AND INDEX_NAME='idx_shipments_order_updated'
);
SET @sql_shipments_order_updated = IF(
    @idx_shipments_order_updated=0,
    'ALTER TABLE meli_shipments ADD INDEX idx_shipments_order_updated (meli_order_id,updated_at)',
    'SELECT 1'
);
PREPARE stmt_shipments_order_updated FROM @sql_shipments_order_updated;
EXECUTE stmt_shipments_order_updated;
DEALLOCATE PREPARE stmt_shipments_order_updated;

SET @idx_shipments_account_synced = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_shipments' AND INDEX_NAME='idx_shipments_account_synced'
);
SET @sql_shipments_account_synced = IF(
    @idx_shipments_account_synced=0,
    'ALTER TABLE meli_shipments ADD INDEX idx_shipments_account_synced (meli_account_id,synced_at,id)',
    'SELECT 1'
);
PREPARE stmt_shipments_account_synced FROM @sql_shipments_account_synced;
EXECUTE stmt_shipments_account_synced;
DEALLOCATE PREPARE stmt_shipments_account_synced;

SET @idx_items_account_synced = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_items' AND INDEX_NAME='idx_items_account_synced'
);
SET @sql_items_account_synced = IF(
    @idx_items_account_synced=0,
    'ALTER TABLE meli_items ADD INDEX idx_items_account_synced (meli_account_id,synced_at,id)',
    'SELECT 1'
);
PREPARE stmt_items_account_synced FROM @sql_items_account_synced;
EXECUTE stmt_items_account_synced;
DEALLOCATE PREPARE stmt_items_account_synced;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('performance.async_sections_enabled','1','performance',0),
('performance.dashboard_progressive','1','performance',0),
('performance.orders_progressive','1','performance',0),
('performance.shipments_progressive','1','performance',0),
('performance.products_progressive','1','performance',0),
('performance.catalog_admin_progressive','1','performance',0),
('performance.default_page_size','50','performance',0),
('performance.section_skeleton_delay_ms','150','performance',0),
('performance.section_progress_message_ms','2000','performance',0),
('performance.section_slow_message_ms','12000','performance',0),
('performance.section_timeout_ms','30000','performance',0),
('performance.read_model_cache_seconds','20','performance',0),
('performance.normal_sample_percent','10','performance',0),
('performance.metrics_retention_days','30','performance',0),
('performance.query_list_budget_ms','800','performance',0),
('performance.query_count_budget_ms','400','performance',0),
('performance.query_widget_budget_ms','300','performance',0),
('notifications.assisted_worker_enabled','1','notifications',0),
('notifications.assisted_batch_limit','5','notifications',0)
ON DUPLICATE KEY UPDATE
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.11.2', 'Rendimiento progresivo, navegación inmediata, paginación y worker operativo.');
