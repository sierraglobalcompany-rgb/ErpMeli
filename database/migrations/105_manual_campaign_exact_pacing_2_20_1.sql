-- ERP Meli 2.20.1 — conteo real y ritmo exacto por operación.
-- Aditiva e idempotente; no modifica datos comerciales.

ALTER TABLE manual_campaigns
    ADD COLUMN IF NOT EXISTS current_operation_id BIGINT UNSIGNED NULL AFTER current_item_id;

SET @has_current_operation_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='manual_campaigns'
      AND index_name='idx_manual_campaign_current_operation'
);
SET @current_operation_idx_sql = IF(
    @has_current_operation_idx=0,
    'ALTER TABLE manual_campaigns ADD KEY idx_manual_campaign_current_operation (current_operation_id,status)',
    'SELECT 1'
);
PREPARE stmt_current_operation_idx FROM @current_operation_idx_sql;
EXECUTE stmt_current_operation_idx;
DEALLOCATE PREPARE stmt_current_operation_idx;

-- Repara el estado contradictorio observado cuando una cuarta prueba corta
-- reemplazó la etiqueta, aunque ya existían las tres ventanas aprobadas.
UPDATE manual_processing_engine_health h
SET h.certification_status='certified',
    h.certified_at=COALESCE(h.certified_at,UTC_TIMESTAMP()),
    h.last_check_result='ok',
    h.last_check_message='Motor certificado con ventanas aprobadas de 10, 30 y 55 segundos.'
WHERE h.component='process_manual_campaign'
  AND (
      SELECT COUNT(DISTINCT p.requested_window_seconds)
      FROM manual_engine_probe_runs p
      WHERE p.status='passed'
        AND p.requested_window_seconds IN (10,30,55)
  )=3;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.exact_remote_pacing_enabled','1','manual_campaign',0),
('manual_campaign.real_call_counter_enabled','1','manual_campaign',0)
ON DUPLICATE KEY UPDATE
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.20.1','Ritmo exacto por consulta, conteo remoto real y bloques aislados por operación')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
