-- ERP Meli 2.33.0 - Snapshot operacional honesto para FIFO/parqueados.
-- Solo estructuras técnicas de lectura y reconciliación.

CREATE TABLE IF NOT EXISTS cron_v3_legacy_reconciliation (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  work_id BIGINT UNSIGNED NOT NULL,
  company_id BIGINT UNSIGNED NOT NULL,
  meli_account_id BIGINT UNSIGNED NOT NULL,
  legacy_queue VARCHAR(80) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','completed','skipped','failed') NOT NULL DEFAULT 'pending',
  reason_code VARCHAR(100) NOT NULL,
  safe_message VARCHAR(255) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_cron_v3_legacy_reconcile (work_id,legacy_queue,source_id),
  KEY idx_cron_v3_legacy_reconcile_status (status,legacy_queue,updated_at),
  KEY idx_cron_v3_legacy_reconcile_scope (company_id,meli_account_id,status,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_v3_operational_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  generation CHAR(40) NOT NULL,
  snapshot_json JSON NOT NULL,
  protocol ENUM('complete','partial','authoritative_empty','unavailable') NOT NULL DEFAULT 'complete',
  measured_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_cron_v3_operational_generation (generation),
  KEY idx_cron_v3_operational_measured (measured_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('cron_v3.fifo_snapshot_enabled', '1', 0, 'cron_v3'),
  ('cron_v3.legacy_reconciliation_enabled', '1', 0, 'cron_v3'),
  ('cron_v3.operational_release', '2.33.0', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);
