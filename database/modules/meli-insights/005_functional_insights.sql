SET @has_snapshot_hash = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ml_insights_item_prices' AND COLUMN_NAME='snapshot_hash'
);
SET @sql_snapshot_hash = IF(
    @has_snapshot_hash=0,
    'ALTER TABLE ml_insights_item_prices ADD COLUMN snapshot_hash CHAR(64) NULL AFTER snapshot_json',
    'SELECT 1'
);
PREPARE stmt_snapshot_hash FROM @sql_snapshot_hash;
EXECUTE stmt_snapshot_hash;
DEALLOCATE PREPARE stmt_snapshot_hash;

SET @has_history_hash = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ml_insights_price_history' AND COLUMN_NAME='snapshot_hash'
);
SET @sql_history_hash = IF(
    @has_history_hash=0,
    'ALTER TABLE ml_insights_price_history ADD COLUMN snapshot_hash CHAR(64) NULL AFTER price_type',
    'SELECT 1'
);
PREPARE stmt_history_hash FROM @sql_history_hash;
EXECUTE stmt_history_hash;
DEALLOCATE PREPARE stmt_history_hash;

SET @has_idx_history_hash = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ml_insights_price_history' AND INDEX_NAME='uq_insights_history_hash'
);
SET @sql_idx_history_hash = IF(
    @has_idx_history_hash=0,
    'ALTER TABLE ml_insights_price_history ADD UNIQUE KEY uq_insights_history_hash (meli_account_id,external_item_id,snapshot_hash)',
    'SELECT 1'
);
PREPARE stmt_idx_history_hash FROM @sql_idx_history_hash;
EXECUTE stmt_idx_history_hash;
DEALLOCATE PREPARE stmt_idx_history_hash;

SET @has_perf_status = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ml_insights_item_performance' AND COLUMN_NAME='status'
);
SET @sql_perf_status = IF(
    @has_perf_status=0,
    'ALTER TABLE ml_insights_item_performance ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT ''confirmed'' AFTER external_item_id',
    'SELECT 1'
);
PREPARE stmt_perf_status FROM @sql_perf_status;
EXECUTE stmt_perf_status;
DEALLOCATE PREPARE stmt_perf_status;

SET @has_comp_eligibility = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ml_insights_catalog_competition' AND COLUMN_NAME='eligibility'
);
SET @sql_comp_eligibility = IF(
    @has_comp_eligibility=0,
    'ALTER TABLE ml_insights_catalog_competition ADD COLUMN eligibility VARCHAR(40) NULL AFTER status',
    'SELECT 1'
);
PREPARE stmt_comp_eligibility FROM @sql_comp_eligibility;
EXECUTE stmt_comp_eligibility;
DEALLOCATE PREPARE stmt_comp_eligibility;

SET @has_idx_capability_account = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ml_insights_account_capabilities' AND INDEX_NAME='idx_insights_capability_account'
);
SET @sql_idx_capability_account = IF(
    @has_idx_capability_account=0,
    'ALTER TABLE ml_insights_account_capabilities ADD KEY idx_insights_capability_account (meli_account_id,last_checked_at)',
    'SELECT 1'
);
PREPARE stmt_idx_capability_account FROM @sql_idx_capability_account;
EXECUTE stmt_idx_capability_account;
DEALLOCATE PREPARE stmt_idx_capability_account;
