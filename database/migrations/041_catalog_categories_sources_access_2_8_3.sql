CREATE TABLE IF NOT EXISTS meli_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    external_category_id VARCHAR(80) NOT NULL,
    site_id VARCHAR(10) NOT NULL DEFAULT 'MCO',
    name VARCHAR(190) NOT NULL,
    path_from_root_json JSON NULL,
    parent_category_id VARCHAR(80) NULL,
    raw_json JSON NULL,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_categories_site_external (site_id, external_category_id),
    KEY idx_meli_categories_external (external_category_id),
    KEY idx_meli_categories_parent (parent_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE catalogs
    ADD COLUMN source_type ENUM('meli','internal','combined') NOT NULL DEFAULT 'meli' AFTER category_scope,
    ADD COLUMN company_scope ENUM('all','single_company') NOT NULL DEFAULT 'all' AFTER source_type,
    ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER company_scope,
    ADD KEY idx_catalogs_source (source_type, account_scope, meli_account_id),
    ADD KEY idx_catalogs_company (company_scope, company_id),
    ADD CONSTRAINT fk_catalogs_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS catalog_internal_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    catalog_id BIGINT UNSIGNED NOT NULL,
    internal_product_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    title_snapshot VARCHAR(255) NOT NULL,
    sku_snapshot VARCHAR(120) NULL,
    price_snapshot DECIMAL(18,2) NULL,
    currency_id VARCHAR(20) NULL,
    thumbnail_url VARCHAR(500) NULL,
    category_id VARCHAR(80) NULL,
    category_name VARCHAR(190) NULL,
    category_slug VARCHAR(160) NULL,
    stock_available INT NULL,
    status VARCHAR(80) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    is_out_of_stock TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    manual_sort_order INT NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    visibility_override ENUM('inherit','visible','hidden') NOT NULL DEFAULT 'inherit',
    hidden_by BIGINT UNSIGNED NULL,
    hidden_at DATETIME NULL,
    hidden_reason VARCHAR(255) NULL,
    source_updated_at DATETIME NULL,
    last_seen_in_source_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_catalog_internal_product (catalog_id, internal_product_id),
    KEY idx_catalog_internal_visible (catalog_id, is_visible, status),
    KEY idx_catalog_internal_category (catalog_id, category_slug, is_visible),
    KEY idx_catalog_internal_company (catalog_id, company_id, is_visible),
    KEY idx_catalog_internal_sku (catalog_id, sku_snapshot),
    KEY idx_catalog_internal_title (catalog_id, title_snapshot),
    KEY idx_catalog_internal_hidden_by (hidden_by),
    CONSTRAINT fk_catalog_internal_catalog FOREIGN KEY (catalog_id) REFERENCES catalogs(id) ON DELETE CASCADE,
    CONSTRAINT fk_catalog_internal_product FOREIGN KEY (internal_product_id) REFERENCES internal_products(id) ON DELETE CASCADE,
    CONSTRAINT fk_catalog_internal_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    CONSTRAINT fk_catalog_internal_hidden_by FOREIGN KEY (hidden_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.3', 'Categorias Mercado Libre cacheadas, fuentes ML/interna/combinada y acceso por clave para empleados.');
