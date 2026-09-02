INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('sync.assisted_details_enabled', '1', 'sync', 0),
('sync.overdue_reschedule_default_minutes', '5', 'sync', 0)
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_group=VALUES(setting_group);

UPDATE app_settings
SET setting_value='5'
WHERE setting_key='sync.default_enqueue_delay_minutes'
  AND setting_value NOT IN ('0','5','30','60');

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.4.5', 'Modo asistido avanzado y recuperacion de bloques vencidos para cron');
