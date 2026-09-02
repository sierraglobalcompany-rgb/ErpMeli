-- ERP Meli 2.28.52 — planner orientado a capacidad y recurso exacto.

INSERT IGNORE INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.planner.selected_equals_claimed','1',0,'cron'),
('cron.planner.try_shorter_queue_after_deadline','1',0,'cron'),
('cron.planner.concurrent_claim_fencing','1',0,'cron');

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.28.52',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.52','Planner incremental por capacidad: un not_started no cierra todo el ciclo')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
