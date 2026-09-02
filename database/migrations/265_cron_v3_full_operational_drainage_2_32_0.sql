-- ERP Meli 2.32.0 - Cron V3 completo, intervención resoluble y ritmo seguro.
-- Solo metadata/ownership/capacidades. No modifica ventas, órdenes, pagos, packs,
-- envíos, OAuth, cierres ni evidencia fiscal.

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.32.0', 0, 'system'),
  ('cron_v3.full_operational_drainage', '1', 0, 'cron_v3'),
  ('cron_v3.notification_fallback_importer', '1', 0, 'cron_v3'),
  ('cron_v3.legacy_needs_diagnosis_route_fixed', '1', 0, 'cron_v3'),
  ('cron_v3.local_maintenance_launcher', '1', 0, 'cron_v3'),
  ('cron_v3.rate_increase_requires_full_capability', '1', 0, 'cron_v3'),
  ('cron_v3.operational_release', '2.32.0', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO cron_v3_queue_ownership
  (queue_key,lane,owner_engine,enabled,changed_by,changed_at)
VALUES
  ('operational_maintenance','local','v3',1,'migration_265',UTC_TIMESTAMP(3)),
  ('monthly_report_maintenance','local','v3',1,'migration_265',UTC_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE
  lane=VALUES(lane),
  owner_engine=VALUES(owner_engine),
  enabled=VALUES(enabled),
  changed_by=VALUES(changed_by),
  changed_at=VALUES(changed_at);

INSERT INTO cron_v3_capability_matrix
  (queue_key,family,lane,capability_state,work_types_json,reason,updated_at)
VALUES
  ('notification_fallback','notifications','remote','v3_active',
   JSON_ARRAY('order_exact','pack_exact','shipment_exact','question_exact','claim_exact','item_exact'),
   'V3 local importa recursos legacy con identidad segura como trabajos exactos; los registros sin evidencia quedan en diagnóstico local.',
   UTC_TIMESTAMP(3)),
  ('operational_maintenance','maintenance','local','v3_local_only',
   JSON_ARRAY('operational_maintenance','monthly_report_maintenance'),
   'Launcher V3 local toma lock/freeze antes de ejecutar mantenimiento técnico acotado.',
   UTC_TIMESTAMP(3))
ON DUPLICATE KEY UPDATE
  family=VALUES(family),
  lane=VALUES(lane),
  capability_state=VALUES(capability_state),
  work_types_json=VALUES(work_types_json),
  reason=VALUES(reason),
  updated_at=VALUES(updated_at);

INSERT INTO app_versions (version, notes)
VALUES ('2.32.0', 'Cron V3 importa notification_fallback con identidad segura, agrupa legacy correctamente y ejecuta mantenimiento local con lock/freeze.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
