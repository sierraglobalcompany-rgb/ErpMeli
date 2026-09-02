-- ERP Meli 2.8.23 — Cola resiliente de descripciones de catálogo.
-- Aditiva e idempotente. No modifica publicaciones ni escribe en Mercado Libre.

CREATE TABLE IF NOT EXISTS catalog_description_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalog_id BIGINT UNSIGNED NOT NULL,
    mode VARCHAR(30) NOT NULL DEFAULT 'missing',
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    dedupe_key CHAR(64) NULL,
    batch_limit SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    pause_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    total_items INT UNSIGNED NOT NULL DEFAULT 0,
    processed_items INT UNSIGNED NOT NULL DEFAULT 0,
    confirmed_items INT UNSIGNED NOT NULL DEFAULT 0,
    unavailable_items INT UNSIGNED NOT NULL DEFAULT 0,
    error_items INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_items INT UNSIGNED NOT NULL DEFAULT 0,
    current_account_id BIGINT UNSIGNED NULL,
    next_run_at DATETIME NULL,
    lock_token CHAR(64) NULL,
    locked_at DATETIME NULL,
    lock_expires_at DATETIME NULL,
    stop_reason VARCHAR(80) NULL,
    last_error_message VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    last_processed_at DATETIME NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalog_description_jobs_dedupe (dedupe_key),
    KEY idx_catalog_description_jobs_due (status, next_run_at, lock_expires_at),
    KEY idx_catalog_description_jobs_catalog (catalog_id, created_at),
    KEY idx_catalog_description_jobs_account (current_account_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_description_job_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    catalog_description_job_id BIGINT UNSIGNED NOT NULL,
    catalog_item_id BIGINT UNSIGNED NOT NULL,
    meli_item_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_item_id VARCHAR(80) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    source_status VARCHAR(30) NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_retry_at DATETIME NULL,
    safe_error_message VARCHAR(500) NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalog_description_job_item (catalog_description_job_id, meli_item_id),
    KEY idx_catalog_description_job_items_due (catalog_description_job_id, status, next_retry_at),
    KEY idx_catalog_description_job_items_account (catalog_description_job_id, meli_account_id, status),
    KEY idx_catalog_description_job_items_external (meli_account_id, external_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('catalog.description_job_batch_limit','20','catalog',0),
    ('catalog.description_job_pause_seconds','30','catalog',0),
    ('catalog.description_job_max_attempts','3','catalog',0),
    ('catalog.description_job_retention_days','90','catalog',0)
ON DUPLICATE KEY UPDATE
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.23', 'Catálogo UX estable, galería aislada y cola persistente/reanudable de descripciones.');
