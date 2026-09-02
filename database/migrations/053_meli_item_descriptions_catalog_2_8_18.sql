-- ERP Meli 2.8.18 — Descripciones cacheadas para fichas de catálogo.
-- Solo lectura hacia Mercado Libre: la vista pública usa esta caché local y no consulta API.

CREATE TABLE IF NOT EXISTS meli_item_descriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    meli_item_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_item_id VARCHAR(80) NOT NULL,
    plain_text MEDIUMTEXT NULL,
    description_text MEDIUMTEXT NULL,
    source_status ENUM('pending','confirmed','unavailable','error') NOT NULL DEFAULT 'pending',
    safe_error_message VARCHAR(500) NULL,
    raw_json LONGTEXT NULL,
    synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_meli_item_description_item (meli_item_id),
    UNIQUE KEY uq_meli_item_description_account_external (meli_account_id, external_item_id),
    KEY idx_meli_item_descriptions_status (source_status, synced_at),
    CONSTRAINT fk_meli_item_descriptions_item FOREIGN KEY (meli_item_id) REFERENCES meli_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_meli_item_descriptions_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
    ('items.sync_descriptions_enabled','1','products',0),
    ('items.description_public_cache_only','1','catalog',0)
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.8.18', 'Galería pública con zoom y descripciones Mercado Libre cacheadas localmente.');
