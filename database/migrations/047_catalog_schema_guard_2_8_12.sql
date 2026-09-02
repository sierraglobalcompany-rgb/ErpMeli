-- ERP Meli 2.8.12 — Guard de esquema para catálogos público/privado.
-- Migración aditiva: asegura columnas usadas por las vistas y consultas recientes.

ALTER TABLE catalogs
  ADD COLUMN IF NOT EXISTS show_public_advanced_filters TINYINT(1) NOT NULL DEFAULT 0 AFTER show_updated_date,
  ADD COLUMN IF NOT EXISTS show_stock_breakdown TINYINT(1) NOT NULL DEFAULT 0 AFTER show_stock,
  ADD COLUMN IF NOT EXISTS show_shipping_methods TINYINT(1) NOT NULL DEFAULT 1 AFTER show_full_badge,
  ADD COLUMN IF NOT EXISTS show_stock_detail_public TINYINT(1) NOT NULL DEFAULT 0 AFTER show_shipping_methods,
  ADD COLUMN IF NOT EXISTS public_statuses_json LONGTEXT NULL AFTER layout_type;

ALTER TABLE catalog_items
  ADD COLUMN IF NOT EXISTS stock_full INT NULL AFTER stock_available,
  ADD COLUMN IF NOT EXISTS stock_non_full INT NULL AFTER stock_full,
  ADD COLUMN IF NOT EXISTS stock_unknown INT NULL AFTER stock_non_full,
  ADD COLUMN IF NOT EXISTS stock_detail_status ENUM('confirmed','partial','not_available','unknown') NOT NULL DEFAULT 'unknown' AFTER stock_unknown,
  ADD COLUMN IF NOT EXISTS shipping_methods_json JSON NULL AFTER shipping_mode;

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.12', 'Guard de esquema y manejo seguro de errores en catálogos público/privado.');
