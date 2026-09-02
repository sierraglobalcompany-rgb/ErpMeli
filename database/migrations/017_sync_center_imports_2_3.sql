CREATE TABLE IF NOT EXISTS sync_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sync_type VARCHAR(80) NOT NULL DEFAULT 'orders',
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    chunk_mode ENUM('weekly','parts') NOT NULL DEFAULT 'weekly',
    chunk_parts INT UNSIGNED NULL,
    date_from DATETIME NOT NULL,
    date_to DATETIME NOT NULL,
    status ENUM('draft','queued','running','partial','complete','error','cancelled') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sync_batch_period (meli_account_id, sync_type, period_year, period_month, chunk_mode, chunk_parts),
    KEY idx_sync_batches_status (status, meli_account_id, period_year, period_month),
    CONSTRAINT fk_sync_batches_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_batches_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_batch_chunks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_batch_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sync_type VARCHAR(80) NOT NULL DEFAULT 'orders',
    sequence_no INT UNSIGNED NOT NULL,
    date_from DATETIME NOT NULL,
    date_to DATETIME NOT NULL,
    status ENUM('pending','queued','running','partial','complete','error','cancelled') NOT NULL DEFAULT 'pending',
    estimated_total INT UNSIGNED NULL,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    cursor_offset INT UNSIGNED NOT NULL DEFAULT 0,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NULL,
    queued_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sync_chunk_sequence (sync_batch_id, sequence_no),
    KEY idx_sync_chunks_due (status, next_run_at),
    KEY idx_sync_chunks_account_range (meli_account_id, sync_type, date_from, date_to, status),
    CONSTRAINT fk_sync_chunks_batch FOREIGN KEY (sync_batch_id) REFERENCES sync_batches(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_chunks_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_chunk_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_batch_chunk_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    status ENUM('running','success','partial','error') NOT NULL DEFAULT 'running',
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    cursor_offset_before INT UNSIGNED NOT NULL DEFAULT 0,
    cursor_offset_after INT UNSIGNED NOT NULL DEFAULT 0,
    total_remote INT UNSIGNED NULL,
    error_message VARCHAR(500) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    KEY idx_sync_chunk_runs_chunk (sync_batch_chunk_id, started_at),
    CONSTRAINT fk_sync_chunk_runs_chunk FOREIGN KEY (sync_batch_chunk_id) REFERENCES sync_batch_chunks(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_chunk_runs_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_diagnostics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    sample_json JSON NOT NULL,
    average_daily_orders DECIMAL(12,2) NOT NULL DEFAULT 0,
    estimated_month_orders INT UNSIGNED NOT NULL DEFAULT 0,
    recommended_mode ENUM('weekly','parts') NOT NULL DEFAULT 'weekly',
    recommended_parts INT UNSIGNED NOT NULL DEFAULT 4,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sync_diagnostics_account_period (meli_account_id, period_year, period_month, created_at),
    CONSTRAINT fk_sync_diagnostics_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_diagnostics_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_sync_coverage (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sync_type VARCHAR(80) NOT NULL DEFAULT 'orders',
    date_from DATETIME NOT NULL,
    date_to DATETIME NOT NULL,
    coverage_status ENUM('complete','partial','unknown','error') NOT NULL DEFAULT 'unknown',
    orders_count INT UNSIGNED NOT NULL DEFAULT 0,
    source_chunk_id BIGINT UNSIGNED NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_sync_coverage_range (meli_account_id, sync_type, date_from, date_to),
    KEY idx_meli_sync_coverage_lookup (meli_account_id, sync_type, coverage_status, date_from, date_to),
    CONSTRAINT fk_meli_sync_coverage_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_meli_sync_coverage_chunk FOREIGN KEY (source_chunk_id) REFERENCES sync_batch_chunks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_import_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    internal_product_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    source_meli_account_id BIGINT UNSIGNED NOT NULL,
    source_meli_item_id BIGINT UNSIGNED NOT NULL,
    source_meli_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    external_item_id VARCHAR(40) NOT NULL,
    external_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    source_reference VARCHAR(160) NOT NULL,
    source_title VARCHAR(255) NULL,
    source_price DECIMAL(18,2) NOT NULL DEFAULT 0,
    source_available_quantity INT NOT NULL DEFAULT 0,
    snapshot_json JSON NULL,
    imported_by BIGINT UNSIGNED NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_import_source (source_meli_account_id, external_item_id, external_variation_id),
    KEY idx_product_import_internal (internal_product_id),
    KEY idx_product_import_reference (company_id, source_reference),
    CONSTRAINT fk_product_import_internal FOREIGN KEY (internal_product_id) REFERENCES internal_products(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_import_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_import_account FOREIGN KEY (source_meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_import_item FOREIGN KEY (source_meli_item_id) REFERENCES meli_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_import_user FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE internal_products
    ADD COLUMN source_reference VARCHAR(160) NULL AFTER internal_sku,
    ADD COLUMN source_meli_account_id BIGINT UNSIGNED NULL AFTER company_id,
    ADD COLUMN source_meli_item_id BIGINT UNSIGNED NULL AFTER source_meli_account_id,
    ADD COLUMN source_meli_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER source_meli_item_id,
    ADD COLUMN source_snapshot_json JSON NULL AFTER notes,
    ADD KEY idx_internal_products_source_ref (company_id, source_reference),
    ADD KEY idx_internal_products_source_meli (source_meli_account_id, source_meli_item_id, source_meli_variation_id);

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('sync.chunk_mode', 'weekly', 'sync', 0),
('sync.chunk_parts', '4', 'sync', 0),
('sync.continuation_delay_minutes', '5', 'sync', 0),
('sync.queue_max_chunks_per_run', '3', 'sync', 0),
('sync.diagnostic_sample_days', '7', 'sync', 0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.3.0', 'Centro de sincronización, cola por lotes, cobertura de facturación e importaciones a bodega');
