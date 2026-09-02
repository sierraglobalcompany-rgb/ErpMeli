INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('api.health.overview_cache_seconds','30','api_guard',0),
('api.health.overview_incident_limit','3','api_guard',0),
('api.health.recent_activity_limit','5','api_guard',0),
('api.health.technical_page_size','50','api_guard',0),
('api.health.default_period_hours','24','api_guard',0),
('api.health.progressive_sections_enabled','1','api_guard',0)
ON DUPLICATE KEY UPDATE
  setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES (
  '2.17.3',
  'Centro de Salud Mercado Libre operativo, accesible y optimizado'
);
