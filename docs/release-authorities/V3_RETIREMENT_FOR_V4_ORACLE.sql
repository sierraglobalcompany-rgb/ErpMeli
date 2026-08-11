-- ERP MELI 2.36.3 — retiro definitivo de autoridad Cron V3 para preparar V4.
-- MariaDB 11.8.x. Ejecución MANUAL en phpMyAdmin, una sola vez.
--
-- ANTES DE EJECUTAR:
-- 1. En /erp-meli/settings/cron use la acción existente
--    "Preparar configuración segura". Esa acción debe dejar efectivos:
--      CRON_V3_ENABLED=false
--      CRON_V3_SHADOW_ENABLED=false
--      CRON_V4_ENABLED=false
--      ML_WRITE_ENABLED=false
--    y no debe existir ningún override de proceso que fuerce V3=true.
-- 2. Confirme que Hostinger no tiene tareas Cron (autoridad informada: vacía).
-- 3. Cambie SOLAMENTE el valor UNVERIFIED de la siguiente línea por el ACK
--    literal indicado. Sin ese cambio, el script aborta antes de mutar.

SET @ERP_V3_RETIREMENT_ACK = 'I_CONFIRMED_V3_AND_SHADOW_FALSE_V4_FALSE_ML_WRITE_FALSE_NO_PROCESS_OVERRIDE';
-- Valor habilitante exacto:
-- I_CONFIRMED_V3_AND_SHADOW_FALSE_V4_FALSE_ML_WRITE_FALSE_NO_PROCESS_OVERRIDE

DELIMITER $$

