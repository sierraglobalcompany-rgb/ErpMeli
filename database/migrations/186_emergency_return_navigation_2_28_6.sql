-- ERP Meli 2.28.6 — retorno integrado desde freno de mano.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('commercial_path.version','2.28.6',0,'commercial_path'),
    ('operational_audit.last_certified_version','2.28.6',0,'audit'),
    ('emergency_control.return_navigation','1',0,'emergency')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);
