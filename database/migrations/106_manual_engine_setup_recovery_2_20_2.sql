-- ERP Meli 2.20.2 — activación directa y diagnóstico humano del motor.
-- Aditiva e idempotente. No consulta ni modifica Mercado Libre.

-- El worker heredado compartía por error la señal del motor de campañas.
-- Se limpia únicamente esa firma conocida; el diagnóstico histórico permanece en Logs.
UPDATE manual_processing_engine_health
SET worker_heartbeat_at=NULL,
    last_worker_result='waiting',
    last_worker_message='Actualización completada. Esperando la primera señal del lanzador de campañas.',
    updated_at=UTC_TIMESTAMP()
WHERE component='process_manual_campaign'
  AND last_worker_message LIKE 'El worker manual no pudo completar el ciclo.%';

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.certification_required','0','manual_campaign',0),
('manual_campaign.conservative_runtime_seconds','25','manual_campaign',0),
('manual_campaign.long_runtime_requires_certification','1','manual_campaign',0),
('manual_campaign.legacy_worker_retired','1','manual_campaign',0),
('manual_campaign.setup_prioritize_release','1','manual_campaign',0)
ON DUPLICATE KEY UPDATE
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.20.2','Activación directa, certificación opcional y separación del worker heredado')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
