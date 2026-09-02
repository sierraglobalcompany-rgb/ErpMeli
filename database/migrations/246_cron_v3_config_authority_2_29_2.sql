-- Cron V3 RC3: autoridad de configuracion resuelta por App\Core\Env.

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron_v3.activation_authority','env_resolver',0,'cron_v3'),
('cron_v3.config_env_supported','1',0,'cron_v3')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.29.2',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.29.2','Cron V3 RC3: config.env canonico y reserva remota antes de acceptUntil')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
