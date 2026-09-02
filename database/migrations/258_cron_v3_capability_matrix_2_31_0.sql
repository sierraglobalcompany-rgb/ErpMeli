-- ERP Meli 2.31.0 - Matriz de capacidades Cron V3 sin tierra de nadie.
-- Solo metadata/operación técnica: no toca órdenes, pagos, packs, envíos, OAuth ni evidencia fiscal.

CREATE TABLE IF NOT EXISTS cron_v3_capability_matrix (
  queue_key VARCHAR(80) NOT NULL,
  family VARCHAR(40) NOT NULL,
  lane VARCHAR(16) NOT NULL,
  capability_state VARCHAR(40) NOT NULL,
  work_types_json JSON NULL,
  reason VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (queue_key),
  KEY idx_cron_v3_capability_state (capability_state),
  KEY idx_cron_v3_capability_lane (lane)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cron_v3_capability_matrix
  (queue_key,family,lane,capability_state,work_types_json,reason)
VALUES
  ('notification_spool','notifications','local','waiting_capability',JSON_ARRAY('notification_spool'),'Falta handler V3 local cercado para mover spool a trabajo exacto.'),
  ('notification_normalize','notifications','local','waiting_capability',JSON_ARRAY('notification_normalize'),'Falta productor V3 que convierta eventos validados en envelopes exactos.'),
  ('notification_fallback','notifications','remote','waiting_capability',JSON_ARRAY('order_exact','pack_exact','shipment_exact','question_exact','claim_exact'),'Backlog legacy requiere diagnóstico/importador exacto antes de moverlo a V3.'),
  ('notification_backfill','notifications','local','waiting_capability',JSON_ARRAY('notification_backfill'),'Falta checkpoint V3 local para backfill sin crear campañas persistentes.'),
  ('recurring_sync','scheduler','local','waiting_capability',JSON_ARRAY('recurring_schedule'),'Falta productor V3 de rangos recurrentes con dedupe por cuenta y periodo.'),
  ('orders_sync','orders','remote','waiting_capability',JSON_ARRAY('orders_search_page','order_exact'),'Handlers V3 existen, pero falta puente que cierre sync_batch_chunks legacy.'),
  ('order_enrichment','orders','remote','v3_active',JSON_ARRAY('pack_exact','shipment_exact'),'Pack y shipment exactos tienen importador legacy, handler y endpoint confirmado.'),
  ('sale_pack_reconciliation','orders','remote','waiting_capability',JSON_ARRAY('pack_exact'),'Falta puente V3 que cierre la cola legacy de reconstrucción de packs.'),
  ('questions','attention','remote','v3_active',JSON_ARRAY('questions_search_page','question_exact'),'Búsqueda y exacto de preguntas tienen handlers confirmados de solo lectura.'),
  ('claims','attention','remote','v3_active',JSON_ARRAY('claims_search_page','claim_exact'),'Búsqueda y exacto de reclamos tienen handlers confirmados de solo lectura.'),
  ('financial_recalc','finance','local','v3_local_only',JSON_ARRAY('financial_local_projection','financial_recalc','financial_gap_scan'),'Trabajo local certificado; no consulta Mercado Libre.'),
  ('sale_financial_reconciliation','finance','remote','v3_active',JSON_ARRAY('sale_billing_capture'),'Captura Billing exacta por venta/input_version con máximo 60 order_ids por solicitud.'),
  ('sales_repair','sales','remote','waiting_capability',JSON_ARRAY('sales_repair_exact'),'Falta handler V3 exacto que cierre sync_sales_repair_jobs sin fan-out oculto.'),
  ('sales_audit','sales','remote','waiting_capability',JSON_ARRAY('sales_audit_page'),'Falta handler V3 de página auditada con cierre exacto de auditoría.'),
  ('sales_fiscal','sales','remote','review_unsupported',JSON_ARRAY('sales_fiscal_exact'),'Endpoint/contrato exacto no certificado para ejecución automática V3.'),
  ('catalog_descriptions','catalog','remote','waiting_capability',JSON_ARRAY('catalog_description_exact'),'Handler exacto existe, pero falta importador que cierre catalog_description_jobs legacy.'),
  ('items_sync','catalog','remote','waiting_capability',JSON_ARRAY('items_search_page','item_exact'),'Handlers V3 existen, pero falta puente que cierre meli_item_sync_jobs legacy.'),
  ('module_jobs','modules','remote','review_unsupported',JSON_ARRAY('module_logistics_exact'),'Solo se permite module_logistics_exact cuando proveedor, tópico y endpoint estén confirmados.'),
  ('operational_maintenance','maintenance','local','waiting_capability',JSON_ARRAY('operational_maintenance','monthly_report_maintenance'),'Falta launcher V3 local con lock/freeze previo para mantenimiento.')
ON DUPLICATE KEY UPDATE
  family=VALUES(family),
  lane=VALUES(lane),
  capability_state=VALUES(capability_state),
  work_types_json=VALUES(work_types_json),
  reason=VALUES(reason),
  updated_at=CURRENT_TIMESTAMP(3);

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.31.0', 0, 'system'),
  ('cron_v3.capability_matrix_version', '2.31.0', 0, 'cron_v3'),
  ('cron_v3.operational_release', '2.31.0', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.31.0', 'Cron V3 publica una matriz única de capacidades y bloquea explícitamente cualquier cola sin camino completo.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
