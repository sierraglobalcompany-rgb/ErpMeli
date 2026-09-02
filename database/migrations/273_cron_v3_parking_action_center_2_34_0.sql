-- ERP Meli 2.34.0 - Centro de parqueados y versión final.
-- Solo metadata operacional segura.

CREATE TABLE IF NOT EXISTS cron_v3_parking_action_policies (
  reason_code VARCHAR(100) NOT NULL,
  human_group VARCHAR(120) NOT NULL,
  primary_action VARCHAR(80) NOT NULL,
  safe_message VARCHAR(255) NOT NULL,
  allow_retry_without_http TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (reason_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cron_v3_parking_action_policies
  (reason_code,human_group,primary_action,safe_message,allow_retry_without_http)
VALUES
  ('missing_safe_identity','Falta identidad','diagnose_local','El ERP necesita reconstruir localmente la identidad antes de reintentar.',0),
  ('final_work_type_not_owned','Falta capacidad V3','keep_parked','Este tipo aún no tiene dueño V3 completo; no bloquea otros trabajos.',0),
  ('endpoint_not_confirmed','Endpoint no confirmado','keep_blocked','No se ejecutará hasta que el endpoint esté confirmado en el mapa oficial.',0),
  ('waiting_rate','Ritmo o presupuesto','retry_next_cron','Se reintentará automáticamente cuando la protección lo permita.',1),
  ('remote_result_uncertain','Resultado remoto incierto','keep_blocked','No se reintentará a ciegas hasta revisar la evidencia.',0),
  ('legacy_needs_diagnosis','Registro anterior a trazabilidad','diagnose_local','Primero se diagnostica localmente; no se hace consulta remota desde el navegador.',0)
ON DUPLICATE KEY UPDATE
  human_group=VALUES(human_group),
  primary_action=VALUES(primary_action),
  safe_message=VALUES(safe_message),
  allow_retry_without_http=VALUES(allow_retry_without_http),
  updated_at=UTC_TIMESTAMP(3);

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.34.0', 0, 'system'),
  ('cron_v3.parking_action_center', '1', 0, 'cron_v3'),
  ('cron_v3.operational_release', '2.34.0', 0, 'cron_v3')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.34.0', 'Cron V3 unifica ritmo, FIFO reentrante, snapshot operacional y centro de trabajos parqueados con UI humana.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
