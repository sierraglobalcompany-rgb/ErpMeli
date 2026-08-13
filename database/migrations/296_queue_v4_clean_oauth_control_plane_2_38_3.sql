-- Queue V4 Clean automatic OAuth control plane. Never stores OAuth credentials.

-- A composite parent key makes the tenant relationship physically indivisible.
-- DDL is crash-reentrant: a retry after an interrupted migration must not fail
-- merely because the index was already published before schema_migrations.
SET @oauth_parent_key_sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_accounts'
       AND INDEX_NAME='uq_meli_accounts_company_id')=0,
    'ALTER TABLE meli_accounts ADD UNIQUE KEY uq_meli_accounts_company_id (company_id,id)',
    'DO 1'
);
PREPARE oauth_parent_key_statement FROM @oauth_parent_key_sql;
EXECUTE oauth_parent_key_statement;
DEALLOCATE PREPARE oauth_parent_key_statement;

CREATE TABLE IF NOT EXISTS oauth_refresh_operations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    expected_meli_user_id VARCHAR(191) NOT NULL,
    expected_refresh_version BIGINT UNSIGNED NOT NULL,
    state ENUM(
        'SCHEDULED','RUNNING','WAITING','REMOTE_UNCERTAIN',
        'RECONNECT_REQUIRED','FAILED','COMPLETED'
    ) NOT NULL DEFAULT 'SCHEDULED',
    next_attempt_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    lease_owner VARCHAR(96) NULL,
    lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lease_expires_at DATETIME(3) NULL,
    remote_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    remote_dispatch_state ENUM('NOT_DISPATCHED','MAY_HAVE_DISPATCHED','RESPONSE_KNOWN')
        NOT NULL DEFAULT 'NOT_DISPATCHED',
    remote_dispatched_at DATETIME(3) NULL,
    response_known_at DATETIME(3) NULL,
    last_http_status SMALLINT UNSIGNED NULL,
    last_error_class VARCHAR(100) NULL,
    last_request_id VARCHAR(64) NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    completed_at DATETIME(3) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_refresh_generation (company_id,meli_account_id,expected_refresh_version),
    KEY idx_oauth_refresh_due (state,next_attempt_at,id),
    KEY idx_oauth_refresh_tenant (company_id,meli_account_id,state,next_attempt_at,id),
    KEY idx_oauth_refresh_lease (state,lease_expires_at,id),
    CONSTRAINT fk_oauth_refresh_tenant FOREIGN KEY (company_id,meli_account_id)
        REFERENCES meli_accounts(company_id,id),
    CONSTRAINT chk_oauth_refresh_lease CHECK (
        (state='RUNNING' AND lease_owner IS NOT NULL AND lease_expires_at IS NOT NULL)
        OR state<>'RUNNING'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings
    (setting_key,setting_value,is_encrypted,setting_group)
VALUES
    ('oauth.auto_refresh_lead_seconds','3600',0,'mercadolibre'),
    ('oauth.auto_refresh_global_reserve_per_15m','3',0,'mercadolibre'),
    ('oauth.auto_refresh_account_reserve_per_15m','1',0,'mercadolibre');
