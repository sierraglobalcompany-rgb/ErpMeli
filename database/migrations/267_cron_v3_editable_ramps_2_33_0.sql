-- ERP Meli 2.33.0 - Rampas HTTP editables con protecciones duras.
-- Solo configuración técnica segura; no modifica tokens ni credenciales.

CREATE TABLE IF NOT EXISTS cron_v3_ramp_profiles (
  profile_key VARCHAR(40) NOT NULL,
  label VARCHAR(80) NOT NULL,
  target_http_per_minute SMALLINT UNSIGNED NOT NULL,
  ramp_steps_json JSON NOT NULL,
  min_stable_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 1440,
  min_known_responses INT UNSIGNED NOT NULL DEFAULT 60,
  max_429 INT UNSIGNED NOT NULL DEFAULT 0,
  max_lease_lost INT UNSIGNED NOT NULL DEFAULT 0,
  max_duplicates INT UNSIGNED NOT NULL DEFAULT 0,
  p95_http_ms INT UNSIGNED NOT NULL DEFAULT 5000,
  require_executable_backlog_decreasing TINYINT(1) NOT NULL DEFAULT 1,
  editable TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (profile_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cron_v3_ramp_profiles
  (profile_key,label,target_http_per_minute,ramp_steps_json,min_stable_minutes,
   min_known_responses,max_429,max_lease_lost,max_duplicates,p95_http_ms,
   require_executable_backlog_decreasing,editable)
VALUES
  ('conservative','Conservador',10,JSON_ARRAY(5,10),1440,60,0,0,0,5000,1,1),
  ('balanced','Equilibrado',20,JSON_ARRAY(10,15,20),1440,60,0,0,0,5000,1,1),
  ('fast','Rápido',30,JSON_ARRAY(15,20,25,30),1440,60,0,0,0,5000,1,1),
  ('maximum','Máximo controlado',40,JSON_ARRAY(15,20,25,30,35,40),1440,60,0,0,0,5000,1,1),
  ('custom','Personalizado',40,JSON_ARRAY(15,20,25,30,35,40),1440,60,0,0,0,5000,1,1)
ON DUPLICATE KEY UPDATE
  label=VALUES(label),
  target_http_per_minute=IF(profile_key='custom', target_http_per_minute, VALUES(target_http_per_minute)),
  ramp_steps_json=IF(profile_key='custom', ramp_steps_json, VALUES(ramp_steps_json)),
  min_stable_minutes=IF(profile_key='custom', min_stable_minutes, VALUES(min_stable_minutes)),
  min_known_responses=IF(profile_key='custom', min_known_responses, VALUES(min_known_responses)),
  max_429=IF(profile_key='custom', max_429, VALUES(max_429)),
  max_lease_lost=IF(profile_key='custom', max_lease_lost, VALUES(max_lease_lost)),
  max_duplicates=IF(profile_key='custom', max_duplicates, VALUES(max_duplicates)),
  p95_http_ms=IF(profile_key='custom', p95_http_ms, VALUES(p95_http_ms)),
  require_executable_backlog_decreasing=IF(profile_key='custom', require_executable_backlog_decreasing, VALUES(require_executable_backlog_decreasing)),
  editable=VALUES(editable);

INSERT INTO app_settings (setting_key, setting_value, is_encrypted, setting_group)
VALUES
  ('cron_v3.editable_ramps_enabled', '1', 0, 'cron_v3'),
  ('api.rhythm.ramp_source', 'cron_v3_ramp_profiles', 0, 'api_rhythm'),
  ('api.rhythm.ramp_evaluation_minutes', '1440', 0, 'api_rhythm')
ON DUPLICATE KEY UPDATE
  setting_value=VALUES(setting_value),
  is_encrypted=0,
  setting_group=VALUES(setting_group);
