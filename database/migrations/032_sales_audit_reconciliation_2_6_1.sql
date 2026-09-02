CREATE TABLE IF NOT EXISTS sync_sales_audit_remote_ids (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_sales_audit_day_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(80) NOT NULL,
    remote_date_created DATETIME NULL,
    remote_status VARCHAR(80) NULL,
    found_local_order_id BIGINT UNSIGNED NULL,
    classification VARCHAR(80) NOT NULL DEFAULT 'unknown',
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sync_sales_audit_remote_id (sync_sales_audit_day_id, external_order_id),
    KEY idx_sync_sales_audit_remote_account (meli_account_id, classification),
    KEY idx_sync_sales_audit_remote_external (meli_account_id, external_order_id),
    CONSTRAINT fk_sync_sales_audit_remote_day FOREIGN KEY (sync_sales_audit_day_id) REFERENCES sync_sales_audit_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_sales_audit_remote_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_sales_audit_remote_local_order FOREIGN KEY (found_local_order_id) REFERENCES meli_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sync_sales_audit_days
    ADD COLUMN remote_ids_checked_at DATETIME NULL AFTER exact_checked_at,
    ADD COLUMN local_shifted_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER shifted_count,
    ADD COLUMN remote_ids_total INT UNSIGNED NOT NULL DEFAULT 0 AFTER local_shifted_count,
    ADD COLUMN audit_consistency_status VARCHAR(80) NOT NULL DEFAULT 'unknown' AFTER remote_ids_total,
    ADD COLUMN audit_diagnostic_message VARCHAR(500) NULL AFTER audit_consistency_status;

ALTER TABLE sync_sales_audits
    ADD COLUMN daily_remote_sum INT UNSIGNED NOT NULL DEFAULT 0 AFTER local_total,
    ADD COLUMN daily_local_sum INT UNSIGNED NOT NULL DEFAULT 0 AFTER daily_remote_sum,
    ADD COLUMN exact_missing_total INT UNSIGNED NOT NULL DEFAULT 0 AFTER daily_local_sum,
    ADD COLUMN exact_shifted_total INT UNSIGNED NOT NULL DEFAULT 0 AFTER exact_missing_total,
    ADD COLUMN exact_extra_total INT UNSIGNED NOT NULL DEFAULT 0 AFTER exact_shifted_total,
    ADD COLUMN audit_consistency_status VARCHAR(80) NOT NULL DEFAULT 'unknown' AFTER exact_extra_total;

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.6.1', 'Auditoria exacta de ventas y reparacion confiable de ordenes');
