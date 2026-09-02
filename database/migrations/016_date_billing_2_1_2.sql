ALTER TABLE date_report_runs
    ADD COLUMN issuer_company_id BIGINT UNSIGNED NULL AFTER company_id,
    ADD COLUMN customer_company_id BIGINT UNSIGNED NULL AFTER issuer_company_id,
    ADD COLUMN status ENUM('borrador','revisado','aprobado','facturado','anulado') NOT NULL DEFAULT 'borrador' AFTER include_returns,
    ADD COLUMN manual_base DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER estimated_net,
    ADD COLUMN external_invoice_reference VARCHAR(160) NULL AFTER manual_base,
    ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER reviewed_by,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN invoiced_at DATETIME NULL AFTER approved_at,
    ADD COLUMN voided_at DATETIME NULL AFTER invoiced_at,
    ADD KEY idx_date_billing_status_range (status, date_from, date_to),
    ADD KEY idx_date_billing_parties (issuer_company_id, customer_company_id, meli_account_id),
    ADD CONSTRAINT fk_date_reports_issuer FOREIGN KEY (issuer_company_id) REFERENCES companies(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_date_reports_customer FOREIGN KEY (customer_company_id) REFERENCES companies(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_date_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_date_reports_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE date_report_runs
SET manual_base = estimated_net
WHERE manual_base = 0 AND estimated_net <> 0;

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.1.2', 'Facturación por fechas y corrección de Empresas');
