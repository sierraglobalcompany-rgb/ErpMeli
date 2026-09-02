CREATE TABLE IF NOT EXISTS meli_product_update_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    status ENUM('draft','scanning','ready','applying','applied','partial','error','cancelled') NOT NULL DEFAULT 'draft',
    total_remote INT UNSIGNED NOT NULL DEFAULT 0,
    new_count INT UNSIGNED NOT NULL DEFAULT 0,
    changed_count INT UNSIGNED NOT NULL DEFAULT 0,
    unchanged_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    approved_count INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
    applied_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_items INT UNSIGNED NOT NULL DEFAULT 50,
    error_message VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    applied_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    applied_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_product_reviews_account_status (meli_account_id, status, created_at),
    KEY idx_product_reviews_created_by (created_by),
    CONSTRAINT fk_product_reviews_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_reviews_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_reviews_applied_by FOREIGN KEY (applied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_product_update_review_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_item_id VARCHAR(80) NOT NULL,
    local_meli_item_id BIGINT UNSIGNED NULL,
    item_status ENUM('new','changed','unchanged','error','approved','rejected','applied') NOT NULL DEFAULT 'unchanged',
    change_count INT UNSIGNED NOT NULL DEFAULT 0,
    summary VARCHAR(500) NULL,
    local_snapshot_json LONGTEXT NULL,
    remote_snapshot_json LONGTEXT NULL,
    safe_error_message VARCHAR(500) NULL,
    approved_by BIGINT UNSIGNED NULL,
    rejected_by BIGINT UNSIGNED NULL,
    applied_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    rejected_at DATETIME NULL,
    applied_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_review_item (review_id, external_item_id),
    KEY idx_product_review_items_review_status (review_id, item_status),
    KEY idx_product_review_items_account_external (meli_account_id, external_item_id),
    CONSTRAINT fk_product_review_items_review FOREIGN KEY (review_id) REFERENCES meli_product_update_reviews(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_review_items_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_review_items_local_item FOREIGN KEY (local_meli_item_id) REFERENCES meli_items(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_review_items_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_review_items_rejected_by FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_product_review_items_applied_by FOREIGN KEY (applied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_product_update_review_changes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_item_id BIGINT UNSIGNED NOT NULL,
    field_key VARCHAR(120) NOT NULL,
    field_label VARCHAR(160) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    change_type ENUM('created','updated','removed') NOT NULL DEFAULT 'updated',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_product_review_changes_item (review_item_id),
    CONSTRAINT fk_product_review_changes_item FOREIGN KEY (review_item_id) REFERENCES meli_product_update_review_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.8', 'Revisión previa y aprobación de cambios de publicaciones Mercado Libre.');
