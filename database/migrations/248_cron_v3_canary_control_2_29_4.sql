INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
('app.version','2.29.4',0,'system'),
('cron_v3.canary.enabled','1',0,'cron_v3'),
('cron_v3.canary.require_shadow_cycles','60',0,'cron_v3'),
('cron_v3.canary.rate_limit','10',0,'cron_v3'),
('cron_v3.canary.local_family','financial_recalc',0,'cron_v3'),
('cron_v3.canary.remote_family','order_enrichment',0,'cron_v3'),
('cron_v3.canary.remote_types','pack_exact,shipment_exact',0,'cron_v3'),
('cron_v3.canary.active_release','2.29.4',0,'cron_v3'),
('cron_v3.canary.phase','not_prepared',0,'cron_v3')
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
is_encrypted=VALUES(is_encrypted),
setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.29.4','Asistente de canario Cron V3 controlado, reversible y limitado a familias certificadas.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
