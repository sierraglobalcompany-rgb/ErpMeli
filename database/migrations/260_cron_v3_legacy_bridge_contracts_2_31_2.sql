-- ERP Meli 2.31.2 - Contratos de productores/importadores V3 para colas grandes.
-- Solo metadata/ownership contractual; el CLI local materializa trabajo exacto y V3 remoto consume cron_v3_work.

INSERT INTO cron_v3_capability_matrix
  (queue_key,family,lane,capability_state,work_types_json,reason)
VALUES
  ('orders_sync','orders','remote','v3_active',JSON_ARRAY('orders_search_page','order_exact'),'V3 local importa chunks legacy como páginas exactas y V3 remoto ejecuta orders_search_page/order_exact.'),
  ('catalog_descriptions','catalog','remote','v3_active',JSON_ARRAY('catalog_description_exact'),'V3 local importa ítems de descripción pendientes como catalog_description_exact.'),
  ('items_sync','catalog','remote','v3_active',JSON_ARRAY('items_search_page','item_exact'),'V3 local importa trabajos de publicaciones como items_search_page/item_exact.'),
  ('manual_campaign','manual','local','legacy_readonly_backlog',JSON_ARRAY(),'Campañas heredadas quedan como diagnóstico; V3 no recrea ni continúa campañas persistentes.')
ON DUPLICATE KEY UPDATE
  family=VALUES(family),
  lane=VALUES(lane),
  capability_state=VALUES(capability_state),
  work_types_json=VALUES(work_types_json),
  reason=VALUES(reason),
  updated_at=CURRENT_TIMESTAMP(3);

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.31.2', 0, 'system'),
  ('cron_v3.legacy_bridge_contracts_version', '2.31.2', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.31.2', 'V3 declara productores/importadores exactos para órdenes, publicaciones y descripciones; colas no seguras quedan bloqueadas explícitamente.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
