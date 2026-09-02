-- ERP Meli 2.35.0 - Cobertura real Cron V3 sin tierra de nadie.
-- Solo metadata/operación técnica. No modifica ventas, órdenes, pagos, packs,
-- envíos, OAuth, cierres ni evidencia fiscal.

INSERT INTO cron_v3_capability_matrix
  (queue_key,family,lane,capability_state,work_types_json,reason,updated_at)
VALUES
  ('notification_spool','notifications','local','v3_local_only',
   JSON_ARRAY('notification_spool'),
   'V3 local mueve spool validado a eventos locales sin consultar Mercado Libre.',
   UTC_TIMESTAMP(3)),
  ('notification_normalize','notifications','local','v3_local_only',
   JSON_ARRAY('notification_normalize'),
   'V3 local convierte eventos validados en trabajos exactos FIFO; lo incompleto queda parqueado por identidad.',
   UTC_TIMESTAMP(3)),
  ('notification_backfill','notifications','local','v3_local_only',
   JSON_ARRAY('notification_backfill'),
   'V3 local ejecuta backfill acotado y checkpointed sin campañas persistentes.',
   UTC_TIMESTAMP(3)),
  ('recurring_sync','scheduler','local','v3_local_only',
   JSON_ARRAY('recurring_schedule'),
   'V3 local prepara rangos recurrentes con dedupe por cuenta y periodo.',
   UTC_TIMESTAMP(3)),
  ('sale_pack_reconciliation','orders','remote','v3_active',
   JSON_ARRAY('sale_pack_reconciliation_exact'),
   'V3 importa la cola legacy de reconstrucción y ejecuta un job exacto por intento remoto.',
   UTC_TIMESTAMP(3)),
  ('sales_audit','sales','remote','v3_active',
   JSON_ARRAY('sales_audit_page'),
   'V3 ejecuta una página auditada por intento remoto y conserva cierre exacto del job.',
   UTC_TIMESTAMP(3)),
  ('sales_repair','sales','remote','v3_active',
   JSON_ARRAY('sales_repair_exact'),
   'V3 ejecuta reparación exacta de auditoría un ítem por intento y conserva fencing del job.',
   UTC_TIMESTAMP(3)),
  ('sales_fiscal','sales','remote','review_unsupported',
   JSON_ARRAY('sales_fiscal_exact'),
   'Endpoint/contrato exacto no certificado para ejecución automática V3.',
   UTC_TIMESTAMP(3)),
  ('module_jobs','modules','remote','review_unsupported',
   JSON_ARRAY('module_logistics_exact'),
   'Solo se permite module_logistics_exact cuando proveedor, tópico y endpoint estén confirmados.',
   UTC_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE
  family=VALUES(family),
  lane=VALUES(lane),
  capability_state=VALUES(capability_state),
  work_types_json=VALUES(work_types_json),
  reason=VALUES(reason),
  updated_at=VALUES(updated_at);

INSERT INTO cron_v3_queue_ownership (queue_key,lane,owner_engine,enabled,changed_by,changed_at)
VALUES
  ('notification_spool','local','v3',1,'release_2_35_0_full_queue_coverage',UTC_TIMESTAMP(3)),
  ('notification_normalize','local','v3',1,'release_2_35_0_full_queue_coverage',UTC_TIMESTAMP(3)),
  ('notification_backfill','local','v3',1,'release_2_35_0_full_queue_coverage',UTC_TIMESTAMP(3)),
  ('recurring_schedule','local','v3',1,'release_2_35_0_full_queue_coverage',UTC_TIMESTAMP(3)),
  ('sale_pack_reconciliation_exact','remote','v3',1,'release_2_35_0_full_queue_coverage',UTC_TIMESTAMP(3)),
  ('sales_audit_page','remote','v3',1,'release_2_35_0_full_queue_coverage',UTC_TIMESTAMP(3)),
  ('sales_repair_exact','remote','v3',1,'release_2_35_0_full_queue_coverage',UTC_TIMESTAMP(3)),
  ('sales_fiscal_exact','remote','disabled',0,'release_2_35_0_review_unsupported',UTC_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE
  lane=VALUES(lane),
  owner_engine=VALUES(owner_engine),
  enabled=VALUES(enabled),
  changed_by=VALUES(changed_by),
  changed_at=VALUES(changed_at);

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.35.0', 0, 'system'),
  ('cron_v3.capability_matrix_release', '2.35.0', 0, 'cron_v3'),
  ('cron_v3.full_queue_coverage', '1', 0, 'cron_v3'),
  ('cron_v3.fifo_local_producers_enabled', '1', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.35.0', 'Cron V3 cierra brechas operativas: productores locales, FIFO ejecutable, reconstrucción de packs, auditoría y reparación exacta.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
