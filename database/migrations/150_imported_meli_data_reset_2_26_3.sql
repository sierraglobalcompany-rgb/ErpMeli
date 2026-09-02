CREATE TABLE IF NOT EXISTS imported_data_reset_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    backup_id BIGINT UNSIGNED NULL,
    status ENUM(
        'analyzed','authorized','running','pausing','paused',
        'verifying','completed','failed'
    ) NOT NULL DEFAULT 'analyzed',
    generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    phase VARCHAR(60) NOT NULL DEFAULT 'analysis',
    phase_position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    cursor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    scope_json JSON NOT NULL,
    plan_json JSON NOT NULL,
    counters_json JSON NOT NULL,
    integrity_before_json JSON NOT NULL,
    integrity_after_json JSON NULL,
    integrity_sha256 CHAR(64) NOT NULL,
    confirmation_challenge VARCHAR(32) NOT NULL,
    authorized_at DATETIME(3) NULL,
    lease_owner VARCHAR(120) NULL,
    lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME(3) NULL,
    heartbeat_at DATETIME(3) NULL,
    safe_message VARCHAR(500) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    started_at DATETIME(3) NULL,
    paused_at DATETIME(3) NULL,
    completed_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_imported_reset_public (public_id),
    KEY idx_imported_reset_active (status,lease_expires_at,id),
    KEY idx_imported_reset_user (requested_by,id),
    CONSTRAINT fk_imported_reset_user
        FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_imported_reset_backup
        FOREIGN KEY (backup_id) REFERENCES system_backup_archives(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imported_data_reset_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reset_request_id BIGINT UNSIGNED NOT NULL,
    sequence_no BIGINT UNSIGNED NOT NULL,
    generation BIGINT UNSIGNED NOT NULL,
    phase VARCHAR(60) NOT NULL,
    dataset_key VARCHAR(80) NOT NULL,
    status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    rows_reviewed INT UNSIGNED NOT NULL DEFAULT 0,
    rows_deleted INT UNSIGNED NOT NULL DEFAULT 0,
    rows_retained INT UNSIGNED NOT NULL DEFAULT 0,
    cursor_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
    cursor_after BIGINT UNSIGNED NOT NULL DEFAULT 0,
    safe_message VARCHAR(500) NULL,
    diagnostic_id VARCHAR(80) NULL,
    started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    completed_at DATETIME(3) NULL,
    UNIQUE KEY uq_imported_reset_sequence (reset_request_id,sequence_no),
    KEY idx_imported_reset_steps (reset_request_id,status,sequence_no),
    CONSTRAINT fk_imported_reset_step_request
        FOREIGN KEY (reset_request_id)
        REFERENCES imported_data_reset_requests(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imported_data_reset_generations (
    meli_account_id BIGINT UNSIGNED PRIMARY KEY,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    last_reset_request_id BIGINT UNSIGNED NULL,
    reset_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ON UPDATE CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_imported_reset_generation_account
        FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_imported_reset_generation_request
        FOREIGN KEY (last_reset_request_id)
        REFERENCES imported_data_reset_requests(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS imported_data_reset_table_policy (
    table_name VARCHAR(128) PRIMARY KEY,
    action ENUM('preserve','delete','reset','ignore') NOT NULL DEFAULT 'preserve',
    policy_version INT UNSIGNED NOT NULL DEFAULT 1,
    classified_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    note VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO imported_data_reset_table_policy (table_name,action,note)
SELECT TABLE_NAME,'preserve','Conservación predeterminada y explícita al instalar la política.'
FROM information_schema.TABLES
WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
  AND TABLE_TYPE='BASE TABLE';

UPDATE imported_data_reset_table_policy
SET action='delete',note='Conjunto permitido por la política conservadora 2.26.3.'
WHERE table_name IN (
    'api_error_logs','api_request_logs','system_work_queue_projection',
    'app_notifications',
    'question_notifications','meli_questions',
    'meli_claims','meli_notification_work_items','meli_notification_events',
    'meli_webhook_events','order_financial_recalc_jobs',
    'order_resource_enrichment_jobs','meli_item_sync_jobs','catalog_description_jobs',
    'catalog_description_job_items',
    'sync_sales_audit_jobs','sync_batches','meli_sync_runs',
    'meli_sync_logs','meli_order_billing_details','meli_order_financials',
    'meli_payments','meli_shipments','meli_orders',
    'meli_packs','meli_items','meli_api_capabilities',
    'ml_insights_account_capabilities','ml_insights_catalog_competition',
    'ml_insights_item_performance','ml_insights_item_prices',
    'ml_insights_moderations','ml_insights_price_history',
    'ml_insights_reputation_snapshots','ml_insights_sync_jobs',
    'manual_campaigns','manual_campaign_events','manual_campaign_items',
    'manual_campaign_operations','manual_campaign_steps',
    'manual_campaign_preview_items','manual_campaign_previews',
    'manual_campaign_reservations','manual_processing_sessions',
    'manual_processing_events','manual_processing_items','manual_processing_scopes',
    'meli_notification_recovery_run_items','meli_product_update_review_items',
    'meli_product_update_reviews','product_match_suggestions',
    'order_datetime_repair_jobs',
    'sales_control_fiscal_job_items','sales_control_fiscal_jobs','sales_control_year_runs',
    'sale_financial_reconciliation_jobs','sale_pack_rebuild_runs',
    'sale_pack_reconciliation_jobs','sync_sales_repair_job_items','sync_sales_repair_jobs'
);

UPDATE imported_data_reset_table_policy
SET action='delete',note='Hija eliminada únicamente por cascada de una identidad autorizada.'
WHERE table_name IN (
    'question_notifications','meli_claim_events','meli_shipment_history',
    'meli_shipment_labels','meli_order_items','meli_pack_orders',
    'meli_item_variations','meli_item_pictures','meli_item_attributes',
    'meli_item_descriptions','meli_item_stock_locations',
    'meli_product_update_review_changes','order_datetime_repair_items',
    'order_financial_recalc_job_items','order_resource_enrichment_job_orders',
    'meli_item_sync_job_items','sync_batch_chunks','sync_chunk_runs'
);

UPDATE imported_data_reset_table_policy
SET action='reset',note='Se conserva únicamente la auditoría anterior; el estado operativo se reinicia.'
WHERE table_name IN (
    'meli_sync_coverage','meli_sync_offsets','meli_sync_checkpoints',
    'api_budget_windows','api_circuit_breakers',
    'remote_payload_references','remote_payload_objects'
);

UPDATE imported_data_reset_table_policy
SET action='ignore',note='Estado técnico mutable de la propia operación; queda fuera de la huella protegida.'
WHERE table_name IN (
    'imported_data_reset_requests','imported_data_reset_steps',
    'imported_data_reset_generations','imported_data_reset_table_policy',
    'system_backup_archives','system_backup_audit_events',
    'system_backup_download_grants','system_backup_jobs','system_backup_table_checks',
    'system_emergency_control_events','system_maintenance_task_state'
);

UPDATE imported_data_reset_table_policy
SET action='preserve',note='Evidencia financiera, fiscal o de pack inmutable; no se elimina durante el restablecimiento.'
WHERE table_name IN (
    'monthly_reports','monthly_report_items','monthly_report_orders','monthly_adjustments',
    'date_report_runs','date_report_items','date_report_orders','date_report_item_orders',
    'sales_control_captures','sales_control_closes','sales_control_reopenings',
    'sales_control_fiscal_snapshots','sales_control_fiscal_items',
    'meli_billing_capture_runs','meli_pack_order_expectations',
    'meli_sale_financials','meli_sale_financial_lines',
    'meli_sale_financial_allocations','meli_sale_financial_history',
    'sale_pack_reconciliation_history',
    'sale_pack_relation_repair_audit'
);

-- Razón explícita para cada tabla de negocio conocida que la política
-- conservadora retiene. La lista es cerrada: una tabla futura no entra aquí.
UPDATE imported_data_reset_table_policy
SET note='Tabla de negocio conocida y retenida expresamente; no es candidata del restablecimiento conservador.'
WHERE action='preserve' AND table_name IN (
    'manual_campaign_adapter_health','manual_campaign_events','manual_campaign_items',
    'manual_campaign_operations','manual_campaign_preview_items','manual_campaign_previews',
    'manual_campaign_reservations','manual_campaign_steps','manual_campaign_workers',
    'manual_campaigns','manual_engine_probe_runs','manual_operation_profiles',
    'manual_processing_engine_health','manual_processing_events','manual_processing_items',
    'manual_processing_scopes','manual_processing_sessions','meli_accounts',
    'meli_api_capabilities','meli_billing_capture_runs','meli_categories',
    'meli_claim_events','meli_claims','meli_item_attributes','meli_item_descriptions',
    'meli_item_pictures','meli_item_stock_locations','meli_item_sync_job_items',
    'meli_item_sync_jobs','meli_item_variations','meli_items',
    'meli_notification_backfill_runs','meli_notification_backfill_unique_resources',
    'meli_notification_events','meli_notification_recovery_run_items',
    'meli_notification_recovery_runs','meli_notification_work_items','meli_oauth_states',
    'meli_order_billing_details','meli_order_financials','meli_order_items','meli_orders',
    'meli_pack_order_expectations','meli_pack_orders','meli_packs','meli_payments',
    'meli_product_update_review_changes','meli_product_update_review_items',
    'meli_product_update_reviews','meli_questions','meli_sale_financial_allocations',
    'meli_sale_financial_history','meli_sale_financial_lines','meli_sale_financials',
    'meli_shipment_history','meli_shipment_labels','meli_shipments',
    'meli_sync_checkpoints','meli_sync_coverage','meli_sync_locks','meli_sync_logs',
    'meli_sync_offsets','meli_sync_runs','meli_tokens','meli_webhook_events',
    'order_datetime_repair_items','order_datetime_repair_jobs',
    'order_financial_recalc_job_items','order_financial_recalc_jobs',
    'order_resource_enrichment_job_orders','order_resource_enrichment_jobs',
    'product_match_suggestions',
    'sale_financial_reconciliation_jobs','sale_pack_rebuild_runs',
    'sale_pack_reconciliation_history','sale_pack_reconciliation_jobs',
    'sale_pack_relation_repair_audit','sales_control_access_audit',
    'sales_control_captures','sales_control_closes','sales_control_fiscal_items',
    'sales_control_fiscal_job_items','sales_control_fiscal_jobs',
    'sales_control_fiscal_snapshots','sales_control_month_sources',
    'sales_control_months','sales_control_reopenings','sales_control_year_runs',
    'sales_control_years','sync_batch_chunks','sync_batches','sync_chunk_runs',
    'sync_data_purge_requests','sync_diagnostics','sync_recurring_rules',
    'sync_sales_audit_days','sync_sales_audit_evidence_events','sync_sales_audit_jobs',
    'sync_sales_audit_missing_orders','sync_sales_audit_remote_ids',
    'sync_sales_audit_run_days','sync_sales_audit_run_orders',
    'sync_sales_audit_run_pages','sync_sales_audit_runs','sync_sales_audits',
    'sync_sales_capture_validations','sync_sales_repair_job_items',
    'sync_sales_repair_jobs'
);

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('imported_data_reset.batch_size','250',0,'maintenance'),
    ('imported_data_reset.lease_seconds','90',0,'maintenance'),
    ('imported_data_reset.require_fresh_backup','1',0,'maintenance'),
    ('imported_data_reset.enabled','1',0,'maintenance')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),
    setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES (
    '2.26.3',
    'Restablecimiento CLI reanudable de datos importados de Mercado Libre con evidencia y configuración protegidas.'
)
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
