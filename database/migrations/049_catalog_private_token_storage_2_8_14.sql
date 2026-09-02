-- ERP Meli 2.8.14 — Blindaje de almacenamiento de token privado de catálogos.
-- Corrige instalaciones donde access_token_hash pudo quedar con tamaño insuficiente
-- y truncar el hash SHA-256 de 64 caracteres.

ALTER TABLE catalogs
  MODIFY COLUMN access_token_hash VARCHAR(128) NULL;

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.14', 'Compatibilidad y corrección de almacenamiento de token privado de catálogos.');
