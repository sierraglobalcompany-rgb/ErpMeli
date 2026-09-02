-- ERP Meli 2.23.2 — identidad de venta por pack y reconstrucción histórica segura.

ALTER TABLE meli_packs
    ADD COLUMN IF NOT EXISTS integrity_status ENUM('provisional','pending','complete','partial','review') NOT NULL DEFAULT 'provisional' AFTER status,
    ADD COLUMN IF NOT EXISTS expected_orders_count INT UNSIGNED NULL AFTER integrity_status,
    ADD COLUMN IF NOT EXISTS linked_orders_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER expected_orders_count,
    ADD COLUMN IF NOT EXISTS expected_orders_json JSON NULL AFTER linked_orders_count,
    ADD COLUMN IF NOT EXISTS orders_fingerprint CHAR(64) NULL AFTER expected_orders_json,
    ADD COLUMN IF NOT EXISTS integrity_message VARCHAR(500) NULL AFTER orders_fingerprint,
    ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL AFTER integrity_message;

SET @sql_idx_packs_integrity := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_packs'
       AND INDEX_NAME='idx_packs_integrity')=0,
    'ALTER TABLE meli_packs ADD KEY idx_packs_integrity (meli_account_id,integrity_status,updated_at)',
    'SELECT 1'
);
PREPARE stmt_idx_packs_integrity FROM @sql_idx_packs_integrity;
EXECUTE stmt_idx_packs_integrity;
DEALLOCATE PREPARE stmt_idx_packs_integrity;

CREATE TABLE IF NOT EXISTS sale_pack_relation_repair_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    previous_meli_pack_id BIGINT UNSIGNED NOT NULL,
    canonical_meli_pack_id BIGINT UNSIGNED NULL,
    reason VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pack_relation_repair
        (meli_order_id,previous_meli_pack_id,canonical_meli_pack_id),
    KEY idx_pack_relation_repair_scope (meli_account_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materializa primero el pack correcto desde la identidad que ya posee la
-- orden. Nunca se elige el menor ID interno como criterio de verdad.
INSERT INTO meli_packs
    (meli_account_id,external_pack_id,external_shipment_id,status,integrity_status,
     linked_orders_count,integrity_message,synced_at)
SELECT o.meli_account_id,o.external_pack_id,MAX(o.external_shipping_id),
       'provisional','provisional',COUNT(DISTINCT o.id),
       'Relación reconstruida con datos locales; falta verificación remota.',
       UTC_TIMESTAMP()
FROM meli_orders o
WHERE o.external_pack_id IS NOT NULL
GROUP BY o.meli_account_id,o.external_pack_id
ON DUPLICATE KEY UPDATE
    external_shipment_id=COALESCE(meli_packs.external_shipment_id,VALUES(external_shipment_id)),
    linked_orders_count=VALUES(linked_orders_count);

INSERT IGNORE INTO meli_pack_orders (meli_pack_id,meli_order_id)
SELECT p.id,o.id
FROM meli_orders o
JOIN meli_packs p
  ON p.meli_account_id=o.meli_account_id
 AND p.external_pack_id=o.external_pack_id
WHERE o.external_pack_id IS NOT NULL;

INSERT IGNORE INTO sale_pack_relation_repair_audit
    (meli_account_id,meli_order_id,previous_meli_pack_id,canonical_meli_pack_id,reason)
SELECT o.meli_account_id,o.id,wrong_link.meli_pack_id,canonical_pack.id,
       'La relación no coincidía con external_pack_id de la orden.'
FROM meli_orders o
JOIN meli_packs canonical_pack
  ON canonical_pack.meli_account_id=o.meli_account_id
 AND canonical_pack.external_pack_id=o.external_pack_id
JOIN meli_pack_orders wrong_link
  ON wrong_link.meli_order_id=o.id
 AND wrong_link.meli_pack_id<>canonical_pack.id
WHERE o.external_pack_id IS NOT NULL;

DELETE wrong_link
FROM meli_orders o
JOIN meli_packs canonical_pack
  ON canonical_pack.meli_account_id=o.meli_account_id
 AND canonical_pack.external_pack_id=o.external_pack_id
JOIN meli_pack_orders wrong_link
  ON wrong_link.meli_order_id=o.id
 AND wrong_link.meli_pack_id<>canonical_pack.id
WHERE o.external_pack_id IS NOT NULL;

INSERT IGNORE INTO sale_pack_relation_repair_audit
    (meli_account_id,meli_order_id,previous_meli_pack_id,canonical_meli_pack_id,reason)
SELECT o.meli_account_id,o.id,po.meli_pack_id,NULL,
       'La orden no informa pack y no debe conservar una relación agrupada.'
FROM meli_orders o
JOIN meli_pack_orders po ON po.meli_order_id=o.id
WHERE o.external_pack_id IS NULL;

DELETE po
FROM meli_orders o
JOIN meli_pack_orders po ON po.meli_order_id=o.id
WHERE o.external_pack_id IS NULL;

SET @sql_uq_pack_order_single := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_pack_orders'
       AND INDEX_NAME='uq_pack_order_single')=0,
    'ALTER TABLE meli_pack_orders ADD UNIQUE KEY uq_pack_order_single (meli_order_id)',
    'SELECT 1'
);
PREPARE stmt_uq_pack_order_single FROM @sql_uq_pack_order_single;
EXECUTE stmt_uq_pack_order_single;
DEALLOCATE PREPARE stmt_uq_pack_order_single;

