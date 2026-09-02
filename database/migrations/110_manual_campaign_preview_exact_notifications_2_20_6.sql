-- ERP Meli 2.20.6 — cálculo visible, selección congelada y notificaciones exactas.
-- Aditiva e idempotente. No modifica datos comerciales ni consulta Mercado Libre.

CREATE TABLE IF NOT EXISTS manual_campaign_previews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    preview_token CHAR(40) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    scope_key VARCHAR(40) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    configuration_hash CHAR(64) NOT NULL,
    configuration_json JSON NOT NULL,
    summary_json JSON NOT NULL,
    status ENUM('ready','consumed','expired') NOT NULL DEFAULT 'ready',
    expires_at DATETIME(3) NOT NULL,
    consumed_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_manual_campaign_preview_token (preview_token),
    KEY idx_manual_campaign_preview_lookup
        (created_by_user_id,configuration_hash,status,expires_at),
    KEY idx_manual_campaign_preview_expiry (status,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_campaign_preview_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manual_campaign_preview_id BIGINT UNSIGNED NOT NULL,
    queue_key VARCHAR(80) NOT NULL,
    source_id VARCHAR(160) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    source_state VARCHAR(40) NOT NULL,
    item_payload_json JSON NOT NULL,
    position_no INT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_manual_campaign_preview_item
        (manual_campaign_preview_id,queue_key,source_id),
    KEY idx_manual_campaign_preview_item_order
        (manual_campaign_preview_id,position_no),
    CONSTRAINT fk_manual_campaign_preview_item
        FOREIGN KEY (manual_campaign_preview_id)
        REFERENCES manual_campaign_previews(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_notification_manual_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='meli_notification_work_items'
      AND index_name='idx_notification_manual_eligible'
);
SET @notification_manual_idx_sql = IF(
    @has_notification_manual_idx=0,
    'ALTER TABLE meli_notification_work_items ADD KEY idx_notification_manual_eligible (status,next_run_at,lock_expires_at,meli_account_id,priority,id)',
    'SELECT 1'
);
PREPARE stmt_notification_manual_idx FROM @notification_manual_idx_sql;
EXECUTE stmt_notification_manual_idx;
DEALLOCATE PREPARE stmt_notification_manual_idx;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.preview_ttl_seconds','600','manual_campaign',0),
('manual_campaign.preview_cache_seconds','20','manual_campaign',0),
('manual_campaign.preview_group_limit','10','manual_campaign',0),
('manual_campaign.notification_exact_enabled','1','manual_campaign',0),
('manual_campaign.max_remote_calls_per_step','1','manual_campaign',0)
ON DUPLICATE KEY UPDATE
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.20.6','Cálculo visible, selección congelada y procesamiento exacto de notificaciones seguras')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
