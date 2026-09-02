ALTER TABLE users
    ADD COLUMN is_temporary TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN expires_at DATETIME NULL AFTER is_temporary,
    ADD COLUMN revoked_at DATETIME NULL AFTER expires_at,
    ADD COLUMN revoked_by BIGINT UNSIGNED NULL AFTER revoked_at,
    ADD COLUMN temporary_reason VARCHAR(255) NULL AFTER revoked_by,
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER temporary_reason,
    ADD COLUMN last_login_at DATETIME NULL AFTER must_change_password,
    ADD KEY idx_users_temporary_access (is_temporary, status, expires_at, revoked_at),
    ADD CONSTRAINT fk_users_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL;
