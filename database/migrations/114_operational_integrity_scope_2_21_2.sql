-- ERP Meli 2.21.2 — integridad operativa, alcance explícito y ausencias terminales.
-- Aditiva e idempotente. No modifica datos comerciales ni elimina históricos.

CREATE TABLE IF NOT EXISTS user_company_access (
    user_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    access_role ENUM('admin','operador','consulta') NOT NULL DEFAULT 'consulta',
    granted_by BIGINT UNSIGNED NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id,company_id),
    KEY idx_user_company_access_company (company_id,user_id),
    CONSTRAINT fk_user_company_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_company_access_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_company_access_granter FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los administradores permanentes existentes conservan su alcance, ahora de forma explícita.
INSERT IGNORE INTO user_company_access (user_id,company_id,access_role,granted_by)
SELECT u.id,c.id,'admin',u.id
FROM users u
CROSS JOIN companies c
WHERE u.role='admin' AND u.status=1 AND COALESCE(u.is_temporary,0)=0 AND c.status=1;

CREATE TABLE IF NOT EXISTS system_component_schema_contracts (
    component_key VARCHAR(80) NOT NULL,
    required_migration VARCHAR(140) NOT NULL,
    contract_kind ENUM('structural','metadata') NOT NULL DEFAULT 'structural',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (component_key,required_migration),
    KEY idx_component_schema_contract (component_key,enabled,contract_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_component_schema_contracts
    (component_key,required_migration,contract_kind,enabled)
VALUES
('process_sync_queue','112_annual_sales_fiscal_control_2_21_0.sql','structural',1),
('process_notifications','068_webhook_first_notifications_2_11_1.sql','structural',1),
('cron_probe','060_api_audit_corrections_2_9_1.sql','structural',1),
('sales_control','112_annual_sales_fiscal_control_2_21_0.sql','structural',1),
('release_metadata','113_sales_control_schema_compatibility_2_21_1.sql','metadata',1)
ON DUPLICATE KEY UPDATE
contract_kind=VALUES(contract_kind),
enabled=VALUES(enabled);

-- Un 404 exacto de pregunta es una ausencia terminal; conservar el diagnóstico,
-- pero detener el ciclo de consultas repetidas.
UPDATE meli_notification_work_items
SET status='complete',
    last_result='question_not_available',
    completed_at=COALESCE(completed_at,UTC_TIMESTAMP()),
    last_processed_at=COALESCE(last_processed_at,UTC_TIMESTAMP()),
    locked_by=NULL,
    locked_at=NULL,
    lock_expires_at=NULL,
    processing_event_id=NULL,
    next_run_at=UTC_TIMESTAMP()
WHERE resource_type='question'
  AND status IN ('pending','retry','error')
  AND last_error_code='http_404';

UPDATE meli_notification_events e
JOIN meli_notification_work_items w ON w.id=e.work_item_id
SET e.status='processed',
    e.disposition='question_not_available',
    e.processed_at=COALESCE(e.processed_at,UTC_TIMESTAMP()),
    e.error_message=NULL
WHERE w.resource_type='question'
  AND w.last_result='question_not_available'
  AND e.status NOT IN ('duplicate','ignored','processed');

UPDATE cron_task_state
SET status='ready',
    next_run_at=UTC_TIMESTAMP(),
    last_selection_reason='abandoned_run_recovered',
    last_error_message='La ejecución anterior perdió su reserva y fue programada nuevamente.',
    updated_at=UTC_TIMESTAMP()
WHERE status='running'
  AND last_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 3 MINUTE);

INSERT INTO app_settings (setting_key,setting_value,setting_group,is_encrypted)
VALUES
('security.business_scope_enabled','1','security',0),
('security.business_scope_deny_unassigned','1','security',0),
('integrity.component_contracts_enabled','1','integrity',0),
('notifications.question_404_terminal','1','notifications',0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group),is_encrypted=VALUES(is_encrypted);

INSERT INTO app_versions (version,notes)
VALUES ('2.21.2','Integridad por componente, alcance explícito y preguntas 404 terminales')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
