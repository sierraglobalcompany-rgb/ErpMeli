-- ERP Meli 2.25.7 — parada de emergencia independiente de la base de datos.
-- El bloqueo efectivo reside en PAUSE_MELI_API y se evalúa antes del transporte remoto.
-- Esta migración solo registra la versión y el estado operativo.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('api.emergency_stop_expected','1','api_guard',0),
('api.emergency_stop_reason','Mantenimiento preventivo 2.25.7','api_guard',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.7','Parada local de emergencia para toda salida hacia Mercado Libre')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
