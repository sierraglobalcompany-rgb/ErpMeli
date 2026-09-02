CREATE TABLE IF NOT EXISTS meli_sync_offsets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sync_type VARCHAR(80) NOT NULL,
    cursor_value TEXT NULL,
    last_resource_id VARCHAR(120) NULL,
    last_synced_at DATETIME NULL,
    status ENUM('pending','running','complete','error') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sync_offset (meli_account_id, sync_type),
    CONSTRAINT fk_sync_offsets_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('sync.products.page_limit', '50', 'sync', 0),
('sync.products.max_items_per_run', '500', 'sync', 0),
('sync.claims.page_limit', '30', 'sync', 0),
('sync.claims.max_claims_per_run', '200', 'sync', 0),
('reports.default_include_returns', '0', 'reports', 0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.1.0', 'Multicuenta, bodega, reportes, packs, envíos, pagos, reclamos y alertas');
