-- ERP Meli 2.8.17 — Galería clicable en ficha pública de producto.
-- El cambio funcional está en la vista pública de producto y en assets.
-- Esta migración registra la versión para el Actualizador.

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.17', 'Corrige la galería de imágenes en fichas públicas de catálogo para permitir cambiar la foto principal.');
