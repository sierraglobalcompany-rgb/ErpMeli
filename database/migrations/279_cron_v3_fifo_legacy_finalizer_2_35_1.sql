-- ERP Meli 2.35.1
-- Cron V3: FIFO explícito y reconciliación de fuentes legacy importadas.
-- Solo estructura/metadata técnica. No toca datos comerciales, OAuth,
-- cierres mensuales ni evidencia fiscal.

SET @has_arrival_seq = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema=DATABASE()
    AND table_name='cron_v3_work'
    AND column_name='arrival_seq'
);

SET @sql_arrival_seq = IF(
  @has_arrival_seq=0,
  'ALTER TABLE cron_v3_work ADD COLUMN arrival_seq BIGINT UNSIGNED NULL AFTER id',
  'SELECT 1'
);
PREPARE stmt_arrival_seq FROM @sql_arrival_seq;
EXECUTE stmt_arrival_seq;
DEALLOCATE PREPARE stmt_arrival_seq;

UPDATE cron_v3_work
SET arrival_seq=id
WHERE arrival_seq IS NULL;

SET @idx_fifo_arrival = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema=DATABASE()
    AND table_name='cron_v3_work'
    AND index_name='idx_cron_v3_work_fifo_arrival_claim'
);

SET @sql_fifo_arrival = IF(
  @idx_fifo_arrival=0,
  'ALTER TABLE cron_v3_work ADD KEY idx_cron_v3_work_fifo_arrival_claim (lane,status,available_at,arrival_seq,id)',
  'SELECT 1'
);
PREPARE stmt_fifo_arrival FROM @sql_fifo_arrival;
EXECUTE stmt_fifo_arrival;
DEALLOCATE PREPARE stmt_fifo_arrival;

INSERT INTO cron_v3_capability_matrix
  (queue_key,family,lane,capability_state,work_types_json,reason,updated_at)
VALUES
  ('orders_sync','orders','remote','v3_active',
   JSON_ARRAY('orders_search_page','order_exact'),
   'V3 local importa chunks legacy a FIFO y V3 remoto cierra el chunk por página, sin fan-out oculto.',
   UTC_TIMESTAMP(3)),
  ('items_sync','catalog','remote','v3_active',
   JSON_ARRAY('items_search_page','item_exact'),
   'V3 local importa trabajos legacy de publicaciones a FIFO y V3 remoto cierra el job por página.',
   UTC_TIMESTAMP(3)),
  ('catalog_descriptions','catalog','remote','v3_active',
   JSON_ARRAY('catalog_description_exact'),
   'V3 local importa ítems de descripción y V3 remoto cierra cada ítem exacto con diagnóstico seguro.',
   UTC_TIMESTAMP(3)),
  ('order_enrichment','orders','remote','v3_active',
   JSON_ARRAY('pack_exact','shipment_exact'),
   'Pack y shipment exactos cierran la fuente legacy de enriquecimiento después del resultado V3 cercado.',
   UTC_TIMESTAMP(3)),
  ('sale_financial_reconciliation','finance','remote','v3_active',
   JSON_ARRAY('sale_billing_capture'),
   'Captura Billing exacta por venta/input_version y cierre legacy cercado por empresa/cuenta.',
   UTC_TIMESTAMP(3)),
  ('financial_recalc','finance','local','v3_local_only',
   JSON_ARRAY('financial_local_projection','financial_recalc','financial_gap_scan'),
   'Trabajo local certificado; no consulta Mercado Libre y cierra la fuente técnica al finalizar.',
   UTC_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE
  family=VALUES(family),
  lane=VALUES(lane),
  capability_state=VALUES(capability_state),
  work_types_json=VALUES(work_types_json),
  reason=VALUES(reason),
  updated_at=VALUES(updated_at);

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.35.1', 0, 'system'),
  ('cron_v3.fifo_arrival_seq_enabled', '1', 0, 'cron_v3'),
  ('cron_v3.legacy_source_finalizer_release', '2.35.1', 0, 'cron_v3'),
  ('cron_v3.capability_matrix_release', '2.35.1', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (`version`, `notes`)
VALUES ('2.35.1', 'Cron V3 reclama FIFO por llegada explícita y reconcilia fuentes legacy importadas sin repetir consultas remotas.')
ON DUPLICATE KEY UPDATE
  `notes`=VALUES(`notes`),
  installed_at=UTC_TIMESTAMP();
