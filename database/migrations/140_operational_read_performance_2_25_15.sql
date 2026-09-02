INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('performance.schema_diagnostic_cache_seconds','60',0,'performance'),
('performance.sales_count_cache_seconds','30',0,'performance'),
('performance.sales_control_cache_seconds','20',0,'performance'),
('performance.progressive_max_parallel','2',0,'performance')
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
is_encrypted=VALUES(is_encrypted),
setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.15','Lecturas paginadas, diagnósticos agrupados y módulos progresivos.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
