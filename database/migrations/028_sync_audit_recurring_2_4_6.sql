CREATE TABLE IF NOT EXISTS sync_sales_audits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    date_from DATETIME NOT NULL,
    date_to DATETIME NOT NULL,
    remote_total INT UNSIGNED NOT NULL DEFAULT 0,
    local_total INT UNSIGNED NOT NULL DEFAULT 0,
    difference_count INT NOT NULL DEFAULT 0,
    status ENUM('complete','incomplete','not_audited','error','blocked') NOT NULL DEFAULT 'not_audited',
    error_message VARCHAR(500) NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_sync_sales_audit_period (meli_account_id, period_year, period_month),
    KEY idx_sync_sales_audits_status (status, checked_at),
    CONSTRAINT fk_sync_sales_audits_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_sales_audits_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_sales_audit_days (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_sales_audit_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    audit_date DATE NOT NULL,
    remote_total INT UNSIGNED NOT NULL DEFAULT 0,
    local_total INT UNSIGNED NOT NULL DEFAULT 0,
    difference_count INT NOT NULL DEFAULT 0,
    status ENUM('complete','incomplete','not_audited','error','blocked') NOT NULL DEFAULT 'not_audited',
    error_message VARCHAR(500) NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sync_sales_audit_day (meli_account_id, audit_date),
    KEY idx_sync_sales_audit_days_audit (sync_sales_audit_id, audit_date),
    CONSTRAINT fk_sync_sales_audit_days_audit FOREIGN KEY (sync_sales_audit_id) REFERENCES sync_sales_audits(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_sales_audit_days_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_sales_audit_missing_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_sales_audit_day_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(80) NOT NULL,
    status ENUM('missing','present','ignored') NOT NULL DEFAULT 'missing',
    found_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sync_sales_missing_order (meli_account_id, external_order_id),
    KEY idx_sync_sales_missing_day (sync_sales_audit_day_id, status),
    CONSTRAINT fk_sync_sales_missing_day FOREIGN KEY (sync_sales_audit_day_id) REFERENCES sync_sales_audit_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_sales_missing_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_recurring_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    rule_type ENUM('orders','questions','shipments') NOT NULL DEFAULT 'orders',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    frequency_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    start_time TIME NOT NULL DEFAULT '09:00:00',
    end_time TIME NOT NULL DEFAULT '19:00:00',
    active_weekdays VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
    overlap_hours INT UNSIGNED NOT NULL DEFAULT 2,
    allow_outside_hours TINYINT(1) NOT NULL DEFAULT 0,
    last_enqueued_at DATETIME NULL,
    next_due_at DATETIME NULL,
    last_result VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sync_recurring_rule (meli_account_id, rule_type),
    KEY idx_sync_recurring_due (enabled, rule_type, next_due_at),
    CONSTRAINT fk_sync_recurring_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, setting_group, is_encrypted)
VALUES
('sync.daily_enabled', '0', 'sync', 0),
('sync.daily_frequency_minutes', '60', 'sync', 0),
('sync.daily_start_time', '09:00', 'sync', 0),
('sync.daily_end_time', '19:00', 'sync', 0),
('sync.daily_overlap_hours', '2', 'sync', 0),
('questions.frequency_minutes', '30', 'questions', 0)
ON DUPLICATE KEY UPDATE setting_group=VALUES(setting_group);

INSERT IGNORE INTO app_versions (version, notes)
VALUES ('2.4.6', 'Auditoria de ventas, sincronizacion diaria programada y correccion UI');
