INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('update.migration_drift_recovery_enabled','1','updates',0),
('update.migration_drift_recovery_mode','certified_only','updates',0),
('module.runtime.minimum_core_migration','089_migration_drift_recovery_2_17_2.sql','modules',0)
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version,notes)
VALUES (
  '2.17.2',
  'Recuperación certificada de migración 087 y bloqueo seguro del runtime modular'
);
