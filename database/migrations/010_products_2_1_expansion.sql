ALTER TABLE meli_items
    ADD COLUMN base_price DECIMAL(18,2) NULL AFTER price,
    ADD COLUMN original_price DECIMAL(18,2) NULL AFTER base_price,
    ADD COLUMN `condition` VARCHAR(40) NULL AFTER original_price,
    ADD COLUMN catalog_product_id VARCHAR(80) NULL AFTER category_id,
    ADD COLUMN official_store_id BIGINT UNSIGNED NULL AFTER catalog_product_id,
    ADD COLUMN quality_score DECIMAL(8,4) NULL AFTER official_store_id,
    ADD KEY idx_meli_items_catalog (meli_account_id, catalog_product_id),
    ADD KEY idx_meli_items_condition (meli_account_id, `condition`);

ALTER TABLE meli_item_attributes
    ADD COLUMN value_id VARCHAR(120) NULL AFTER name,
    ADD KEY idx_meli_attribute_value (attribute_id, value_id);

ALTER TABLE internal_products
    ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN suggested_purchase_value DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER manual_purchase_value,
    ADD COLUMN min_profit_percent DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER default_profit_percent,
    ADD COLUMN deleted_at DATETIME NULL AFTER status,
    ADD KEY idx_internal_products_company_status (company_id, status, deleted_at),
    ADD CONSTRAINT fk_internal_products_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL;
