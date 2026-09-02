-- ERP Meli 2.8.9 — Stock real por origen y métodos de envío en catálogos
-- Migración aditiva: no borra datos existentes.

ALTER TABLE meli_items
  ADD COLUMN IF NOT EXISTS user_product_id VARCHAR(80) NULL AFTER external_item_id,
  ADD COLUMN IF NOT EXISTS logistic_type VARCHAR(80) NULL AFTER listing_type_id,
  ADD COLUMN IF NOT EXISTS shipping_mode VARCHAR(80) NULL AFTER logistic_type;

ALTER TABLE meli_item_variations
  ADD COLUMN IF NOT EXISTS user_product_id VARCHAR(80) NULL AFTER external_variation_id;

CREATE TABLE IF NOT EXISTS meli_item_stock_locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  meli_item_id BIGINT UNSIGNED NOT NULL,
  meli_item_variation_id BIGINT UNSIGNED NULL,
  external_item_id VARCHAR(80) NOT NULL,
  external_variation_id BIGINT UNSIGNED NULL,
  user_product_id VARCHAR(80) NOT NULL,
  location_type VARCHAR(80) NULL,
  network_node_id VARCHAR(120) NULL,
  store_id VARCHAR(120) NULL,
  quantity INT NOT NULL DEFAULT 0,
  stock_source ENUM('multi_origin','item_total','manual','unknown') NOT NULL DEFAULT 'multi_origin',
  raw_json LONGTEXT NULL,
  last_synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_meli_stock_item (meli_item_id),
  KEY idx_meli_stock_account_user_product (meli_account_id, user_product_id),
  KEY idx_meli_stock_location_type (location_type),
  CONSTRAINT fk_meli_stock_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_meli_stock_item FOREIGN KEY (meli_item_id) REFERENCES meli_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_meli_stock_variation FOREIGN KEY (meli_item_variation_id) REFERENCES meli_item_variations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE catalog_items
  ADD COLUMN IF NOT EXISTS stock_full INT NULL AFTER stock_available,
  ADD COLUMN IF NOT EXISTS stock_non_full INT NULL AFTER stock_full,
  ADD COLUMN IF NOT EXISTS stock_unknown INT NULL AFTER stock_non_full,
  ADD COLUMN IF NOT EXISTS stock_detail_status ENUM('confirmed','partial','not_available','unknown') NOT NULL DEFAULT 'unknown' AFTER stock_unknown,
  ADD COLUMN IF NOT EXISTS shipping_methods_json JSON NULL AFTER shipping_mode;

ALTER TABLE catalogs
  ADD COLUMN IF NOT EXISTS show_stock_breakdown TINYINT(1) NOT NULL DEFAULT 0 AFTER show_stock,
  ADD COLUMN IF NOT EXISTS show_shipping_methods TINYINT(1) NOT NULL DEFAULT 1 AFTER show_full_badge,
  ADD COLUMN IF NOT EXISTS show_stock_detail_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_shipping_methods;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
  ('catalog.stock_locations_enabled','0','catalog',0),
  ('catalog.stock_locations_batch_limit','20','catalog',0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group=VALUES(setting_group), is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.9', 'Stock real por origen y métodos de envío en catálogos.');
