INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
('update.enabled', '0', 0, 'update'),
('update.key_hash', NULL, 0, 'update'),
('update.key_expires_at', NULL, 0, 'update'),
('update.last_attempt_at', NULL, 0, 'update'),
('update.last_attempt_result', NULL, 0, 'update')
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);
