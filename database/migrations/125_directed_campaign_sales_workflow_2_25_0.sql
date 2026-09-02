-- ERP Meli 2.25.0 — campañas dirigidas y control anual idempotente.
-- Aditiva e idempotente. No modifica datos comerciales ni consulta Mercado Libre.

ALTER TABLE manual_campaign_reservations
    ADD COLUMN IF NOT EXISTS renewed_at DATETIME(3) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS return_reason VARCHAR(120) NULL AFTER expires_at;

UPDATE manual_campaign_reservations
SET renewed_at=COALESCE(renewed_at,updated_at,created_at),
    expires_at=GREATEST(expires_at,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 15 MINUTE))
WHERE status='active'
  AND manual_campaign_id IN (
      SELECT id FROM manual_campaigns
      WHERE execution_mode='directed_cli' AND status IN ('active','pausing','paused')
  );

CREATE TABLE IF NOT EXISTS sales_control_year_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    control_year SMALLINT UNSIGNED NOT NULL,
    status ENUM('active','waiting','completed','completed_with_issues','paused')
        NOT NULL DEFAULT 'active',
    last_requested_month TINYINT UNSIGNED NOT NULL,
    primary_months_completed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    verification_months_completed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    current_primary_month TINYINT UNSIGNED NULL,
    current_verification_month TINYINT UNSIGNED NULL,
    safe_message VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    completed_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_control_year_run (company_id,meli_account_id,control_year),
    KEY idx_sales_control_year_run_status (company_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sales_control_year_runs
    (company_id,meli_account_id,control_year,status,last_requested_month,created_by,safe_message)
SELECT y.company_id,y.meli_account_id,y.control_year,
       IF(y.status='checking','active','waiting'),
       LEAST(12,GREATEST(1,
           IF(y.control_year=YEAR(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00')),
              MONTH(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00')),12))),
       y.created_by,
       'Ejecución anual recuperada sin modificar sus auditorías anteriores.'
FROM sales_control_years y
WHERE y.status IN ('checking','needs_review','partial')
ON DUPLICATE KEY UPDATE
    last_requested_month=GREATEST(last_requested_month,VALUES(last_requested_month)),
    updated_at=UTC_TIMESTAMP(3);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','125_directed_campaign_sales_workflow_2_25_0.sql','structural',1),
('manual_campaigns','125_directed_campaign_sales_workflow_2_25_0.sql','structural',1),
('sales_control','125_directed_campaign_sales_workflow_2_25_0.sql','structural',1)
ON DUPLICATE KEY UPDATE
required_migration=VALUES(required_migration),contract_kind=VALUES(contract_kind),enabled=1;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('manual_campaign.reservation_ttl_seconds','900','manual_campaign',0),
('manual_campaign.reservation_interval_multiplier','3','manual_campaign',0),
('manual_campaign.directed_priority','45','manual_campaign',0),
('sales_control.annual_coordinator_enabled','1','sales_control',0),
('sales_control.primary_lane_first','1','sales_control',0)
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.0','Campañas dirigidas exactas, reservas adaptativas y control anual idempotente')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
