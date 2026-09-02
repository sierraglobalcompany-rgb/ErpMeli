INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('module.meli_growth.enabled','0','modules',0),
('growth.sync_window_days','30','modules',0),
('growth.trends_refresh_hours','168','modules',0),
('growth.highlights_refresh_hours','168','modules',0),
('growth.max_categories_per_run','1','modules',0),
('growth.event_sync_enabled','1','modules',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES ('module.rollout.allowed_modules','meli-insights,meli-growth','modules',0)
ON DUPLICATE KEY UPDATE
setting_value=IF(FIND_IN_SET('meli-growth',setting_value)>0,setting_value,CONCAT_WS(',',NULLIF(setting_value,''),'meli-growth')),
setting_group=VALUES(setting_group);

INSERT INTO system_modules (module_id,display_name,discovered_version,status)
VALUES ('meli-growth','Crecimiento comercial','1.1.0','disabled')
ON DUPLICATE KEY UPDATE
display_name=VALUES(display_name),
discovered_version=VALUES(discovered_version),
status=IF(status='enabled','enabled',status);

INSERT IGNORE INTO app_versions (version,notes)
VALUES ('2.19.0','Meli Growth comercial, seguro, aislado y de solo lectura');
