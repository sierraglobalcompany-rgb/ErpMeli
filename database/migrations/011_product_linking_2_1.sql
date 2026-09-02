ALTER TABLE product_meli_links
    ADD COLUMN link_source ENUM('manual','from_item','from_order','suggested') NOT NULL DEFAULT 'manual' AFTER status,
    ADD COLUMN meli_variation_external_id BIGINT UNSIGNED NULL AFTER meli_variation_id,
    ADD COLUMN item_snapshot_json JSON NULL AFTER meli_sku_snapshot,
    ADD COLUMN deactivated_at DATETIME NULL AFTER status,
    ADD KEY idx_product_links_account_status (meli_account_id, status),
    ADD KEY idx_product_links_item_status (meli_item_id, meli_variation_id, status);

CREATE TABLE IF NOT EXISTS product_match_suggestions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_item_id BIGINT UNSIGNED NULL,
    meli_order_item_id BIGINT UNSIGNED NULL,
    internal_product_id BIGINT UNSIGNED NULL,
    suggestion_type ENUM('sku','title','ean','manual') NOT NULL DEFAULT 'manual',
    confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
    reason VARCHAR(500) NULL,
    status ENUM('pending','accepted','rejected','ignored') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_suggestion (meli_account_id, meli_item_id, meli_order_item_id, internal_product_id, suggestion_type),
    KEY idx_product_suggestions_status (status, confidence),
    CONSTRAINT fk_suggestions_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_suggestions_item FOREIGN KEY (meli_item_id) REFERENCES meli_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_suggestions_order_item FOREIGN KEY (meli_order_item_id) REFERENCES meli_order_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_suggestions_internal FOREIGN KEY (internal_product_id) REFERENCES internal_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_unlinked_ignores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_item_id VARCHAR(40) NOT NULL,
    external_variation_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    ignored_by BIGINT UNSIGNED NULL,
    ignored_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_unlinked_ignore (meli_account_id, external_item_id, external_variation_id),
    CONSTRAINT fk_unlinked_ignores_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_unlinked_ignores_user FOREIGN KEY (ignored_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
