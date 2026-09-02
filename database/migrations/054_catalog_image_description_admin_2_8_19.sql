-- ERP Meli 2.8.19 — Imagen principal correcta y actualización admin de descripciones.
-- Solo lectura hacia Mercado Libre. El catálogo público usa caché local.

ALTER TABLE catalogs
  ADD COLUMN IF NOT EXISTS last_description_sync_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS last_description_sync_status VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS last_description_sync_message VARCHAR(500) NULL;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('catalog.description_batch_limit','20','catalog',0),
    ('catalog.description_retry_errors','0','catalog',0),
    ('catalog.description_force_refresh','0','catalog',0)
ON DUPLICATE KEY UPDATE
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.19', 'Corrige imagen principal del catálogo y agrega actualización admin de descripciones cacheadas.');