BEGIN NOT ATOMIC
    DECLARE v_lock_acquired INT DEFAULT 0;
    DECLARE v_schema_count BIGINT DEFAULT 0;
    DECLARE v_schema_max INT DEFAULT 0;
    DECLARE v_schema_293 BIGINT DEFAULT 0;
    DECLARE v_app_version_count BIGINT DEFAULT 0;
    DECLARE v_engine_count BIGINT DEFAULT 0;
    DECLARE v_engine_generation BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_engine_bad BIGINT DEFAULT 0;
    DECLARE v_table_bad BIGINT DEFAULT 0;
    DECLARE v_trigger_count BIGINT DEFAULT 0;
    DECLARE v_invalid_enabled_ownership BIGINT DEFAULT 0;
    DECLARE v_active_pre BIGINT DEFAULT 0;
    DECLARE v_active_post BIGINT DEFAULT 0;
    DECLARE v_ownership_changed BIGINT DEFAULT 0;
    DECLARE v_settings_changed BIGINT DEFAULT 0;
    DECLARE v_settings_post_ok BIGINT DEFAULT 0;
    DECLARE v_work_pre BIGINT DEFAULT 0;
    DECLARE v_work_post BIGINT DEFAULT 0;
    DECLARE v_attempt_pre BIGINT DEFAULT 0;
    DECLARE v_attempt_post BIGINT DEFAULT 0;
    DECLARE v_preimage_hash CHAR(64) DEFAULT '';

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        IF v_lock_acquired = 1 THEN
            DO RELEASE_LOCK('erp_meli_v3_retirement_for_v4_2363');
        END IF;
        RESIGNAL;
    END;

    IF COALESCE(@ERP_V3_RETIREMENT_ACK, '') <>
       'I_CONFIRMED_V3_AND_SHADOW_FALSE_V4_FALSE_ML_WRITE_FALSE_NO_PROCESS_OVERRIDE' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: effective runtime flags/process overrides were not manually certified';
    END IF;

    SELECT GET_LOCK('erp_meli_v3_retirement_for_v4_2363', 0)
      INTO v_lock_acquired;
    IF v_lock_acquired <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: V3 retirement authority lock is busy';
    END IF;

    SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
    SET SESSION time_zone = '+00:00';
    SET SESSION group_concat_max_len = 1048576;
    START TRANSACTION;

    -- Autoridad de release/schema: exactamente 2.36.3 y schema 293.
    SELECT COUNT(*) INTO v_app_version_count
      FROM app_settings
     WHERE setting_key = 'app.version'
       AND setting_value = '2.36.3';

    SELECT COUNT(*),
           COALESCE(MAX(CAST(SUBSTRING_INDEX(version, '_', 1) AS UNSIGNED)), 0),
           SUM(version = '293_queue_core_runtime_profile_defaults_b2_1.sql')
      INTO v_schema_count, v_schema_max, v_schema_293
      FROM schema_migrations;

    IF v_app_version_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: app.version is not exactly 2.36.3';
    END IF;
    IF v_schema_count <> 293 OR v_schema_max <> 293 OR v_schema_293 <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: schema authority is not exactly 293';
    END IF;

    -- Las únicas tablas mutadas deben ser BASE TABLE/InnoDB y no tener triggers.
    SELECT COUNT(*) INTO v_table_bad
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name IN ('app_settings', 'cron_v3_queue_ownership')
       AND (table_type <> 'BASE TABLE' OR engine <> 'InnoDB');
    IF v_table_bad <> 0 OR
       (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('app_settings', 'cron_v3_queue_ownership')) <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: retirement tables are missing or non-transactional';
    END IF;

    SELECT COUNT(*) INTO v_trigger_count
      FROM information_schema.triggers
     WHERE trigger_schema = DATABASE()
       AND event_object_table IN ('app_settings', 'cron_v3_queue_ownership');
    IF v_trigger_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: unexpected trigger on retirement write-set';
    END IF;

    -- Queue Engine debe estar ya disabled/idle. Este script no toca generation.
    SELECT COUNT(*) INTO v_engine_count
      FROM queue_engine_control
     WHERE control_key = 'primary';
    SELECT COUNT(*) INTO v_engine_bad
      FROM queue_engine_control
     WHERE control_key = 'primary'
       AND (active_engine <> 'disabled'
            OR readiness_mode <> 'idle'
            OR readiness_context_hash IS NOT NULL);
    IF v_engine_count <> 1 OR v_engine_bad <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: Queue Engine is not disabled/idle';
    END IF;

    SELECT generation INTO v_engine_generation
      FROM queue_engine_control
     WHERE control_key = 'primary'
     FOR UPDATE;

    -- Fences exactos. No se bloquea, lee ni modifica payload/backlog histórico.
    SELECT setting_key
      FROM app_settings
     WHERE setting_key IN (
        'cron_v3.enabled',
        'cron_v3.shadow_enabled',
        'cron_v3.operational_mode',
        'cron_v3.v2_runtime_disabled',
        'cron_v3.rollback_enabled',
        'cron_v3.operational_phase',
        'cron_v3.certified_cutover.enabled',
        'cron_v3.certified_cutover.phase',
        'cron_v3.canary.phase'
     )
     ORDER BY setting_key
     FOR UPDATE;

    SELECT queue_key
      FROM cron_v3_queue_ownership
     WHERE owner_engine = 'v3' OR enabled = 1
     ORDER BY lane, queue_key
     FOR UPDATE;

    SELECT COUNT(*) INTO v_invalid_enabled_ownership
      FROM cron_v3_queue_ownership
     WHERE enabled = 1 AND owner_engine <> 'v3';
    IF v_invalid_enabled_ownership <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: enabled ownership exists outside V3 authority';
    END IF;

    SELECT COUNT(*) INTO v_active_pre
      FROM cron_v3_queue_ownership
     WHERE owner_engine = 'v3' OR enabled = 1;
    IF v_active_pre < 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: no active V3 ownership found; do not replay this script';
    END IF;

    SELECT COUNT(*) INTO v_work_pre FROM cron_v3_work;
    SELECT COUNT(*) INTO v_attempt_pre FROM cron_v3_attempts;

    SELECT SHA2(CONCAT(
        'erp-meli-2363-v3-retirement-preimage-v1|settings|',
        COALESCE((
            SELECT GROUP_CONCAT(
                CONCAT(setting_key, '=', COALESCE(setting_value, '<SQL-NULL>'))
                ORDER BY setting_key SEPARATOR '\n'
            )
              FROM app_settings
             WHERE setting_key IN (
                'cron_v3.enabled','cron_v3.shadow_enabled',
                'cron_v3.operational_mode','cron_v3.v2_runtime_disabled',
                'cron_v3.rollback_enabled','cron_v3.operational_phase',
                'cron_v3.certified_cutover.enabled',
                'cron_v3.certified_cutover.phase','cron_v3.canary.phase'
             )
        ), '<NONE>'),
        '|ownership|',
        COALESCE((
            SELECT GROUP_CONCAT(
                CONCAT_WS('|', queue_key, lane, owner_engine, enabled,
                          COALESCE(changed_by, '<SQL-NULL>'),
                          COALESCE(DATE_FORMAT(changed_at, '%Y-%m-%dT%H:%i:%s.%fZ'), '<SQL-NULL>'))
                ORDER BY lane, queue_key SEPARATOR '\n'
            )
              FROM cron_v3_queue_ownership
             WHERE owner_engine = 'v3' OR enabled = 1
        ), '<NONE>'),
        '|engine|disabled|idle|', v_engine_generation
    ), 256) INTO v_preimage_hash;

    -- Receipt PRE visible en phpMyAdmin; no contiene secretos ni payloads.
    SELECT v_preimage_hash AS V3_PREIMAGE_HASH,
           v_active_pre AS ACTIVE_V3_OWNERSHIP_PRE,
           v_engine_generation AS QUEUE_ENGINE_GENERATION_PRE,
           v_work_pre AS CRON_V3_WORK_ROWS_PRE,
           v_attempt_pre AS CRON_V3_ATTEMPT_ROWS_PRE;

    SELECT setting_key, setting_value
      FROM app_settings
     WHERE setting_key IN (
        'cron_v3.enabled','cron_v3.shadow_enabled',
        'cron_v3.operational_mode','cron_v3.v2_runtime_disabled',
        'cron_v3.rollback_enabled','cron_v3.operational_phase',
        'cron_v3.certified_cutover.enabled',
        'cron_v3.certified_cutover.phase','cron_v3.canary.phase'
     )
     ORDER BY setting_key;

    SELECT queue_key, lane, owner_engine, enabled, changed_by, changed_at
      FROM cron_v3_queue_ownership
     WHERE owner_engine = 'v3' OR enabled = 1
     ORDER BY lane, queue_key;

    -- Única transición de ownership: no borra filas ni evidencia.
    UPDATE cron_v3_queue_ownership
       SET owner_engine = 'disabled',
           enabled = 0,
           changed_by = 'v3_retired_for_v4_2363',
           changed_at = UTC_TIMESTAMP(3)
     WHERE owner_engine = 'v3' OR enabled = 1;
    SET v_ownership_changed = ROW_COUNT();
    IF v_ownership_changed <> v_active_pre THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: ownership CAS count changed';
    END IF;

    -- Nueve mirrors/settings técnicos; V2 permanece explícitamente deshabilitado.
    INSERT INTO app_settings
        (setting_key, setting_value, is_encrypted, setting_group)
    VALUES
        ('cron_v3.enabled', '0', 0, 'cron_v3'),
        ('cron_v3.shadow_enabled', '0', 0, 'cron_v3'),
        ('cron_v3.operational_mode', '0', 0, 'cron_v3'),
        ('cron_v3.v2_runtime_disabled', '1', 0, 'cron_v3'),
        ('cron_v3.rollback_enabled', '0', 0, 'cron_v3'),
        ('cron_v3.operational_phase', 'retired_for_v4', 0, 'cron_v3'),
        ('cron_v3.certified_cutover.enabled', '0', 0, 'cron_v3'),
        ('cron_v3.certified_cutover.phase', 'retired_for_v4', 0, 'cron_v3'),
        ('cron_v3.canary.phase', 'rolled_back', 0, 'cron_v3')
    ON DUPLICATE KEY UPDATE
        setting_value = VALUES(setting_value),
        is_encrypted = 0,
        setting_group = 'cron_v3';
    SET v_settings_changed = ROW_COUNT();

    SELECT COUNT(*) INTO v_settings_post_ok
      FROM app_settings
     WHERE (setting_key = 'cron_v3.enabled' AND setting_value = '0')
        OR (setting_key = 'cron_v3.shadow_enabled' AND setting_value = '0')
        OR (setting_key = 'cron_v3.operational_mode' AND setting_value = '0')
        OR (setting_key = 'cron_v3.v2_runtime_disabled' AND setting_value = '1')
        OR (setting_key = 'cron_v3.rollback_enabled' AND setting_value = '0')
        OR (setting_key = 'cron_v3.operational_phase' AND setting_value = 'retired_for_v4')
        OR (setting_key = 'cron_v3.certified_cutover.enabled' AND setting_value = '0')
        OR (setting_key = 'cron_v3.certified_cutover.phase' AND setting_value = 'retired_for_v4')
        OR (setting_key = 'cron_v3.canary.phase' AND setting_value = 'rolled_back');
    IF v_settings_post_ok <> 9 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: V3 retirement settings postcondition failed';
    END IF;

    SELECT COUNT(*) INTO v_active_post
      FROM cron_v3_queue_ownership
     WHERE owner_engine = 'v3' OR enabled = 1;
    IF v_active_post <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: active V3 ownership remains';
    END IF;

    SELECT COUNT(*) INTO v_work_post FROM cron_v3_work;
    SELECT COUNT(*) INTO v_attempt_post FROM cron_v3_attempts;
    IF v_work_post <> v_work_pre OR v_attempt_post <> v_attempt_pre THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: V3 work/attempt row count changed';
    END IF;

    IF (SELECT COUNT(*) FROM queue_engine_control
         WHERE control_key = 'primary'
           AND active_engine = 'disabled'
           AND readiness_mode = 'idle'
           AND readiness_context_hash IS NULL
           AND generation = v_engine_generation) <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'BLOCK: Queue Engine authority changed';
    END IF;

    COMMIT;
    DO RELEASE_LOCK('erp_meli_v3_retirement_for_v4_2363');
    SET v_lock_acquired = 0;

    -- Receipt POST. BUSINESS_ROWS_CHANGED=0 está garantizado por write-set,
    -- tablas InnoDB y ausencia de triggers sobre las dos tablas mutadas.
    SELECT v_preimage_hash AS V3_PREIMAGE_HASH,
           v_active_pre AS ACTIVE_V3_OWNERSHIP_PRE,
           v_active_post AS ACTIVE_V3_OWNERSHIP_POST,
           v_ownership_changed AS OWNERSHIP_ROWS_CHANGED,
           v_settings_changed AS APP_SETTINGS_ROWCOUNT_REPORTED,
           v_engine_generation AS QUEUE_ENGINE_GENERATION,
           (v_work_post - v_work_pre) AS CRON_V3_WORK_ROWS_CHANGED,
           (v_attempt_post - v_attempt_pre) AS CRON_V3_ATTEMPT_ROWS_CHANGED,
           0 AS BUSINESS_ROWS_CHANGED,
           'PASS' AS V3_RETIREMENT;

    SELECT active_engine AS QUEUE_ENGINE,
           readiness_mode AS QUEUE_ENGINE_READINESS,
           generation AS QUEUE_ENGINE_GENERATION
      FROM queue_engine_control
     WHERE control_key = 'primary';

    SELECT setting_key, setting_value
      FROM app_settings
     WHERE setting_key IN (
        'cron_v3.enabled','cron_v3.shadow_enabled',
        'cron_v3.operational_mode','cron_v3.v2_runtime_disabled',
        'cron_v3.rollback_enabled','cron_v3.operational_phase',
        'cron_v3.certified_cutover.enabled',
        'cron_v3.certified_cutover.phase','cron_v3.canary.phase'
     )
     ORDER BY setting_key;
END$$

DELIMITER ;

-- DESPUÉS DE PASS:
-- - No cree Cron V4.
-- - Regrese al ERP y compruebe que V3 ya no está operational_active.
-- - Ejecute sólo el preflight read-only Queue Core desde la vía CLI autorizada.
-- - Guarde/exporte los result sets PRE/POST como receipt de esta transición.
