-- ERP Meli 2.25.1 — campañas activas con carril dirigido y monitor coherente.
-- Recuperación exclusivamente local. No consulta ni modifica Mercado Libre.

-- Reconstruir los totales desde los elementos, que son la fuente operativa.
UPDATE manual_campaigns c
JOIN (
    SELECT manual_campaign_id,
           COUNT(*) AS total_jobs,
           COALESCE(SUM(total_units),0) AS total_work_units,
           COALESCE(SUM(primary_calls+derived_calls),0) AS saved_outbound
    FROM manual_campaign_items
    GROUP BY manual_campaign_id
) totals ON totals.manual_campaign_id=c.id
SET c.total_items=totals.total_jobs,
    c.total_units=totals.total_work_units,
    c.outbound_calls=GREATEST(c.outbound_calls,totals.saved_outbound),
    c.primary_calls=GREATEST(c.primary_calls,(
        SELECT COALESCE(SUM(i.primary_calls),0)
        FROM manual_campaign_items i
        WHERE i.manual_campaign_id=c.id
    )),
    c.derived_calls=GREATEST(c.derived_calls,(
        SELECT COALESCE(SUM(i.derived_calls),0)
        FROM manual_campaign_items i
        WHERE i.manual_campaign_id=c.id
    )),
    c.safe_message=CASE
        WHEN c.status='active'
        THEN 'Campaña preparada. El lanzador del ERP continuará automáticamente.'
        ELSE c.safe_message
    END,
    c.last_engine_state=CASE
        WHEN c.status='active' THEN 'waiting_cli'
        ELSE c.last_engine_state
    END,
    c.next_launcher_at=CASE
        WHEN c.status='active' THEN UTC_TIMESTAMP(3)
        ELSE c.next_launcher_at
    END,
    c.version_no=c.version_no+1
WHERE c.execution_mode='directed_cli';

-- Cubrir incluso un lanzador irregular de diez minutos durante el primer
-- ciclo posterior a la actualización. El runtime ajustará el TTL usando la
-- mediana observada: max(10 min, intervalo*3 + 2 min).
UPDATE manual_campaign_reservations r
JOIN manual_campaigns c ON c.id=r.manual_campaign_id
SET r.status='active',
    r.renewed_at=UTC_TIMESTAMP(3),
    r.expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 32 MINUTE),
    r.return_reason=NULL
WHERE c.execution_mode='directed_cli'
  AND c.status='active'
  AND r.status='active';

-- Corregir únicamente el texto histórico equivocado; conservar todos los
-- demás eventos y resultados.
UPDATE manual_campaign_events
SET safe_message=REPLACE(
    safe_message,
    'Esta pestaña controla el procesamiento.',
    'El lanzador del ERP continuará automáticamente.'
)
WHERE event_type='campaign_started'
  AND safe_message LIKE '%Esta pestaña controla el procesamiento.%';

INSERT INTO manual_campaign_events
    (manual_campaign_id,event_type,severity,safe_message,block_no)
SELECT c.id,'scheduler_recovered','info',
       'Campaña recuperada. Conserva sus trabajos, unidades, orden y resultados anteriores.',
       GREATEST(1,c.current_block)
FROM manual_campaigns c
WHERE c.execution_mode='directed_cli'
  AND c.status='active'
  AND NOT EXISTS (
      SELECT 1 FROM manual_campaign_events e
      WHERE e.manual_campaign_id=c.id
        AND e.event_type='scheduler_recovered'
  );

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','126_manual_campaign_scheduler_recovery_2_25_1.sql','structural',1),
('manual_campaigns','126_manual_campaign_scheduler_recovery_2_25_1.sql','structural',1)
ON DUPLICATE KEY UPDATE
required_migration=VALUES(required_migration),
contract_kind=VALUES(contract_kind),
enabled=1;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('cron.directed_lane_enabled','1','cron',0),
('cron.directed_lane_tasks_per_run','1','cron',0),
('cron.recommended_interval_seconds','60','cron',0),
('manual_campaign.reservation_ttl_seconds','600','manual_campaign',0),
('manual_campaign.reservation_interval_multiplier','3','manual_campaign',0),
('manual_campaign.status_summary_always_complete','1','manual_campaign',0),
('app.timezone','America/Bogota','system',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.1','Campañas activas con carril dirigido, reservas adaptativas y monitor coherente')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
