-- ERP Meli 2.28.9
-- Trazabilidad humana de Cron y campañas dirigidas.
-- Solo agrega metadata técnica y defaults UI; no modifica datos comerciales.

ALTER TABLE manual_campaigns
    ADD COLUMN IF NOT EXISTS last_scheduler_selected_at DATETIME(3) NULL AFTER next_launcher_at,
    ADD COLUMN IF NOT EXISTS last_scheduler_reason VARCHAR(80) NULL AFTER last_scheduler_selected_at;

ALTER TABLE manual_campaign_items
    ADD COLUMN IF NOT EXISTS next_eligible_at DATETIME(3) NULL AFTER lease_expires_at,
    ADD COLUMN IF NOT EXISTS source_resolution VARCHAR(120) NULL AFTER source_checked_at;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','189_cron_campaign_traceability_2_28_9.sql','structural',1),
('automation_center','189_cron_campaign_traceability_2_28_9.sql','structural',1),
('manual_campaigns','189_cron_campaign_traceability_2_28_9.sql','structural',1)
ON DUPLICATE KEY UPDATE
required_migration=VALUES(required_migration),
contract_kind=VALUES(contract_kind),
enabled=VALUES(enabled);

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('cron.campaign_traceability_enabled', '1', 0, 'cron'),
  ('cron.hostinger_output_verbose', '1', 0, 'cron'),
  ('manual_campaign.show_exact_wait_reason', '1', 0, 'manual_campaign'),
  ('manual_campaign.hide_zero_countdown_when_due', '1', 0, 'manual_campaign'),
  ('commercial_path.version', '2.28.9', 0, 'commercial_path'),
  ('operational_audit.last_certified_version', '2.28.9', 0, 'audit')
ON DUPLICATE KEY UPDATE
  setting_value = VALUES(setting_value),
  is_encrypted = VALUES(is_encrypted),
  setting_group = VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.28.9', 'Cron transparente y campañas con avance verificable')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
