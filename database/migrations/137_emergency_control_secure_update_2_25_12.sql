CREATE TABLE IF NOT EXISTS system_emergency_control_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(80) NOT NULL,
    actor_hash CHAR(64) NOT NULL,
    reason_summary VARCHAR(500) NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'filesystem',
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emergency_events_time (occurred_at),
    KEY idx_emergency_events_key_time (event_key, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_emergency_canary_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    state VARCHAR(30) NOT NULL,
    max_remote_calls SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    used_remote_calls SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    safe_result VARCHAR(80) NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_emergency_canary_state (state, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
VALUES
('emergency.control.enabled','1',0,'system'),
('emergency.session_ttl_seconds','600',0,'system'),
('emergency.canary_max_remote_calls','1',0,'system'),
('update.secure_backup_default','1',0,'system'),
('update.backup_encryption','xchacha20poly1305',0,'system')
ON DUPLICATE KEY UPDATE
setting_value=VALUES(setting_value),
is_encrypted=VALUES(is_encrypted),
setting_group=VALUES(setting_group);

INSERT INTO app_versions (version,notes)
VALUES ('2.25.12','Freno de mano independiente y actualizador con respaldo cifrado.')
ON DUPLICATE KEY UPDATE notes=VALUES(notes);
