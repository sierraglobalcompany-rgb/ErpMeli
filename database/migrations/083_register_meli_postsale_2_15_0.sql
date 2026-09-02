INSERT INTO system_modules (module_id,display_name,discovered_version,status)
VALUES ('meli-postsale','Posventa','1.0.0','discovered')
ON DUPLICATE KEY UPDATE discovered_version=VALUES(discovered_version),display_name=VALUES(display_name);
INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES ('module.meli_postsale.enabled','0','modules',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);
INSERT IGNORE INTO app_versions (version,notes) VALUES ('2.15.0','Módulo aislado Meli Posventa');
