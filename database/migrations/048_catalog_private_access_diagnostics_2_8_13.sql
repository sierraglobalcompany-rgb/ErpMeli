-- ERP Meli 2.8.13 — Diagnóstico y normalización de acceso privado de catálogos.
-- Migración aditiva: no guarda tokens planos ni modifica publicaciones.

ALTER TABLE catalogs
  ADD COLUMN IF NOT EXISTS last_private_access_error VARCHAR(120) NULL AFTER access_token_hash,
  ADD COLUMN IF NOT EXISTS last_private_access_error_at DATETIME NULL AFTER last_private_access_error,
  ADD COLUMN IF NOT EXISTS last_token_regenerated_at DATETIME NULL AFTER last_private_access_error_at;

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.13', 'Diagnóstico y corrección de enlaces privados de catálogos.');
