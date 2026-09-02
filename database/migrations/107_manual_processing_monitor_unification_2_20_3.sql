-- ERP Meli 2.20.3 — monitor asistido unificado y estados verificables.
-- Aditiva e idempotente. No modifica datos comerciales ni ejecuta consultas remotas.

ALTER TABLE manual_campaigns
    ADD COLUMN IF NOT EXISTS origin_key VARCHAR(50) NOT NULL DEFAULT 'manual_center' AFTER scope_key,
    ADD COLUMN IF NOT EXISTS origin_context_json TEXT NULL AFTER origin_key,
    ADD COLUMN IF NOT EXISTS returned_items INT UNSIGNED NOT NULL DEFAULT 0 AFTER retry_items,
    ADD COLUMN IF NOT EXISTS last_engine_state VARCHAR(40) NULL AFTER worker_heartbeat_at,
    ADD COLUMN IF NOT EXISTS last_result_message VARCHAR(500) NULL AFTER safe_message;

SET @has_origin_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='manual_campaigns'
      AND index_name='idx_manual_campaign_origin'
);
SET @origin_idx_sql = IF(
    @has_origin_idx=0,
    'ALTER TABLE manual_campaigns ADD KEY idx_manual_campaign_origin (origin_key,created_at)',
    'SELECT 1'
);
PREPARE stmt_origin_idx FROM @origin_idx_sql;
EXECUTE stmt_origin_idx;
DEALLOCATE PREPARE stmt_origin_idx;

-- Las campañas ya finalizadas conservan sus resultados; solo se reconstruye
-- el contador derivado de recursos devueltos para que el monitor sea coherente.
UPDATE manual_campaigns c
SET c.returned_items=(
    SELECT COUNT(*)
    FROM manual_campaign_items i
    WHERE i.manual_campaign_id=c.id AND i.status='returned'
);

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.monitor_unified_enabled','1','manual_campaign',0),
('manual_campaign.status_poll_running_ms','2000','manual_campaign',0),
('manual_campaign.status_poll_waiting_ms','5000','manual_campaign',0),
('manual_campaign.status_poll_paused_ms','10000','manual_campaign',0),
('manual_campaign.status_poll_hidden_ms','15000','manual_campaign',0),
('manual_campaign.engine_live_seconds','15','manual_campaign',0),
('manual_campaign.legacy_navigation_until','2.20.4','manual_campaign',0)
ON DUPLICATE KEY UPDATE
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.20.3','Monitor asistido unificado, progreso verificable y navegación heredada segura')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
