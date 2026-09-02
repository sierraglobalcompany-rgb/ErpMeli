CREATE TABLE IF NOT EXISTS meli_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_item_id VARCHAR(40) NOT NULL,
    title VARCHAR(255) NOT NULL,
    seller_sku VARCHAR(120) NULL,
    category_id VARCHAR(80) NULL,
    price DECIMAL(18,2) NOT NULL DEFAULT 0,
    available_quantity INT NOT NULL DEFAULT 0,
    sold_quantity INT NOT NULL DEFAULT 0,
    status VARCHAR(80) NULL,
    permalink VARCHAR(500) NULL,
    thumbnail VARCHAR(500) NULL,
    listing_type_id VARCHAR(80) NULL,
    raw_json JSON NULL,
    synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_item_account_external (meli_account_id, external_item_id),
    KEY idx_meli_items_status (meli_account_id, status),
    KEY idx_meli_items_sku (meli_account_id, seller_sku),
    CONSTRAINT fk_meli_items_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_item_variations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_item_id BIGINT UNSIGNED NOT NULL,
    external_variation_id BIGINT UNSIGNED NOT NULL,
    seller_sku VARCHAR(120) NULL,
    price DECIMAL(18,2) NOT NULL DEFAULT 0,
    available_quantity INT NOT NULL DEFAULT 0,
    sold_quantity INT NOT NULL DEFAULT 0,
    attribute_summary VARCHAR(500) NULL,
    raw_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_item_variation (meli_item_id, external_variation_id),
    KEY idx_meli_variations_sku (seller_sku),
    CONSTRAINT fk_meli_variations_item FOREIGN KEY (meli_item_id) REFERENCES meli_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_item_pictures (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_item_id BIGINT UNSIGNED NOT NULL,
    url VARCHAR(500) NULL,
    secure_url VARCHAR(500) NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_meli_pictures_item_position (meli_item_id, position),
    CONSTRAINT fk_meli_pictures_item FOREIGN KEY (meli_item_id) REFERENCES meli_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_item_attributes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_item_id BIGINT UNSIGNED NOT NULL,
    attribute_id VARCHAR(120) NOT NULL,
    name VARCHAR(190) NULL,
    value_name VARCHAR(255) NULL,
    raw_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_meli_attributes_item (meli_item_id, attribute_id),
    CONSTRAINT fk_meli_attributes_item FOREIGN KEY (meli_item_id) REFERENCES meli_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS internal_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    internal_sku VARCHAR(120) NOT NULL,
    name VARCHAR(255) NOT NULL,
    accounting_name VARCHAR(255) NULL,
    description TEXT NULL,
    unit VARCHAR(40) NOT NULL DEFAULT 'unidad',
    tax_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    manual_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    default_profit_percent DECIMAL(8,4) NOT NULL DEFAULT 10,
    manual_purchase_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    image_url VARCHAR(500) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_internal_products_sku (internal_sku),
    KEY idx_internal_products_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_meli_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    internal_product_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_item_id BIGINT UNSIGNED NOT NULL,
    meli_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    meli_title_snapshot VARCHAR(255) NULL,
    meli_sku_snapshot VARCHAR(120) NULL,
    conversion_factor DECIMAL(12,4) NOT NULL DEFAULT 1,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_meli_active_item (meli_account_id, meli_item_id, meli_variation_id, status),
    KEY idx_product_meli_internal (internal_product_id, status),
    CONSTRAINT fk_product_links_internal FOREIGN KEY (internal_product_id) REFERENCES internal_products(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_links_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_product_links_item FOREIGN KEY (meli_item_id) REFERENCES meli_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
