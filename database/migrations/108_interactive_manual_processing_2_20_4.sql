-- ERP Meli 2.20.4 — procesamiento manual interactivo, sin lanzador CLI.
-- Aditiva e idempotente. No modifica datos comerciales ni realiza consultas remotas.

ALTER TABLE manual_campaigns
    ADD COLUMN IF NOT EXISTS execution_mode ENUM('legacy_cli','interactive_web')
        NOT NULL DEFAULT 'legacy_cli' AFTER preset,
    ADD COLUMN IF NOT EXISTS progress_model ENUM('legacy_job','unit_v1')
        NOT NULL DEFAULT 'legacy_job' AFTER execution_mode,
    ADD COLUMN IF NOT EXISTS total_units INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_items,
    ADD COLUMN IF NOT EXISTS completed_units INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_units,
    ADD COLUMN IF NOT EXISTS failed_units INT UNSIGNED NOT NULL DEFAULT 0 AFTER completed_units,
    ADD COLUMN IF NOT EXISTS skipped_units INT UNSIGNED NOT NULL DEFAULT 0 AFTER failed_units,
    ADD COLUMN IF NOT EXISTS returned_units INT UNSIGNED NOT NULL DEFAULT 0 AFTER skipped_units,
    ADD COLUMN IF NOT EXISTS control_owner VARCHAR(80) NULL AFTER last_engine_state,
    ADD COLUMN IF NOT EXISTS control_heartbeat_at DATETIME(3) NULL AFTER control_owner,
    ADD COLUMN IF NOT EXISTS control_expires_at DATETIME(3) NULL AFTER control_heartbeat_at,
    ADD COLUMN IF NOT EXISTS step_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER control_expires_at;

ALTER TABLE manual_campaign_items
    ADD COLUMN IF NOT EXISTS total_units INT UNSIGNED NOT NULL DEFAULT 1 AFTER position_no,
    ADD COLUMN IF NOT EXISTS completed_units INT UNSIGNED NOT NULL DEFAULT 0 AFTER total_units,
    ADD COLUMN IF NOT EXISTS failed_units INT UNSIGNED NOT NULL DEFAULT 0 AFTER completed_units,
    ADD COLUMN IF NOT EXISTS skipped_units INT UNSIGNED NOT NULL DEFAULT 0 AFTER failed_units,
    ADD COLUMN IF NOT EXISTS last_step_key CHAR(64) NULL AFTER skipped_units;

CREATE TABLE IF NOT EXISTS manual_campaign_reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manual_campaign_id BIGINT UNSIGNED NOT NULL,
    manual_campaign_item_id BIGINT UNSIGNED NOT NULL,
    queue_key VARCHAR(80) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    lease_generation INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active','released') NOT NULL DEFAULT 'active',
    expires_at DATETIME(3) NOT NULL,
    released_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_manual_campaign_reservation_item (manual_campaign_item_id),
    KEY idx_manual_campaign_reservation_lookup
        (queue_key,source_id,meli_account_id,company_id,status,expires_at),
    KEY idx_manual_campaign_reservation_campaign (manual_campaign_id,status,expires_at),
    CONSTRAINT fk_manual_campaign_reservation_campaign FOREIGN KEY (manual_campaign_id)
        REFERENCES manual_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_manual_campaign_reservation_item FOREIGN KEY (manual_campaign_item_id)
        REFERENCES manual_campaign_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_campaign_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    manual_campaign_id BIGINT UNSIGNED NOT NULL,
    manual_campaign_item_id BIGINT UNSIGNED NULL,
    step_key CHAR(64) NOT NULL,
    browser_owner VARCHAR(80) NOT NULL,
    sequence_no BIGINT UNSIGNED NOT NULL,
    status ENUM('started','completed','failed','discarded') NOT NULL DEFAULT 'started',
    primary_calls SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    derived_calls SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    processed_units INT UNSIGNED NOT NULL DEFAULT 0,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    completed_at DATETIME(3) NULL,
    UNIQUE KEY uq_manual_campaign_step (manual_campaign_id,step_key),
    KEY idx_manual_campaign_step_sequence (manual_campaign_id,sequence_no),
    CONSTRAINT fk_manual_campaign_step_campaign FOREIGN KEY (manual_campaign_id)
        REFERENCES manual_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_control_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='manual_campaigns'
      AND index_name='idx_manual_campaign_control'
);
SET @control_idx_sql = IF(
    @has_control_idx=0,
    'ALTER TABLE manual_campaigns ADD KEY idx_manual_campaign_control (execution_mode,status,control_expires_at)',
    'SELECT 1'
);
PREPARE stmt_control_idx FROM @control_idx_sql;
EXECUTE stmt_control_idx;
DEALLOCATE PREPARE stmt_control_idx;

-- Las campañas creadas por el motor anterior conservan su historia, pero no
-- se reanudan automáticamente con el nuevo contrato interactivo.
UPDATE manual_campaigns
SET execution_mode='legacy_cli',
    progress_model='legacy_job',
    status=IF(status IN ('active','pausing','finishing'),'paused',status),
    current_item_id=NULL,
    control_owner=NULL,
    control_heartbeat_at=NULL,
    control_expires_at=NULL,
    last_engine_state=IF(status='paused','legacy_review',last_engine_state),
    safe_message=IF(
        status='paused',
        'Campaña anterior conservada. Revísela o devuelva sus pendientes antes de crear una nueva.',
        safe_message
    )
WHERE execution_mode='legacy_cli';

UPDATE manual_campaign_items i
JOIN manual_campaigns c ON c.id=i.manual_campaign_id
SET i.status=IF(i.status='running','retry',i.status),
    i.lease_owner=NULL,
    i.lease_expires_at=NULL
WHERE c.execution_mode='legacy_cli' AND i.status='running';

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.interactive_enabled','1','manual_campaign',0),
('manual_campaign.browser_heartbeat_seconds','10','manual_campaign',0),
('manual_campaign.browser_control_ttl_seconds','45','manual_campaign',0),
('manual_campaign.interactive_step_timeout_seconds','12','manual_campaign',0),
('manual_campaign.interactive_single_remote_call','1','manual_campaign',0),
('manual_campaign.cli_worker_retired','1','manual_campaign',0)
ON DUPLICATE KEY UPDATE
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.20.4','Procesamiento manual interactivo por pasos, sin cron ni lanzador manual')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
