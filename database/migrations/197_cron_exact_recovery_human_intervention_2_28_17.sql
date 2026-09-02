-- ERP Meli 2.28.17 — recuperación exacta de Cron e intervención accionable.
-- Repara exclusivamente estados técnicos sin transporte remoto. No modifica
-- órdenes, ítems, pagos, envíos, cuentas, OAuth, cierres ni evidencia fiscal.

CREATE TABLE IF NOT EXISTS system_work_resolution_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idempotency_key CHAR(64) NOT NULL,
    queue_key VARCHAR(80) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    campaign_id BIGINT UNSIGNED NULL,
    campaign_item_id BIGINT UNSIGNED NULL,
    action_key VARCHAR(80) NOT NULL,
    previous_status VARCHAR(40) NOT NULL,
    new_status VARCHAR(40) NOT NULL,
    expected_generation INT UNSIGNED NOT NULL DEFAULT 0,
    resulting_generation INT UNSIGNED NOT NULL DEFAULT 0,
    diagnostic_id VARCHAR(80) NULL,
    safe_message VARCHAR(500) NOT NULL,
    result_status VARCHAR(40) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_work_resolution_idempotency (idempotency_key),
    KEY idx_work_resolution_resource (queue_key,source_id,created_at),
    KEY idx_work_resolution_scope (company_id,meli_account_id,created_at),
    KEY idx_work_resolution_campaign (campaign_id,campaign_item_id,created_at),
    KEY idx_work_resolution_user (created_by,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El patrón histórico observado en producción agotó la ventana antes de
-- iniciar HTTP. Devuelve el recurso a retry, restaura el intento técnico y
-- conserva mensaje y diagnóstico para auditoría.
UPDATE order_resource_enrichment_jobs
SET status='retry',
    attempts=GREATEST(0,attempts-1),
    next_run_at=UTC_TIMESTAMP(),
    lock_token=NULL,
    locked_at=NULL,
    heartbeat_at=NULL,
    last_error_code='cron_deadline_deferred',
    failure_class='waiting_deadline',
    reached_remote=0,
    updated_at=UTC_TIMESTAMP()
WHERE status='error'
  AND COALESCE(last_error_code,'unknown')='unknown'
  AND COALESCE(reached_remote,0)=0
  AND LOWER(COALESCE(last_error_message,'')) LIKE '%límite seguro antes de iniciar otra consulta api%';

-- Reconciliar únicamente los ítems de campaña que apuntan al patrón anterior.
UPDATE manual_campaign_items i
JOIN order_resource_enrichment_jobs j
  ON i.queue_key='order_enrichment'
 AND BINARY i.source_id=BINARY CAST(j.id AS CHAR)
SET i.status='retry',
    i.attempts=GREATEST(0,i.attempts-1),
    i.failed_units=0,
    i.next_eligible_at=UTC_TIMESTAMP(3),
    i.lease_owner=NULL,
    i.lease_expires_at=NULL,
    i.result_summary='Aplazado por tiempo. Mercado Libre no fue consultado; Cron continuará automáticamente.',
    i.last_attempt_result='waiting_deadline',
    i.updated_at=UTC_TIMESTAMP()
WHERE i.status='failed'
  AND j.last_error_code='cron_deadline_deferred'
  AND j.failure_class='waiting_deadline'
  AND COALESCE(j.reached_remote,0)=0;

-- Limpiar punteros que ya no representan un lease vivo. El historial del
-- intento se conserva y un worker antiguo queda cercado por generación.
UPDATE manual_campaigns c
LEFT JOIN manual_campaign_items i ON i.id=c.current_item_id
SET c.current_item_id=NULL,
    c.current_operation_id=NULL,
    c.worker_heartbeat_at=NULL,
    c.version_no=c.version_no+1,
    c.last_engine_state=IF(c.status='active','waiting_cli',c.last_engine_state),
    c.next_action_at=IF(c.status='active',UTC_TIMESTAMP(3),c.next_action_at)
WHERE c.current_item_id IS NOT NULL
  AND (i.id IS NULL OR i.status<>'running' OR i.lease_expires_at IS NULL OR i.lease_expires_at<UTC_TIMESTAMP(3));

-- Los agregados se derivan de los ítems. Fallidos, omitidos y devueltos no
-- son completados reales ni alimentan la ETA.
UPDATE manual_campaigns c
JOIN (
    SELECT manual_campaign_id,
           SUM(status='completed') completed_items_real,
           SUM(status='failed') failed_items_real,
           SUM(status='skipped') skipped_items_real,
           SUM(status IN ('waiting','retry')) retry_items_real,
           COALESCE(SUM(CASE WHEN status='completed' THEN completed_units ELSE 0 END),0) completed_units_real,
           COALESCE(SUM(CASE WHEN status='failed' THEN failed_units ELSE 0 END),0) failed_units_real,
           COALESCE(SUM(CASE WHEN status='skipped' THEN skipped_units ELSE 0 END),0) skipped_units_real,
           COALESCE(SUM(primary_calls),0) primary_calls_real,
           COALESCE(SUM(derived_calls),0) derived_calls_real
    FROM manual_campaign_items
    GROUP BY manual_campaign_id
) s ON s.manual_campaign_id=c.id
SET c.completed_items=s.completed_items_real,
    c.failed_items=s.failed_items_real,
    c.skipped_items=s.skipped_items_real,
    c.retry_items=s.retry_items_real,
    c.completed_units=s.completed_units_real,
    c.failed_units=s.failed_units_real,
    c.skipped_units=s.skipped_units_real,
    c.primary_calls=s.primary_calls_real,
    c.derived_calls=s.derived_calls_real,
    c.outbound_calls=s.primary_calls_real+s.derived_calls_real,
    c.version_no=c.version_no+1;

-- Las funciones con errores aislados deben continuar. Solo se preserva el
-- estado de error para evidencia claramente sistémica.
UPDATE cron_task_state
SET status='ready',
    next_run_at=UTC_TIMESTAMP(),
    consecutive_failures=0,
    last_selection_reason='isolated_work_recovered',
    updated_at=UTC_TIMESTAMP()
WHERE status='error'
  AND LOWER(COALESCE(last_error_message,'')) NOT REGEXP
      'sqlstate|schema|esquema|integridad|adapter|adaptador|migration|migraci[oó]n|database|base de datos';

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('cron.exact_recovery_enabled','1',0,'cron'),
('cron.isolated_errors_do_not_stop_queue','1',0,'cron'),
('manual_campaign.orphan_recovery_enabled','1',0,'manual_campaign'),
('manual_campaign.eta_minimum_successes','3',0,'manual_campaign'),
('app.version','2.28.17',0,'system')
ON DUPLICATE KEY UPDATE
    setting_value=VALUES(setting_value),
    setting_group=VALUES(setting_group),
    is_encrypted=VALUES(is_encrypted);

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','197_cron_exact_recovery_human_intervention_2_28_17.sql','structural',1),
('automation_center','197_cron_exact_recovery_human_intervention_2_28_17.sql','structural',1),
('manual_campaigns','197_cron_exact_recovery_human_intervention_2_28_17.sql','structural',1),
('order_enrichment','197_cron_exact_recovery_human_intervention_2_28_17.sql','structural',1)
ON DUPLICATE KEY UPDATE
    required_migration=VALUES(required_migration),
    contract_kind=VALUES(contract_kind),
    enabled=VALUES(enabled);

INSERT INTO app_versions (version,notes)
VALUES ('2.28.17','Recuperación exacta de Cron, campañas continuas e intervención accionable')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
