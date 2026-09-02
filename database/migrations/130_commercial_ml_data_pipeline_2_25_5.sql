-- ERP Meli 2.25.5 - canal comercial de datos Mercado Libre.
-- Aditiva e idempotente. No modifica Mercado Libre ni elimina evidencia local.

ALTER TABLE sale_financial_reconciliation_jobs
    MODIFY COLUMN status ENUM(
        'pending','running','retry','awaiting_remote','complete','partial',
        'review','error','paused','cancelled'
    ) NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS remote_pending_since DATETIME NULL AFTER attempts,
    ADD COLUMN IF NOT EXISTS retry_until DATETIME NULL AFTER remote_pending_since,
    ADD COLUMN IF NOT EXISTS last_remote_state VARCHAR(40) NULL AFTER retry_until,
    ADD COLUMN IF NOT EXISTS priority_tier TINYINT UNSIGNED NOT NULL DEFAULT 30 AFTER last_remote_state,
    ADD COLUMN IF NOT EXISTS origin_type VARCHAR(40) NULL AFTER priority_tier,
    ADD COLUMN IF NOT EXISTS origin_id BIGINT UNSIGNED NULL AFTER origin_type;

SET @has_financial_priority_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='sale_financial_reconciliation_jobs'
      AND index_name='idx_sale_financial_priority_due'
);
SET @sql_financial_priority_idx = IF(
    @has_financial_priority_idx=0,
    'ALTER TABLE sale_financial_reconciliation_jobs
       ADD KEY idx_sale_financial_priority_due (status,priority_tier,next_run_at,id)',
    'SELECT 1'
);
PREPARE stmt_financial_priority_idx FROM @sql_financial_priority_idx;
EXECUTE stmt_financial_priority_idx;
DEALLOCATE PREPARE stmt_financial_priority_idx;

CREATE TABLE IF NOT EXISTS api_request_pacing_state (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scope_key VARCHAR(190) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    operation_key VARCHAR(80) NULL,
    effective_rpm TINYINT UNSIGNED NOT NULL DEFAULT 1,
    next_allowed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_reserved_at DATETIME(6) NULL,
    last_wait_ms INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_api_pacing_scope (scope_key),
    KEY idx_api_pacing_account (meli_account_id,updated_at),
    KEY idx_api_pacing_next (next_allowed_at),
    CONSTRAINT fk_api_pacing_account
        FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE meli_item_sync_jobs
    ADD COLUMN IF NOT EXISTS cursor_expires_at DATETIME NULL AFTER cursor_value,
    ADD COLUMN IF NOT EXISTS cursor_restart_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER cursor_expires_at;

ALTER TABLE meli_product_update_reviews
    ADD COLUMN IF NOT EXISTS company_id BIGINT UNSIGNED NULL AFTER id;

UPDATE meli_product_update_reviews r
JOIN meli_accounts a ON a.id=r.meli_account_id
SET r.company_id=a.company_id
WHERE r.company_id IS NULL;

ALTER TABLE meli_product_update_reviews
    MODIFY COLUMN company_id BIGINT UNSIGNED NOT NULL;

SET @has_product_review_company_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='meli_product_update_reviews'
      AND index_name='idx_product_reviews_company_account'
);
SET @sql_product_review_company_idx = IF(
    @has_product_review_company_idx=0,
    'ALTER TABLE meli_product_update_reviews
       ADD KEY idx_product_reviews_company_account (company_id,meli_account_id,status,created_at)',
    'SELECT 1'
);
PREPARE stmt_product_review_company_idx FROM @sql_product_review_company_idx;
EXECUTE stmt_product_review_company_idx;
DEALLOCATE PREPARE stmt_product_review_company_idx;

SET @has_product_review_company_fk = (
    SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema=DATABASE()
      AND table_name='meli_product_update_reviews'
      AND constraint_name='fk_product_reviews_company'
);
SET @sql_product_review_company_fk = IF(
    @has_product_review_company_fk=0,
    'ALTER TABLE meli_product_update_reviews
       ADD CONSTRAINT fk_product_reviews_company
       FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt_product_review_company_fk FROM @sql_product_review_company_fk;
EXECUTE stmt_product_review_company_fk;
DEALLOCATE PREPARE stmt_product_review_company_fk;

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('sales_financial.commercial_pipeline_enabled','1','sales_financial',0),
('sales_financial.remote_retry_days','30','sales_financial',0),
('sales_financial.auto_queue_notifications','1','sales_financial',0),
('sales_financial.auto_queue_repairs','1','sales_financial',0),
('items.hybrid_notification_updates_enabled','1','items',0),
('items.hybrid_bulk_updates_enabled','1','items',0),
('items.scroll_cursor_ttl_seconds','300','items',0),
('api.pacing.enabled','1','api_pacing',0),
('api.pacing.ceiling_rpm','20','api_pacing',0),
('api.pacing.minimum_samples','20','api_pacing',0),
('api.pacing.safe_p95_duration_ms','5000','api_pacing',0),
('api.pacing.max_wait_seconds','55','api_pacing',0),
('api.pacing.adaptive_enabled','1','api_pacing',0)
ON DUPLICATE KEY UPDATE
setting_group=VALUES(setting_group),
is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('commercial_ml_pipeline','130_commercial_ml_data_pipeline_2_25_5.sql','structural',1)
ON DUPLICATE KEY UPDATE
required_migration=VALUES(required_migration),
contract_kind=VALUES(contract_kind),
enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.5','Automatizacion comercial de ventas, finanzas y publicaciones Mercado Libre')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
