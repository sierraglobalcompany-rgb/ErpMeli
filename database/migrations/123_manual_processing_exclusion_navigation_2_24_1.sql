-- ERP Meli 2.24.1 — navegación humana desde trabajos excluidos de Procesar ahora.
-- Aditiva e idempotente. No modifica datos comerciales ni consulta Mercado Libre.

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.exclusion_navigation_enabled','1','manual_campaign',0),
('manual_campaign.exclusion_page_size','50','manual_campaign',0),
('manual_campaign.exclusion_reason_mode','structured','manual_campaign',0)
ON DUPLICATE KEY UPDATE
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.24.1','Procesar ahora explica y enlaza cada trabajo excluido mediante estados estructurados')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
