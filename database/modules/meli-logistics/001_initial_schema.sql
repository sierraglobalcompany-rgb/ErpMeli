CREATE TABLE IF NOT EXISTS ml_logistics_shipment_snapshots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_shipment_id VARCHAR(120) NOT NULL,
 external_order_id VARCHAR(80) NULL,status VARCHAR(60) NULL,substatus VARCHAR(80) NULL,logistic_type VARCHAR(80) NULL,
 estimated_delivery_at DATETIME NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_logistics_shipment(meli_account_id,external_shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_logistics_tracking_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_shipment_id VARCHAR(120) NOT NULL,
 event_code VARCHAR(80) NOT NULL,event_description VARCHAR(500) NULL,event_at DATETIME NULL,snapshot_json JSON NULL,
 KEY idx_logistics_tracking(meli_account_id,external_shipment_id,event_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_logistics_delivery_promises (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_shipment_id VARCHAR(120) NOT NULL,
 promised_from DATETIME NULL,promised_to DATETIME NULL,delivered_at DATETIME NULL,delay_minutes INT NULL,confidence VARCHAR(40) NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_logistics_promise(meli_account_id,external_shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_logistics_stock_locations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,user_product_id VARCHAR(120) NOT NULL,
 location_id VARCHAR(120) NOT NULL,location_type VARCHAR(80) NULL,quantity INT NOT NULL DEFAULT 0,confidence VARCHAR(40) NULL,snapshot_json JSON NULL,observed_at DATETIME NOT NULL,
 UNIQUE KEY uq_logistics_stock_location(meli_account_id,user_product_id,location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_logistics_inventory_snapshots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(40) NOT NULL,user_product_id VARCHAR(120) NULL,
 stock_total INT NULL,stock_full INT NULL,stock_local INT NULL,stock_unknown INT NULL,confidence VARCHAR(40) NULL,observed_at DATETIME NOT NULL,
 KEY idx_logistics_inventory(meli_account_id,external_item_id,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_logistics_inventory_differences (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(40) NOT NULL,internal_sku VARCHAR(120) NULL,
 remote_quantity INT NULL,local_quantity INT NULL,difference_quantity INT NULL,status VARCHAR(40) NOT NULL,observed_at DATETIME NOT NULL,
 KEY idx_logistics_difference(meli_account_id,status,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_logistics_label_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,request_token CHAR(64) NOT NULL,shipment_ids_json JSON NOT NULL,
 format VARCHAR(20) NOT NULL,status VARCHAR(40) NOT NULL,private_path VARCHAR(500) NULL,expires_at DATETIME NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,
 UNIQUE KEY uq_logistics_label_token(request_token),KEY idx_logistics_labels_expiry(status,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS ml_logistics_sync_jobs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,job_type VARCHAR(80) NOT NULL,status VARCHAR(40) NOT NULL,
 checkpoint_json JSON NULL,next_run_at DATETIME NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,KEY idx_logistics_jobs(status,next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
