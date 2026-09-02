-- Cron V3 RC2: autoridad de ritmo aditiva por aplicacion, tenant, endpoint y operacion.
-- No contiene credenciales. El application_id es el identificador publico de la aplicacion ML.
-- Contrato: columnas aditivas controladas por information_schema para conservar compatibilidad MariaDB/MySQL.

SET @col_cron_v3_scope_level = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='cron_v3_rate_buckets'
      AND column_name='scope_level'
);
SET @sql_cron_v3_scope_level = IF(
    @col_cron_v3_scope_level=0,
    "ALTER TABLE cron_v3_rate_buckets ADD COLUMN scope_level ENUM('global','application','account','endpoint','operation') NOT NULL DEFAULT 'global' AFTER scope_key",
    'SELECT 1'
);
PREPARE stmt_cron_v3_scope_level FROM @sql_cron_v3_scope_level;
EXECUTE stmt_cron_v3_scope_level;
DEALLOCATE PREPARE stmt_cron_v3_scope_level;

SET @col_cron_v3_application_id = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='cron_v3_rate_buckets'
      AND column_name='application_id'
);
SET @sql_cron_v3_application_id = IF(
    @col_cron_v3_application_id=0,
    'ALTER TABLE cron_v3_rate_buckets ADD COLUMN application_id VARCHAR(120) NULL AFTER scope_level',
    'SELECT 1'
);
PREPARE stmt_cron_v3_application_id FROM @sql_cron_v3_application_id;
EXECUTE stmt_cron_v3_application_id;
DEALLOCATE PREPARE stmt_cron_v3_application_id;

SET @col_cron_v3_endpoint_key = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='cron_v3_rate_buckets'
      AND column_name='endpoint_key'
);
SET @sql_cron_v3_endpoint_key = IF(
    @col_cron_v3_endpoint_key=0,
    'ALTER TABLE cron_v3_rate_buckets ADD COLUMN endpoint_key VARCHAR(120) NULL AFTER meli_account_id',
    'SELECT 1'
);
PREPARE stmt_cron_v3_endpoint_key FROM @sql_cron_v3_endpoint_key;
EXECUTE stmt_cron_v3_endpoint_key;
DEALLOCATE PREPARE stmt_cron_v3_endpoint_key;

SET @col_cron_v3_operation_key = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='cron_v3_rate_buckets'
      AND column_name='operation_key'
);
SET @sql_cron_v3_operation_key = IF(
    @col_cron_v3_operation_key=0,
    'ALTER TABLE cron_v3_rate_buckets ADD COLUMN operation_key VARCHAR(80) NULL AFTER endpoint_key',
    'SELECT 1'
);
PREPARE stmt_cron_v3_operation_key FROM @sql_cron_v3_operation_key;
EXECUTE stmt_cron_v3_operation_key;
DEALLOCATE PREPARE stmt_cron_v3_operation_key;

UPDATE cron_v3_rate_buckets
SET scope_level='global',application_id=NULL,endpoint_key=NULL,operation_key=NULL
WHERE scope_key=SHA2('cron_v3|global',256);

SET @idx_cron_v3_rate_dimensions = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='cron_v3_rate_buckets'
      AND index_name='idx_cron_v3_rate_dimensions'
);
SET @sql_cron_v3_rate_dimensions = IF(
    @idx_cron_v3_rate_dimensions=0,
    'ALTER TABLE cron_v3_rate_buckets ADD KEY idx_cron_v3_rate_dimensions (scope_level,application_id,company_id,meli_account_id,endpoint_key,operation_key)',
    'SELECT 1'
);
PREPARE stmt_cron_v3_rate_dimensions FROM @sql_cron_v3_rate_dimensions;
EXECUTE stmt_cron_v3_rate_dimensions;
DEALLOCATE PREPARE stmt_cron_v3_rate_dimensions;

SET @idx_cron_v3_rate_operation = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='cron_v3_rate_buckets'
      AND index_name='idx_cron_v3_rate_operation'
);
SET @sql_cron_v3_rate_operation = IF(
    @idx_cron_v3_rate_operation=0,
    'ALTER TABLE cron_v3_rate_buckets ADD KEY idx_cron_v3_rate_operation (application_id,endpoint_key,operation_key,blocked_until)',
    'SELECT 1'
);
PREPARE stmt_cron_v3_rate_operation FROM @sql_cron_v3_rate_operation;
EXECUTE stmt_cron_v3_rate_operation;
DEALLOCATE PREPARE stmt_cron_v3_rate_operation;
