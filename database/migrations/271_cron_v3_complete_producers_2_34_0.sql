-- ERP Meli 2.34.0 - Matriz explícita de productores V3.
-- Las colas sin camino completo quedan declaradas; no vuelven a V2 ni se
-- muestran como listas.

INSERT INTO cron_v3_capability_matrix
  (queue_key,family,lane,capability_state,work_types_json,reason,updated_at)
VALUES
  ('notification_spool','notifications','local','waiting_capability',
   JSON_ARRAY('notification_spool'),
   'Capacidad pendiente: falta handler V3 local cercado para mover spool a envelopes exactos sin consultar Mercado Libre.',
   UTC_TIMESTAMP(3)),
  ('notification_normalize','notifications','local','waiting_capability',
   JSON_ARRAY('notification_normalize'),
   'Capacidad pendiente: falta productor V3 local que normalice eventos validados y deduplique por recurso.',
   UTC_TIMESTAMP(3)),
  ('notification_backfill','notifications','local','waiting_capability',
   JSON_ARRAY('notification_backfill'),
   'Capacidad pendiente: falta checkpoint V3 local para backfill sin crear campañas persistentes.',
   UTC_TIMESTAMP(3)),
  ('notification_fallback','notifications','remote','v3_active',
   JSON_ARRAY('order_exact','pack_exact','shipment_exact','question_exact','claim_exact','item_exact'),
   'V3 local importa por estado y antigüedad; los recursos con identidad segura entran a FIFO y los incompletos se parquean.',
   UTC_TIMESTAMP(3)),
  ('recurring_sync','scheduler','local','waiting_capability',
   JSON_ARRAY('recurring_schedule'),
   'Capacidad pendiente: falta productor V3 de rangos recurrentes con dedupe por cuenta y periodo.',
   UTC_TIMESTAMP(3)),
  ('sale_pack_reconciliation','orders','remote','waiting_capability',
   JSON_ARRAY('pack_exact'),
   'Capacidad pendiente: falta puente que cierre la cola legacy de reconstrucción de packs por recurso exacto.',
   UTC_TIMESTAMP(3)),
  ('sales_repair','sales','remote','waiting_capability',
   JSON_ARRAY('sales_repair_exact'),
   'Capacidad pendiente: falta handler V3 exacto que cierre sync_sales_repair_jobs sin fan-out oculto.',
   UTC_TIMESTAMP(3)),
  ('sales_audit','sales','remote','waiting_capability',
   JSON_ARRAY('sales_audit_page'),
   'Capacidad pendiente: falta handler V3 de página auditada con cierre exacto de auditoría.',
   UTC_TIMESTAMP(3)),
  ('module_jobs','modules','remote','review_unsupported',
   JSON_ARRAY('module_logistics_exact'),
   'No soportado automáticamente: solo se permitirán módulos con proveedor, tópico y endpoint confirmados.',
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
  ('cron_v3.capability_matrix_release', '2.34.0', 0, 'cron_v3'),
  ('cron_v3.no_owner_means_waiting_capability', '1', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);
