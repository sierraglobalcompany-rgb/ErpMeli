-- Cron V3 2.29.0: contencion de alcance para backfill y reparaciones heredadas.
-- Aditiva e idempotente. Los trabajos globales sin tenant quedan cerrados.

ALTER TABLE meli_notification_backfill_runs
    ADD COLUMN IF NOT EXISTS company_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS meli_account_id BIGINT UNSIGNED NULL AFTER company_id;

ALTER TABLE meli_notification_backfill_unique_resources
    ADD COLUMN IF NOT EXISTS company_id BIGINT UNSIGNED NULL AFTER run_id;

UPDATE meli_notification_backfill_unique_resources u
JOIN meli_accounts a ON a.id=u.meli_account_id
SET u.company_id=a.company_id
WHERE u.company_id IS NULL AND u.meli_account_id>0;

UPDATE meli_notification_backfill_runs
SET status='error',locked_by=NULL,locked_at=NULL,lock_expires_at=NULL,
    last_error_message='Ejecucion historica global retirada: cree una corrida por empresa y cuenta.'
WHERE (company_id IS NULL OR meli_account_id IS NULL)
  AND status IN ('analyzing','ready','running','paused');

ALTER TABLE order_datetime_repair_jobs
    ADD COLUMN IF NOT EXISTS company_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS owner_token VARCHAR(100) NULL AFTER error_message,
    ADD COLUMN IF NOT EXISTS lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER owner_token,
    ADD COLUMN IF NOT EXISTS lease_until DATETIME NULL AFTER lease_generation;

UPDATE order_datetime_repair_jobs j
JOIN meli_accounts a ON a.id=j.meli_account_id
SET j.company_id=a.company_id
WHERE j.company_id IS NULL;

UPDATE order_datetime_repair_jobs
SET status='error',owner_token=NULL,lease_until=NULL,
    error_message='Trabajo sin empresa o cuenta verificable; requiere revision.'
WHERE (company_id IS NULL OR meli_account_id IS NULL)
  AND status IN ('pending','running');

UPDATE sync_sales_repair_jobs j
JOIN meli_accounts a ON a.id=j.meli_account_id
SET j.company_id=a.company_id
WHERE j.company_id IS NULL;

UPDATE sync_sales_repair_jobs
SET status='error',lock_owner=NULL,lock_expires_at=NULL,heartbeat_at=NULL,
    error_message='Trabajo sin empresa o cuenta verificable; requiere revision.'
WHERE (company_id IS NULL OR meli_account_id IS NULL)
  AND status IN ('pending','running','retry','waiting_budget');

SET @idx_backfill_scope = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='meli_notification_backfill_runs'
      AND index_name='idx_notification_backfill_scope_due'
);
SET @sql_backfill_scope = IF(
    @idx_backfill_scope=0,
    'ALTER TABLE meli_notification_backfill_runs ADD KEY idx_notification_backfill_scope_due (company_id,meli_account_id,status,next_run_at,id)',
    'SELECT 1'
);
PREPARE stmt_backfill_scope FROM @sql_backfill_scope;
EXECUTE stmt_backfill_scope;
DEALLOCATE PREPARE stmt_backfill_scope;

SET @idx_backfill_resource_scope = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='meli_notification_backfill_unique_resources'
      AND index_name='idx_backfill_resource_scope'
);
SET @sql_backfill_resource_scope = IF(
    @idx_backfill_resource_scope=0,
    'ALTER TABLE meli_notification_backfill_unique_resources ADD KEY idx_backfill_resource_scope (company_id,meli_account_id,run_id,first_event_id)',
    'SELECT 1'
);
PREPARE stmt_backfill_resource_scope FROM @sql_backfill_resource_scope;
EXECUTE stmt_backfill_resource_scope;
DEALLOCATE PREPARE stmt_backfill_resource_scope;

SET @idx_date_repair_scope = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='order_datetime_repair_jobs'
      AND index_name='idx_order_date_repair_scope_due'
);
SET @sql_date_repair_scope = IF(
    @idx_date_repair_scope=0,
    'ALTER TABLE order_datetime_repair_jobs ADD KEY idx_order_date_repair_scope_due (company_id,meli_account_id,status,lease_until,id)',
    'SELECT 1'
);
PREPARE stmt_date_repair_scope FROM @sql_date_repair_scope;
EXECUTE stmt_date_repair_scope;
DEALLOCATE PREPARE stmt_date_repair_scope;

SET @idx_sales_repair_scope = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='sync_sales_repair_jobs'
      AND index_name='idx_sales_repair_scope_due'
);
SET @sql_sales_repair_scope = IF(
    @idx_sales_repair_scope=0,
    'ALTER TABLE sync_sales_repair_jobs ADD KEY idx_sales_repair_scope_due (company_id,meli_account_id,source_kind,status,lock_expires_at,id)',
    'SELECT 1'
);
PREPARE stmt_sales_repair_scope FROM @sql_sales_repair_scope;
EXECUTE stmt_sales_repair_scope;
DEALLOCATE PREPARE stmt_sales_repair_scope;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES ('app.version','2.29.0',0,'system')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
    is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.29.0','Cron V3 deshabilitado por defecto, finanzas integradas y contención multitenant')
ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=CURRENT_TIMESTAMP;
