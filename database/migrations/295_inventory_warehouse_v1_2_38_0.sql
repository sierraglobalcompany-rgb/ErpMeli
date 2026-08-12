-- Inventario / Bodega Real V1. Estado local exclusivamente; no modifica Mercado Libre.

CREATE TABLE IF NOT EXISTS inventory_warehouses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(160) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    default_slot TINYINT GENERATED ALWAYS AS (
        CASE WHEN status='active' AND is_default=1 THEN 1 ELSE NULL END
    ) STORED,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_warehouse_code (company_id,code),
    UNIQUE KEY uq_inventory_warehouse_default (company_id,default_slot),
    KEY idx_inventory_warehouse_status (company_id,status,name),
    CONSTRAINT fk_inventory_warehouse_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_balances (
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    internal_product_id BIGINT UNSIGNED NOT NULL,
    on_hand DECIMAL(20,6) NOT NULL DEFAULT 0,
    reserved DECIMAL(20,6) NOT NULL DEFAULT 0,
    available DECIMAL(20,6) GENERATED ALWAYS AS (on_hand-reserved) STORED,
    average_unit_cost DECIMAL(20,6) NOT NULL DEFAULT 0,
    lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (company_id,warehouse_id,internal_product_id),
    KEY idx_inventory_balance_product (company_id,internal_product_id,warehouse_id),
    KEY idx_inventory_balance_available (company_id,warehouse_id,available),
    CONSTRAINT fk_inventory_balance_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_inventory_balance_warehouse FOREIGN KEY (warehouse_id) REFERENCES inventory_warehouses(id),
    CONSTRAINT fk_inventory_balance_product FOREIGN KEY (internal_product_id) REFERENCES internal_products(id),
    CONSTRAINT chk_inventory_balance_nonnegative CHECK (on_hand>=0 AND reserved>=0 AND reserved<=on_hand),
    CONSTRAINT chk_inventory_balance_cost CHECK (average_unit_cost>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    internal_product_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NULL,
    movement_type ENUM(
        'opening','receipt','sale_issue','sale_reversal',
        'adjustment_in','adjustment_out','reserve','release'
    ) NOT NULL,
    on_hand_delta DECIMAL(20,6) NOT NULL DEFAULT 0,
    reserved_delta DECIMAL(20,6) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(20,6) NOT NULL DEFAULT 0,
    total_cost DECIMAL(20,6) NOT NULL DEFAULT 0,
    on_hand_after DECIMAL(20,6) NOT NULL,
    reserved_after DECIMAL(20,6) NOT NULL,
    available_after DECIMAL(20,6) NOT NULL,
    average_unit_cost_after DECIMAL(20,6) NOT NULL,
    reference_type VARCHAR(64) NOT NULL,
    reference_id VARCHAR(191) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    reversal_of_movement_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    source ENUM('manual','queue_v4_clean','system') NOT NULL,
    reason VARCHAR(500) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_movement_idempotency (company_id,idempotency_key),
    UNIQUE KEY uq_inventory_movement_reversal (company_id,reversal_of_movement_id),
    KEY idx_inventory_movement_kardex (company_id,warehouse_id,internal_product_id,created_at,id),
    KEY idx_inventory_movement_account (company_id,meli_account_id,created_at,id),
    KEY idx_inventory_movement_reference (company_id,reference_type,reference_id,movement_type),
    CONSTRAINT fk_inventory_movement_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_inventory_movement_warehouse FOREIGN KEY (warehouse_id) REFERENCES inventory_warehouses(id),
    CONSTRAINT fk_inventory_movement_product FOREIGN KEY (internal_product_id) REFERENCES internal_products(id),
    CONSTRAINT fk_inventory_movement_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id),
    CONSTRAINT fk_inventory_movement_reversal FOREIGN KEY (reversal_of_movement_id) REFERENCES inventory_movements(id),
    CONSTRAINT chk_inventory_movement_cost CHECK (unit_cost>=0 AND total_cost>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(80) NOT NULL,
    internal_product_id BIGINT UNSIGNED NULL,
    reason_code ENUM(
        'UNLINKED_PRODUCT','DEFAULT_WAREHOUSE_MISSING',
        'INSUFFICIENT_STOCK','PARTIAL_RETURN_AUTHORITY_REQUIRED'
    ) NOT NULL,
    state ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    required_quantity DECIMAL(20,6) NULL,
    available_quantity DECIMAL(20,6) NULL,
    context_json JSON NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    resolution VARCHAR(160) NULL,
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME(3) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_review_idempotency (company_id,idempotency_key),
    KEY idx_inventory_review_queue (company_id,state,reason_code,created_at,id),
    KEY idx_inventory_review_order (company_id,meli_account_id,meli_order_id,state),
    CONSTRAINT fk_inventory_review_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_inventory_review_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id),
    CONSTRAINT fk_inventory_review_order FOREIGN KEY (meli_order_id) REFERENCES meli_orders(id),
    CONSTRAINT fk_inventory_review_product FOREIGN KEY (internal_product_id) REFERENCES internal_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