ALTER TABLE order_resource_enrichment_jobs
    ADD COLUMN IF NOT EXISTS lease_generation INT UNSIGNED NOT NULL DEFAULT 0 AFTER lock_token,
    ADD COLUMN IF NOT EXISTS heartbeat_at DATETIME NULL AFTER locked_at;

CREATE TABLE IF NOT EXISTS sale_pack_reconciliation_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_pack_id BIGINT UNSIGNED NOT NULL,
    operation ENUM('verify_pack','recover_order') NOT NULL,
    external_resource_id VARCHAR(80) NOT NULL,
    status ENUM('pending','running','retry','complete','partial','error','paused','cancelled') NOT NULL DEFAULT 'pending',
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lock_owner CHAR(32) NULL,
    lease_generation INT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME NULL,
    heartbeat_at DATETIME NULL,
    last_error_code VARCHAR(80) NULL,
    safe_message VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_pack_reconcile_resource (meli_account_id,operation,external_resource_id),
    KEY idx_sale_pack_reconcile_due (status,next_run_at,priority,id),
    KEY idx_sale_pack_reconcile_pack (meli_pack_id,status),
    CONSTRAINT fk_sale_pack_reconcile_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_pack_reconcile_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_pack_reconcile_pack FOREIGN KEY (meli_pack_id) REFERENCES meli_packs(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_pack_reconcile_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_pack_reconciliation_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_pack_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    previous_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    linked_orders_count INT UNSIGNED NOT NULL DEFAULT 0,
    expected_orders_count INT UNSIGNED NULL,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sale_pack_history_pack (meli_pack_id,created_at),
    KEY idx_sale_pack_history_scope (company_id,meli_account_id,created_at),
    CONSTRAINT fk_sale_pack_history_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_pack_history_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_pack_history_pack FOREIGN KEY (meli_pack_id) REFERENCES meli_packs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sale_pack_rebuild_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    status ENUM('pending','running','complete','error') NOT NULL DEFAULT 'pending',
    lock_owner CHAR(32) NULL,
    lease_generation INT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME NULL,
    safe_message VARCHAR(500) NULL,
    packs_count INT UNSIGNED NOT NULL DEFAULT 0,
    links_count INT UNSIGNED NOT NULL DEFAULT 0,
    jobs_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sale_pack_rebuild_due (status,created_at,id),
    CONSTRAINT fk_sale_pack_rebuild_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_pack_rebuild_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_pack_rebuild_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reactiva únicamente el fallo histórico conocido provocado por la columna
-- meli_orders.meli_pack_id que nunca formó parte del esquema.
UPDATE order_resource_enrichment_jobs
SET status='retry',
    next_run_at=UTC_TIMESTAMP(),
    lock_token=NULL,
    locked_at=NULL,
    heartbeat_at=NULL
WHERE resource_type='pack'
  AND status='error'
  AND last_error_message LIKE '%meli_pack_id%'
  AND (
      last_error_message LIKE '%Unknown column%'
      OR last_error_message LIKE '%columna desconocida%'
  );

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales.pack_snapshot_ttl_hours','24','sales',0),
('sales.pack_reconciliation_batch','1','sales',0),
('sales.grouped_view_enabled','1','sales',0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('sales_grouped','121_sale_pack_identity_integrity_2_23_2.sql','structural',1),
('sale_pack_reconciliation','121_sale_pack_identity_integrity_2_23_2.sql','structural',1),
('process_sync_queue','121_sale_pack_identity_integrity_2_23_2.sql','structural',1)
ON DUPLICATE KEY UPDATE required_migration=VALUES(required_migration),enabled=1;

INSERT INTO app_versions (version,notes)
VALUES ('2.23.2','Reconstrucción histórica de packs e identidad agrupada de ventas')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
