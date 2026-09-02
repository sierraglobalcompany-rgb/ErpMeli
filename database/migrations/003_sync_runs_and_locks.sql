CREATE TABLE IF NOT EXISTS meli_sync_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sync_type VARCHAR(60) NOT NULL,
    date_from DATETIME NULL,
    date_to DATETIME NULL,
    status ENUM('running','success','partial','error') NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sync_runs_account_type_started (meli_account_id, sync_type, started_at),
    KEY idx_sync_runs_status_started (status, started_at),
    CONSTRAINT fk_sync_runs_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_sync_runs_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meli_sync_locks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lock_key CHAR(64) NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    sync_type VARCHAR(60) NOT NULL,
    date_from DATETIME NULL,
    date_to DATETIME NULL,
    locked_until DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sync_lock_key (lock_key),
    KEY idx_sync_locks_account_type (meli_account_id, sync_type),
    KEY idx_sync_locks_expiry (locked_until),
    CONSTRAINT fk_sync_locks_account FOREIGN KEY (meli_account_id) REFERENCES meli_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
