-- Puente inmutable antes de 242: conserva un indice para la FK de cuenta
-- cuando 242 sustituye la unicidad legacy por la clave versionada V3.

SET @has_sale_financial_job_account_fk_idx = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE()
      AND table_name='sale_financial_reconciliation_jobs'
      AND index_name='idx_sale_financial_job_account_fk'
);
SET @add_sale_financial_job_account_fk_idx = IF(
    @has_sale_financial_job_account_fk_idx=0,
    'ALTER TABLE sale_financial_reconciliation_jobs ADD KEY idx_sale_financial_job_account_fk (meli_account_id)',
    'SELECT 1'
);
PREPARE stmt_add_sale_financial_job_account_fk_idx FROM @add_sale_financial_job_account_fk_idx;
EXECUTE stmt_add_sale_financial_job_account_fk_idx;
DEALLOCATE PREPARE stmt_add_sale_financial_job_account_fk_idx;
