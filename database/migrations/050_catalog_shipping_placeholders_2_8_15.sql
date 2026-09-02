-- ERP Meli 2.8.15 — Corrección de placeholders SQL en filtros de logística de catálogos.
-- El cambio funcional está en CatalogQueryService; esta migración registra la versión
-- para que el Actualizador refleje correctamente el correctivo instalado.

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.15', 'Corrige HY093 en catálogos causado por placeholders repetidos en filtros de métodos de envío.');
