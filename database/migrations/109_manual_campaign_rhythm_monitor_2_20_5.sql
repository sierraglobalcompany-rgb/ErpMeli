-- ERP Meli 2.20.5 — ritmo global de campañas y monitor compacto.
-- Aditiva e idempotente. No modifica datos comerciales ni consulta Mercado Libre.

ALTER TABLE manual_campaigns
    ADD COLUMN IF NOT EXISTS outbound_calls INT UNSIGNED NOT NULL DEFAULT 0 AFTER derived_calls,
    ADD COLUMN IF NOT EXISTS block_outbound_calls INT UNSIGNED NOT NULL DEFAULT 0 AFTER calls_in_block,
    ADD COLUMN IF NOT EXISTS completed_blocks INT UNSIGNED NOT NULL DEFAULT 0 AFTER current_block,
    ADD COLUMN IF NOT EXISTS block_started_at DATETIME(3) NULL AFTER completed_blocks,
    ADD COLUMN IF NOT EXISTS block_completed_at DATETIME(3) NULL AFTER block_started_at,
    ADD COLUMN IF NOT EXISTS limit_reason VARCHAR(40) NULL AFTER block_completed_at,
    ADD COLUMN IF NOT EXISTS finished_reason VARCHAR(80) NULL AFTER limit_reason;

ALTER TABLE manual_campaign_items
    ADD COLUMN IF NOT EXISTS source_state VARCHAR(40) NULL AFTER last_step_key,
    ADD COLUMN IF NOT EXISTS source_checked_at DATETIME(3) NULL AFTER source_state,
    ADD COLUMN IF NOT EXISTS source_resolution VARCHAR(120) NULL AFTER source_checked_at,
    ADD COLUMN IF NOT EXISTS total_units_known TINYINT(1) NOT NULL DEFAULT 1 AFTER source_resolution;

ALTER TABLE manual_campaign_steps
    ADD COLUMN IF NOT EXISTS outbound_calls SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER derived_calls;

ALTER TABLE manual_campaign_events
    ADD COLUMN IF NOT EXISTS operation_key VARCHAR(80) NULL AFTER event_type,
    ADD COLUMN IF NOT EXISTS meli_account_id BIGINT UNSIGNED NULL AFTER operation_key;

SET @has_campaign_state_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='manual_campaign_items'
      AND index_name='idx_manual_campaign_item_state'
);
SET @campaign_state_idx_sql = IF(
    @has_campaign_state_idx=0,
    'ALTER TABLE manual_campaign_items ADD KEY idx_manual_campaign_item_state (manual_campaign_id,status,source_state,position_no)',
    'SELECT 1'
);
PREPARE stmt_campaign_state_idx FROM @campaign_state_idx_sql;
EXECUTE stmt_campaign_state_idx;
DEALLOCATE PREPARE stmt_campaign_state_idx;

UPDATE manual_campaigns c
LEFT JOIN (
    SELECT manual_campaign_id,
           COALESCE(SUM(primary_calls+derived_calls),0) AS counted_outbound
    FROM manual_campaign_steps
    WHERE status IN ('completed','failed')
    GROUP BY manual_campaign_id
) s ON s.manual_campaign_id=c.id
SET c.outbound_calls=GREATEST(c.outbound_calls,COALESCE(s.counted_outbound,0)),
    c.block_outbound_calls=MOD(
        GREATEST(c.outbound_calls,COALESCE(s.counted_outbound,0)),
        GREATEST(1,COALESCE(
            CAST(JSON_UNQUOTE(JSON_EXTRACT(c.configuration_json,'$.block_size')) AS UNSIGNED),
            1
        ))
    ),
    c.completed_blocks=FLOOR(
        GREATEST(c.outbound_calls,COALESCE(s.counted_outbound,0))
        / GREATEST(1,COALESCE(
            CAST(JSON_UNQUOTE(JSON_EXTRACT(c.configuration_json,'$.block_size')) AS UNSIGNED),
            1
        ))
    ),
    c.current_block=FLOOR(
        GREATEST(c.outbound_calls,COALESCE(s.counted_outbound,0))
        / GREATEST(1,COALESCE(
            CAST(JSON_UNQUOTE(JSON_EXTRACT(c.configuration_json,'$.block_size')) AS UNSIGNED),
            1
        ))
    )+1,
    c.calls_in_block=MOD(
        GREATEST(c.outbound_calls,COALESCE(s.counted_outbound,0)),
        GREATEST(1,COALESCE(
            CAST(JSON_UNQUOTE(JSON_EXTRACT(c.configuration_json,'$.block_size')) AS UNSIGNED),
            1
        ))
    )
WHERE c.execution_mode='interactive_web';

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.status_event_limit','10','manual_campaign',0),
('manual_campaign.monitor_page_size','50','manual_campaign',0),
('manual_campaign.global_block_counter','1','manual_campaign',0),
('manual_campaign.source_inspection_enabled','1','manual_campaign',0)
ON DUPLICATE KEY UPDATE
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.20.5','Ritmo global confiable y monitor operativo compacto para campañas manuales')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
