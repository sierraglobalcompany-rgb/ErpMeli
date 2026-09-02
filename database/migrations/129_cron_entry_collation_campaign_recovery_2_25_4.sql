-- ERP Meli 2.25.4 — arranque observable, collation segura y campañas visibles.
-- Solo modifica metadata técnica. No consulta ni modifica Mercado Libre.

CREATE TABLE IF NOT EXISTS system_cron_entry_states (
    component_key VARCHAR(80) PRIMARY KEY,
    release_version VARCHAR(30) NULL,
    release_build_id VARCHAR(80) NULL,
    stage ENUM(
        'php_opened','bootstrap_loaded','database_connected','installation_validated',
        'lock_acquired','queues_prepared','work_selected','finished',
        'duplicate_skipped','failed_before_bootstrap'
    ) NOT NULL DEFAULT 'php_opened',
    result_state VARCHAR(40) NOT NULL DEFAULT 'running',
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    reached_remote TINYINT(1) NOT NULL DEFAULT 0,
    diagnostic_id VARCHAR(80) NULL,
    observed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    KEY idx_cron_entry_build_recent (release_build_id,observed_at),
    KEY idx_cron_entry_stage (stage,result_state,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_runtime_diagnostic_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cause_key VARCHAR(120) NOT NULL,
    component_key VARCHAR(80) NOT NULL,
    cycle_token VARCHAR(80) NOT NULL,
    diagnostic_id VARCHAR(80) NULL,
    occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_runtime_diagnostic_cycle (cause_key,component_key,cycle_token),
    KEY idx_runtime_diagnostic_recent (component_key,last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE manual_campaigns
    ADD COLUMN IF NOT EXISTS last_scheduler_selected_at DATETIME(3) NULL AFTER next_launcher_at,
    ADD COLUMN IF NOT EXISTS last_scheduler_reason VARCHAR(80) NULL AFTER last_scheduler_selected_at;

-- Únicamente tablas técnicas del runtime. Las tablas comerciales no se alteran.
ALTER TABLE schema_migrations
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE system_component_schema_contracts
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE system_cron_boot_attempts
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE cron_health_checks
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE cron_task_state
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','129_cron_entry_collation_campaign_recovery_2_25_4.sql','structural',1),
('automation_center','129_cron_entry_collation_campaign_recovery_2_25_4.sql','structural',1),
('manual_campaigns','129_cron_entry_collation_campaign_recovery_2_25_4.sql','structural',1)
ON DUPLICATE KEY UPDATE
required_migration=VALUES(required_migration),
contract_kind=VALUES(contract_kind),
enabled=VALUES(enabled);

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('cron.single_launcher_enabled','1','cron',0),
('cron.notifications_dedicated_enabled','0','cron',0),
('cron.entry_marker_enabled','1','cron',0),
('cron.current_build_required_signals','2','cron',0),
('cron.requested_interval_seconds','60','cron',0),
('manual_campaign.directed_lane_enabled','1','manual_campaign',0),
('manual_campaign.hide_eta_without_heartbeat','1','manual_campaign',0),
('manual_campaign.status_summary_always_complete','1','manual_campaign',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.4','Recuperación definitiva del arranque Cron, collation segura y campañas visibles')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
