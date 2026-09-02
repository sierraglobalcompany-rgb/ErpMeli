INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('safety.status_authority','filesystem',0,'system'),
('sales_control.unknown_values_as_zero','0',0,'sales_control')
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
is_encrypted=VALUES(is_encrypted),
setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.14','Freno de mano recuperado y estados de seguridad coherentes.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
