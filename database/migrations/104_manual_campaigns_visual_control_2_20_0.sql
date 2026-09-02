-- ERP Meli 2.20.0 — campañas manuales configurables y cabina visual.
-- Las campañas congelan referencias operativas; las colas de dominio conservan la autoridad.

CREATE TABLE IF NOT EXISTS manual_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_token CHAR(40) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    company_scope_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
    scope_key VARCHAR(50) NOT NULL,
    preset ENUM('safe','balanced','custom','repeat') NOT NULL DEFAULT 'safe',
    status ENUM('draft','active','pausing','paused','finishing','completed','completed_with_issues','failed') NOT NULL DEFAULT 'draft',
    configuration_json MEDIUMTEXT NOT NULL,
    version_no BIGINT UNSIGNED NOT NULL DEFAULT 1,
    total_items INT UNSIGNED NOT NULL DEFAULT 0,
    completed_items INT UNSIGNED NOT NULL DEFAULT 0,
    failed_items INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_items INT UNSIGNED NOT NULL DEFAULT 0,
    retry_items INT UNSIGNED NOT NULL DEFAULT 0,
    primary_calls INT UNSIGNED NOT NULL DEFAULT 0,
    derived_calls INT UNSIGNED NOT NULL DEFAULT 0,
    avoided_calls INT UNSIGNED NOT NULL DEFAULT 0,
    transferred_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    current_block INT UNSIGNED NOT NULL DEFAULT 1,
    calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,
    total_blocks INT UNSIGNED NOT NULL DEFAULT 0,
    next_action_at DATETIME(3) NULL,
    current_item_id BIGINT UNSIGNED NULL,
    worker_heartbeat_at DATETIME NULL,
    last_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    started_at DATETIME NULL,
    paused_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_manual_campaign_token (campaign_token),
    KEY idx_manual_campaign_active (status,next_action_at,created_at),
    KEY idx_manual_campaign_user (created_by_user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_campaign_operations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manual_campaign_id BIGINT UNSIGNED NOT NULL,
    queue_key VARCHAR(80) NOT NULL,
    operation_key VARCHAR(80) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NULL,
    item_count INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_primary_calls INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_derived_calls INT UNSIGNED NOT NULL DEFAULT 0,
    block_size SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    requested_interval_ms INT UNSIGNED NOT NULL DEFAULT 0,
    effective_interval_ms INT UNSIGNED NOT NULL DEFAULT 0,
    block_pause_ms INT UNSIGNED NOT NULL DEFAULT 0,
    exact_adapter TINYINT(1) NOT NULL DEFAULT 0,
    safe_adjustment_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_manual_campaign_operation (manual_campaign_id,queue_key,operation_key,meli_account_id),
    KEY idx_manual_campaign_operation_account (company_id,meli_account_id,operation_key),
    CONSTRAINT fk_manual_campaign_operation FOREIGN KEY (manual_campaign_id)
        REFERENCES manual_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_campaign_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manual_campaign_id BIGINT UNSIGNED NOT NULL,
    operation_id BIGINT UNSIGNED NOT NULL,
    queue_key VARCHAR(80) NOT NULL,
    operation_key VARCHAR(80) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NULL,
    human_label VARCHAR(160) NOT NULL,
    content_summary VARCHAR(500) NULL,
    status ENUM('pending','running','waiting','retry','completed','skipped','failed','returned') NOT NULL DEFAULT 'pending',
    position_no INT UNSIGNED NOT NULL,
    block_no INT UNSIGNED NOT NULL DEFAULT 1,
    lease_owner VARCHAR(100) NULL,
    lease_generation INT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME NULL,
    next_eligible_at DATETIME(3) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    primary_calls INT UNSIGNED NOT NULL DEFAULT 0,
    derived_calls INT UNSIGNED NOT NULL DEFAULT 0,
    avoided_calls INT UNSIGNED NOT NULL DEFAULT 0,
    result_summary VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_manual_campaign_source (manual_campaign_id,queue_key,source_id),
    KEY idx_manual_campaign_claim (manual_campaign_id,company_id,meli_account_id,status,next_eligible_at,position_no),
    KEY idx_manual_campaign_lease (lease_expires_at,status),
    CONSTRAINT fk_manual_campaign_item_campaign FOREIGN KEY (manual_campaign_id)
        REFERENCES manual_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_manual_campaign_item_operation FOREIGN KEY (operation_id)
        REFERENCES manual_campaign_operations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_campaign_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manual_campaign_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    severity ENUM('neutral','info','success','warning','error') NOT NULL DEFAULT 'info',
    safe_message VARCHAR(500) NOT NULL,
    manual_campaign_item_id BIGINT UNSIGNED NULL,
    block_no INT UNSIGNED NULL,
    event_data_json TEXT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY idx_manual_campaign_event_cursor (manual_campaign_id,id),
    CONSTRAINT fk_manual_campaign_event_campaign FOREIGN KEY (manual_campaign_id)
        REFERENCES manual_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_campaign_workers (
    worker_id VARCHAR(100) NOT NULL PRIMARY KEY,
    process_token CHAR(40) NOT NULL,
    status VARCHAR(40) NOT NULL,
    campaign_id BIGINT UNSIGNED NULL,
    current_item_id BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL,
    heartbeat_at DATETIME NOT NULL,
    deadline_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    safe_message VARCHAR(500) NULL,
    KEY idx_manual_campaign_worker_health (status,heartbeat_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO manual_processing_engine_health (component,last_worker_result,last_worker_message)
VALUES ('process_manual_campaign','waiting','Esperando la primera ejecución CLI del motor de campañas.')
ON DUPLICATE KEY UPDATE component=VALUES(component);

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.enabled','1','manual_campaign',0),
('manual_campaign.default_preset','safe','manual_campaign',0),
('manual_campaign.default_block_size','30','manual_campaign',0),
('manual_campaign.default_interval_ms','2000','manual_campaign',0),
('manual_campaign.default_block_pause_ms','30000','manual_campaign',0),
('manual_campaign.max_block_size','60','manual_campaign',0),
('manual_campaign.status_event_limit','25','manual_campaign',0),
('manual_campaign.event_retention_days','30','manual_campaign',0),
('manual_campaign.status_poll_running_ms','2000','manual_campaign',0),
('manual_campaign.status_poll_paused_ms','10000','manual_campaign',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.20.0','Campañas manuales configurables, temporizadores persistentes y cabina visual')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
