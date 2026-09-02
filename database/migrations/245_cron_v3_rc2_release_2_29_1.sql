-- Cron V3 RC2 release marker. Activation remains controlled by environment.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron_v3.activation_authority','environment',0,'cron_v3'),
('cron_v3.minimum_mariadb','10.6',0,'cron_v3')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.29.1',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.29.1','Cron V3 RC2: deadline, doctor, fencing de resultados y rate scopes MariaDB')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
