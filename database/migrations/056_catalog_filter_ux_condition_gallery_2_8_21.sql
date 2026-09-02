-- ERP Meli 2.8.21 — Filtros UX de catálogo y condición de producto.
-- Aditiva: permite filtrar Nuevo/Usado desde snapshots locales sin consultar Mercado Libre en vistas públicas.

ALTER TABLE catalog_items
    ADD COLUMN IF NOT EXISTS condition_snapshot VARCHAR(40) NULL AFTER status;

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.21', 'Filtros públicos de catálogo rediseñados, condición Nuevo/Usado en snapshots y galería pública estable.');
