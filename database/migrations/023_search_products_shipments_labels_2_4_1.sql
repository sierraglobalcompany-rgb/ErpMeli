ALTER TABLE meli_orders
    ADD KEY idx_orders_account_buyer_2_4_1 (meli_account_id, buyer_nickname(120)),
    ADD KEY idx_orders_external_search_2_4_1 (external_order_id);

ALTER TABLE meli_order_items
    ADD KEY idx_order_items_title_2_4_1 (meli_account_id, title(120)),
    ADD KEY idx_order_items_external_2_4_1 (meli_account_id, external_item_id);

ALTER TABLE meli_items
    ADD KEY idx_meli_items_title_2_4_1 (meli_account_id, title(120)),
    ADD KEY idx_meli_items_external_2_4_1 (meli_account_id, external_item_id);

ALTER TABLE meli_shipments
    ADD KEY idx_shipments_logistics_day_2_4_1 (meli_account_id, logistic_type, status, substatus, estimated_delivery),
    ADD KEY idx_shipments_mode_2_4_1 (meli_account_id, shipping_mode, logistic_type);

CREATE TABLE IF NOT EXISTS meli_shipment_labels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    meli_shipment_id BIGINT UNSIGNED NOT NULL,
    external_shipment_id BIGINT UNSIGNED NOT NULL,
    label_format ENUM('pdf','zpl') NOT NULL DEFAULT 'pdf',
    status ENUM('disabled','pending','downloaded','unavailable','error') NOT NULL DEFAULT 'disabled',
    file_path VARCHAR(500) NULL,
    error_code VARCHAR(120) NULL,
    error_message VARCHAR(500) NULL,
    downloaded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipment_label_format (meli_account_id, external_shipment_id, label_format),
    KEY idx_labels_status (status, updated_at),
    CONSTRAINT fk_labels_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_labels_shipment FOREIGN KEY (meli_shipment_id) REFERENCES meli_shipments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('shipping_labels.enabled', '0', 'shipping', 0),
('shipping_labels.country_confirmed', '0', 'shipping', 0),
('shipping_labels.max_shipments_per_request', '50', 'shipping', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.4.1', 'Búsquedas, publicaciones con imagen, enlaces ML y envíos pendientes');
