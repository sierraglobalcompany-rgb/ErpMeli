-- ERP Meli 2.34.0 - Autoridad única de ritmo V3.
-- Solo configuración técnica. No toca tokens, credenciales, órdenes, pagos,
-- packs, envíos, OAuth, cierres ni evidencia fiscal.

CREATE TABLE IF NOT EXISTS cron_v3_rate_policy_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  authority_key VARCHAR(80) NOT NULL,
  profile_key VARCHAR(40) NOT NULL,
  target_http_per_minute SMALLINT UNSIGNED NOT NULL,
  current_adaptive_limit SMALLINT UNSIGNED NOT NULL,
  minimum_interval_ms INT UNSIGNED NOT NULL,
  rolling_window_seconds SMALLINT UNSIGNED NOT NULL,
  source VARCHAR(40) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_cron_v3_rate_policy_audit_created (created_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('app.version', '2.34.0', 0, 'system'),
  ('cron_v3.rate_authority', 'api.rhythm', 0, 'cron_v3'),
  ('cron_v3.rate_env_fallback_only', '1', 0, 'cron_v3'),
  ('cron_v3.remote_rate_limit', '40', 0, 'cron_v3'),
  ('api.rhythm.minimum_interval_ms', '1000', 0, 'api_rhythm'),
  ('api.rhythm.rolling_window_seconds', '60', 0, 'api_rhythm'),
  ('api.rhythm.transport_unit', 'http_started', 0, 'api_rhythm')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);

INSERT INTO app_versions (version, notes)
VALUES ('2.34.0', 'Cron V3 usa una sola autoridad persistida para el ritmo; CRON_V3_RATE_LIMIT queda como fallback seguro.')
ON DUPLICATE KEY UPDATE installed_at=UTC_TIMESTAMP();
