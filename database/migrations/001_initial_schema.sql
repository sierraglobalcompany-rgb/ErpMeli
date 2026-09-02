CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(100) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','operador','consulta') NOT NULL DEFAULT 'consulta',
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_lookup (email_hash, ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    nit VARCHAR(40) NULL,
    legal_name VARCHAR(190) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(40) NULL,
    address VARCHAR(255) NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_companies_nit (nit),
    KEY idx_companies_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    setting_key VARCHAR(120) NOT NULL,
    setting_value TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_company_setting (company_id, setting_key),
    CONSTRAINT fk_company_settings_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    account_name VARCHAR(160) NOT NULL,
    meli_user_id BIGINT UNSIGNED NULL,
    nickname VARCHAR(160) NULL,
    site_id VARCHAR(12) NULL,
    country_id VARCHAR(12) NULL,
    status ENUM('pendiente','conectado','vencido','error','desconectado') NOT NULL DEFAULT 'pendiente',
    last_sync_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_account_user (meli_user_id),
    KEY idx_meli_accounts_company_status (company_id, status),
    CONSTRAINT fk_meli_accounts_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    access_token_encrypted MEDIUMTEXT NOT NULL,
    refresh_token_encrypted MEDIUMTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    token_type VARCHAR(40) NOT NULL DEFAULT 'Bearer',
    scope TEXT NULL,
    refresh_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_token_account (meli_account_id),
    CONSTRAINT fk_meli_tokens_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_oauth_states (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    state_hash CHAR(64) NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    account_name VARCHAR(160) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_oauth_state (state_hash),
    KEY idx_oauth_state_expiry (expires_at, consumed_at),
    CONSTRAINT fk_oauth_state_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_oauth_state_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_order_id BIGINT UNSIGNED NOT NULL,
    external_pack_id BIGINT UNSIGNED NULL,
    date_created DATETIME NULL,
    date_closed DATETIME NULL,
    status VARCHAR(80) NULL,
    status_detail VARCHAR(160) NULL,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency_id VARCHAR(8) NULL,
    buyer_id BIGINT UNSIGNED NULL,
    buyer_nickname VARCHAR(160) NULL,
    external_shipping_id BIGINT UNSIGNED NULL,
    tags_json JSON NULL,
    raw_json JSON NULL,
    raw_path VARCHAR(500) NULL,
    synced_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_order (meli_account_id, external_order_id),
    KEY idx_orders_account_date (meli_account_id, date_created),
    KEY idx_orders_pack (meli_account_id, external_pack_id),
    KEY idx_orders_shipping (meli_account_id, external_shipping_id),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_item_id VARCHAR(40) NOT NULL,
    external_variation_id BIGINT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    seller_sku VARCHAR(120) NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
    full_unit_price DECIMAL(18,2) NULL,
    sale_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
    listing_type_id VARCHAR(40) NULL,
    raw_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_item (meli_order_id, external_item_id, external_variation_id),
    KEY idx_order_items_sku (meli_account_id, seller_sku),
    CONSTRAINT fk_order_items_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_packs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_pack_id BIGINT UNSIGNED NOT NULL,
    external_shipment_id BIGINT UNSIGNED NULL,
    buyer_json JSON NULL,
    status VARCHAR(80) NULL,
    raw_json JSON NULL,
    raw_path VARCHAR(500) NULL,
    synced_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_pack (meli_account_id, external_pack_id),
    KEY idx_packs_shipment (meli_account_id, external_shipment_id),
    CONSTRAINT fk_packs_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_pack_orders (
    meli_pack_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (meli_pack_id, meli_order_id),
    CONSTRAINT fk_pack_orders_pack FOREIGN KEY (meli_pack_id) REFERENCES meli_packs(id) ON DELETE CASCADE,
    CONSTRAINT fk_pack_orders_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_shipments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_shipment_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NULL,
    meli_pack_id BIGINT UNSIGNED NULL,
    status VARCHAR(80) NULL,
    substatus VARCHAR(120) NULL,
    logistic_type VARCHAR(80) NULL,
    shipping_mode VARCHAR(80) NULL,
    tracking_number VARCHAR(160) NULL,
    carrier VARCHAR(160) NULL,
    estimated_delivery DATETIME NULL,
    gross_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    seller_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    buyer_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    discounts DECIMAL(18,2) NOT NULL DEFAULT 0,
    raw_json JSON NULL,
    raw_path VARCHAR(500) NULL,
    synced_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_shipment (meli_account_id, external_shipment_id),
    KEY idx_shipments_status (meli_account_id, status),
    CONSTRAINT fk_shipments_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_shipments_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_shipments_pack FOREIGN KEY (meli_pack_id) REFERENCES meli_packs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_shipment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_shipment_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(80) NOT NULL,
    substatus VARCHAR(120) NULL,
    event_at DATETIME NULL,
    raw_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipment_event (meli_shipment_id, status, substatus, event_at),
    CONSTRAINT fk_shipment_history_shipment FOREIGN KEY (meli_shipment_id) REFERENCES meli_shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NULL,
    external_payment_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(80) NULL,
    status_detail VARCHAR(160) NULL,
    payment_method_id VARCHAR(80) NULL,
    payment_type VARCHAR(80) NULL,
    transaction_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    shipping_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    coupon_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    marketplace_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
    date_approved DATETIME NULL,
    raw_json JSON NULL,
    raw_path VARCHAR(500) NULL,
    synced_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meli_payment (meli_account_id, external_payment_id),
    KEY idx_payments_approved (meli_account_id, date_approved),
    KEY idx_payments_status (status),
    CONSTRAINT fk_payments_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_payments_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_sync_checkpoints (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sync_type VARCHAR(60) NOT NULL,
    cursor_value TEXT NULL,
    range_from DATETIME NULL,
    range_to DATETIME NULL,
    status ENUM('pending','running','complete','error') NOT NULL DEFAULT 'pending',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sync_checkpoint (meli_account_id, sync_type),
    CONSTRAINT fk_sync_checkpoints_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_hash CHAR(64) NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    topic VARCHAR(120) NULL,
    resource VARCHAR(500) NULL,
    external_user_id BIGINT UNSIGNED NULL,
    status ENUM('pending','processing','processed','ignored','error') NOT NULL DEFAULT 'pending',
    raw_json JSON NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    UNIQUE KEY uq_webhook_hash (event_hash),
    KEY idx_webhooks_queue (status, received_at),
    KEY idx_webhooks_topic_resource (topic, resource(190)),
    CONSTRAINT fk_webhooks_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    issuer_company_id BIGINT UNSIGNED NOT NULL,
    customer_company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    report_month DATE NOT NULL,
    status ENUM('borrador','revisado','aprobado','facturado','anulado') NOT NULL DEFAULT 'borrador',
    gross_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
    marketplace_fees DECIMAL(18,2) NOT NULL DEFAULT 0,
    shipping_costs DECIMAL(18,2) NOT NULL DEFAULT 0,
    discounts DECIMAL(18,2) NOT NULL DEFAULT 0,
    estimated_net DECIMAL(18,2) NOT NULL DEFAULT 0,
    manual_base DECIMAL(18,2) NOT NULL DEFAULT 0,
    external_invoice_reference VARCHAR(160) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    invoiced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_monthly_report (issuer_company_id, customer_company_id, meli_account_id, report_month),
    KEY idx_reports_status_month (status, report_month),
    CONSTRAINT fk_reports_issuer FOREIGN KEY (issuer_company_id) REFERENCES companies(id),
    CONSTRAINT fk_reports_customer FOREIGN KEY (customer_company_id) REFERENCES companies(id),
    CONSTRAINT fk_reports_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id),
    CONSTRAINT fk_reports_creator FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id),
    CONSTRAINT fk_reports_approver FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_report_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    monthly_report_id BIGINT UNSIGNED NOT NULL,
    seller_sku VARCHAR(120) NOT NULL,
    product_title VARCHAR(255) NOT NULL,
    units INT NOT NULL DEFAULT 0,
    gross_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
    marketplace_fees DECIMAL(18,2) NOT NULL DEFAULT 0,
    shipping_costs DECIMAL(18,2) NOT NULL DEFAULT 0,
    discounts DECIMAL(18,2) NOT NULL DEFAULT 0,
    estimated_net DECIMAL(18,2) NOT NULL DEFAULT 0,
    manual_base DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_report_sku (monthly_report_id, seller_sku),
    CONSTRAINT fk_report_items_report FOREIGN KEY (monthly_report_id) REFERENCES monthly_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_report_orders (
    monthly_report_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    classification ENUM('valida','cancelada','devuelta','ajuste') NOT NULL DEFAULT 'valida',
    PRIMARY KEY (monthly_report_id, meli_order_id),
    CONSTRAINT fk_report_orders_report FOREIGN KEY (monthly_report_id) REFERENCES monthly_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_orders_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_report_id BIGINT UNSIGNED NOT NULL,
    target_report_id BIGINT UNSIGNED NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(120) NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    status ENUM('pending','applied') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at DATETIME NULL,
    UNIQUE KEY uq_adjustment_order_reason (source_report_id, meli_order_id, reason),
    CONSTRAINT fk_adjustment_source FOREIGN KEY (source_report_id) REFERENCES monthly_reports(id),
    CONSTRAINT fk_adjustment_target FOREIGN KEY (target_report_id) REFERENCES monthly_reports(id),
    CONSTRAINT fk_adjustment_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL,
    message VARCHAR(500) NOT NULL,
    context_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_system_logs_level_date (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_sync_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NULL,
    sync_type VARCHAR(60) NOT NULL,
    status ENUM('started','success','partial','error') NOT NULL,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    KEY idx_sync_logs_status (status, started_at),
    CONSTRAINT fk_sync_logs_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    module VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id BIGINT UNSIGNED NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    ip_hash CHAR(64) NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_user_date (user_id, created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_error_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NULL,
    request_id VARCHAR(80) NULL,
    method VARCHAR(10) NOT NULL,
    endpoint_path VARCHAR(500) NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    error_code VARCHAR(120) NULL,
    safe_message VARCHAR(500) NOT NULL,
    response_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_api_errors_account_date (meli_account_id, created_at),
    CONSTRAINT fk_api_errors_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
