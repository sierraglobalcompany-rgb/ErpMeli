<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CronV3RetirementForV4Service;
$dsn = getenv('ERP_2365_MYSQL_DSN') ?: '';
$user = getenv('ERP_2365_MYSQL_USER') ?: '';
$password = getenv('ERP_2365_MYSQL_PASS') ?: '';
if ($dsn === '') {
    echo "Cron V3 retirement 2.36.5: SKIP (ERP_2365_MYSQL_DSN absent)\n";
    exit(0);
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$suffix = strtolower(bin2hex(random_bytes(5)));
$dbA = 'erp_2365_oracle_' . $suffix;
$dbB = 'erp_2365_service_' . $suffix;
$config = tempnam(sys_get_temp_dir(), 'erp-2365-retirement-');
if ($config === false) {
    throw new RuntimeException('config_fixture_unavailable');
}
$flags = ['CRON_V3_ENABLED', 'CRON_V3_SHADOW_ENABLED', 'CRON_V4_ENABLED', 'ML_WRITE_ENABLED'];
$admin = null;

$connect = static function (string $database = '') use ($dsn, $user, $password): PDO {
    $target = $dsn . ($database === '' ? '' : ';dbname=' . $database);
    return new PDO($target, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ]);
};
$writeSafeConfig = static function (array $changes = []) use ($config): void {
    $values = array_merge([
        'CRON_V3_ENABLED' => 'false',
        'CRON_V3_SHADOW_ENABLED' => 'false',
        'CRON_V4_ENABLED' => 'false',
        'ML_WRITE_ENABLED' => 'false',
    ], $changes);
    $body = '';
    foreach ($values as $key => $value) {
        $body .= $key . '=' . $value . "\n";
    }
    file_put_contents($config, $body);
};
$createSchema = static function (PDO $pdo): void {
    $ddl = [
        "CREATE TABLE app_settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value LONGTEXT NULL, is_encrypted TINYINT(1) NOT NULL DEFAULT 0, setting_group VARCHAR(80) NOT NULL DEFAULT 'general', updated_at TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB",
        'CREATE TABLE schema_migrations (version VARCHAR(255) PRIMARY KEY) ENGINE=InnoDB',
        "CREATE TABLE queue_engine_control (control_key VARCHAR(32) PRIMARY KEY, active_engine VARCHAR(32) NOT NULL, readiness_mode VARCHAR(32) NOT NULL, readiness_context_hash CHAR(64) NULL, generation BIGINT UNSIGNED NOT NULL, changed_by VARCHAR(120) NULL, changed_at TIMESTAMP(3) NULL) ENGINE=InnoDB",
        "CREATE TABLE cron_v3_queue_ownership (queue_key VARCHAR(80) PRIMARY KEY, lane VARCHAR(32) NOT NULL, owner_engine VARCHAR(32) NOT NULL, enabled TINYINT(1) NOT NULL, changed_by VARCHAR(120) NULL, changed_at DATETIME(6) NULL) ENGINE=InnoDB",
        'CREATE TABLE cron_v3_work (id BIGINT PRIMARY KEY, payload_marker VARCHAR(80) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE cron_v3_attempts (id BIGINT PRIMARY KEY, payload_marker VARCHAR(80) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE business_sentinel (id BIGINT PRIMARY KEY, immutable_value VARCHAR(80) NOT NULL) ENGINE=InnoDB',
    ];
    foreach ($ddl as $sql) {
        $pdo->exec($sql);
    }
};
$seed = static function (PDO $pdo, string $version = '2.36.5'): void {
    foreach (['app_settings', 'schema_migrations', 'queue_engine_control', 'cron_v3_queue_ownership', 'cron_v3_work', 'cron_v3_attempts', 'business_sentinel'] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    $migration = $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)');
    for ($i = 1; $i <= 292; $i++) {
        $migration->execute([sprintf('%03d_fixture.sql', $i)]);
    }
    $migration->execute([CronV3RetirementForV4Service::LAST_MIGRATION]);
    $settings = [
        'app.version' => $version,
        'cron_v3.enabled' => '0',
        'cron_v3.shadow_enabled' => '0',
        'cron_v3.operational_mode' => '1',
        'cron_v3.v2_runtime_disabled' => '0',
        'cron_v3.rollback_enabled' => '1',
        'cron_v3.operational_phase' => 'operational_active',
        'cron_v3.certified_cutover.enabled' => '1',
        'cron_v3.certified_cutover.phase' => 'operational_active',
        'cron_v3.canary.phase' => 'active',
    ];
    $insertSetting = $pdo->prepare("INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES (?,?,0,'cron_v3')");
    foreach ($settings as $key => $value) {
        $insertSetting->execute([$key, $value]);
    }
    $pdo->exec("INSERT INTO queue_engine_control VALUES ('primary','disabled','idle',NULL,17,'fixture','2026-08-11 00:00:00.000')");
    $pdo->exec("INSERT INTO cron_v3_queue_ownership VALUES
        ('financial_recalc','local','v3',1,'fixture','2026-08-10 12:00:00.000000'),
        ('shipment_exact','remote','v3',1,'fixture','2026-08-10 12:00:01.000000'),
        ('disabled_history','local','disabled',0,'fixture','2026-08-10 12:00:02.000000')");
    $pdo->exec("INSERT INTO cron_v3_work VALUES (1,'work-stable'),(2,'work-stable-2')");
    $pdo->exec("INSERT INTO cron_v3_attempts VALUES (1,'attempt-stable')");
    $pdo->exec("INSERT INTO business_sentinel VALUES (1,'business-unchanged')");
};
$snapshot = static function (PDO $pdo): array {
    $keys = array_keys(CronV3RetirementForV4Service::TARGET_SETTINGS);
    $marks = implode(',', array_fill(0, count($keys), '?'));
    $query = $pdo->prepare('SELECT setting_key,setting_value,is_encrypted,setting_group FROM app_settings WHERE setting_key IN (' . $marks . ') ORDER BY BINARY setting_key');
    $query->execute($keys);
    return [
        'settings' => $query->fetchAll(),
        'ownership' => $pdo->query('SELECT queue_key,lane,owner_engine,enabled,changed_by FROM cron_v3_queue_ownership ORDER BY BINARY queue_key')->fetchAll(),
        'engine' => $pdo->query("SELECT active_engine,readiness_mode,readiness_context_hash,generation FROM queue_engine_control WHERE control_key='primary'")->fetch(),
        'work' => $pdo->query('SELECT * FROM cron_v3_work ORDER BY id')->fetchAll(),
        'attempts' => $pdo->query('SELECT * FROM cron_v3_attempts ORDER BY id')->fetchAll(),
        'business' => $pdo->query('SELECT * FROM business_sentinel ORDER BY id')->fetchAll(),
    ];
};
$runOracle = static function (PDO $pdo, string $path): array {
    $raw = (string) file_get_contents($path);
    if (preg_match('/SET @ERP_V3_RETIREMENT_ACK\s*=\s*\x27([^\x27]+)\x27;/', $raw, $ack) !== 1
        || preg_match('/DELIMITER \$\$\s*(BEGIN NOT ATOMIC.*?END)\$\$\s*DELIMITER ;/s', $raw, $compound) !== 1) {
        throw new RuntimeException('oracle_parse_failed');
    }
    $pdo->exec("SET @ERP_V3_RETIREMENT_ACK=" . $pdo->quote($ack[1]));
    $statement = $pdo->query($compound[1]);
    $receipt = [];
    do {
        if ($statement->columnCount() > 0) {
            foreach ($statement->fetchAll() as $row) {
                if (isset($row['V3_PREIMAGE_HASH'])) {
                    $receipt = $row;
                }
            }
        }
    } while ($statement->nextRowset());
    return $receipt;
};

try {
    foreach ($flags as $flag) {
        putenv($flag);
        unset($_ENV[$flag], $_SERVER[$flag]);
    }
    $writeSafeConfig();
    $admin = $connect();
    $admin->exec('CREATE DATABASE `' . $dbA . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec('CREATE DATABASE `' . $dbB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoA = $connect($dbA);
    $pdoB = $connect($dbB);
    $createSchema($pdoA);
    $createSchema($pdoB);
    $seed($pdoA, '2.36.3');
    $seed($pdoB, '2.36.5');

    $oracleReceipt = $runOracle($pdoA, dirname(__DIR__) . '/docs/release-authorities/V3_RETIREMENT_FOR_V4_ORACLE.sql');
    $factoryB = static fn (): PDO => $connect($dbB);
    $service = new CronV3RetirementForV4Service($factoryB, $config);
    $result = $service->retire(7, CronV3RetirementForV4Service::CONFIRMATION_PHRASE);
    $assert($result['ok'] === true, 'nominal_service_failed');
    $assert($snapshot($pdoA) === $snapshot($pdoB), 'sql_service_poststate_mismatch');
    $assert(($oracleReceipt['V3_PREIMAGE_HASH'] ?? '') === ($result['receipt']['preimage_hash'] ?? ''), 'preimage_hash_not_equivalent');
    $assert(($result['receipt']['business_rows_changed'] ?? -1) === 0, 'business_receipt_invalid');

    $beforeReplay = $snapshot($pdoB);
    try {
        $service->retire(7, CronV3RetirementForV4Service::CONFIRMATION_PHRASE);
        throw new RuntimeException('second_execution_not_blocked');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'v3_retirement_already_completed', 'second_execution_reason_invalid');
    }
    $assert($snapshot($pdoB) === $beforeReplay, 'second_execution_mutated');

    $negativeDb = [
        'version' => static fn (PDO $pdo) => $pdo->exec("UPDATE app_settings SET setting_value='2.36.4' WHERE setting_key='app.version'"),
        'schema' => static fn (PDO $pdo) => $pdo->exec("DELETE FROM schema_migrations WHERE version='293_queue_core_runtime_profile_defaults_b2_1.sql'"),
        'engine' => static fn (PDO $pdo) => $pdo->exec("UPDATE queue_engine_control SET active_engine='v4' WHERE control_key='primary'"),
        'foreign' => static fn (PDO $pdo) => $pdo->exec("INSERT INTO cron_v3_queue_ownership VALUES ('foreign','remote','v4',1,'fixture','2026-08-10 12:00:03.000000')"),
    ];
    foreach ($negativeDb as $label => $mutate) {
        $seed($pdoB);
        $mutate($pdoB);
        $before = $snapshot($pdoB);
        try {
            $service->retire(7, CronV3RetirementForV4Service::CONFIRMATION_PHRASE);
            throw new RuntimeException($label . '_not_blocked');
        } catch (RuntimeException $error) {
            $assert(str_starts_with($error->getMessage(), 'v3_retirement_'), $label . '_unsafe_error');
        }
        $assert($snapshot($pdoB) === $before, $label . '_mutated');
    }

    foreach (['CRON_V3_ENABLED', 'CRON_V3_SHADOW_ENABLED', 'CRON_V4_ENABLED', 'ML_WRITE_ENABLED'] as $flag) {
        $seed($pdoB);
        $writeSafeConfig([$flag => 'true']);
        $before = $snapshot($pdoB);
        try {
            $service->retire(7, CronV3RetirementForV4Service::CONFIRMATION_PHRASE);
            throw new RuntimeException($flag . '_not_blocked');
        } catch (RuntimeException $error) {
            $assert($error->getMessage() === 'v3_retirement_effective_flags_invalid', $flag . '_reason_invalid');
        }
        $assert($snapshot($pdoB) === $before, $flag . '_mutated');
    }
    $writeSafeConfig();
    $seed($pdoB);
    putenv('CRON_V4_ENABLED=true');
    try {
        $service->retire(7, CronV3RetirementForV4Service::CONFIRMATION_PHRASE);
        throw new RuntimeException('process_override_not_blocked');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'v3_retirement_process_override_conflict', 'process_override_reason_invalid');
    } finally {
        putenv('CRON_V4_ENABLED');
    }

    $seed($pdoB);
    $busy = $connect($dbB);
    $lock = $busy->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute([CronV3RetirementForV4Service::LOCK_NAME]);
    $assert((int) $lock->fetchColumn() === 1, 'busy_lock_fixture_failed');
    try {
        $service->retire(7, CronV3RetirementForV4Service::CONFIRMATION_PHRASE);
        throw new RuntimeException('busy_lock_not_blocked');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'v3_retirement_lock_busy', 'busy_lock_reason_invalid');
    } finally {
        $busy->query("SELECT RELEASE_LOCK('" . CronV3RetirementForV4Service::LOCK_NAME . "')")->fetchColumn();
    }

    foreach (['before_first_mutation', 'after_ownership', 'after_settings', 'after_postconditions', 'before_commit'] as $point) {
        $seed($pdoB);
        $before = $snapshot($pdoB);
        $fault = new CronV3RetirementForV4Service($factoryB, $config, static function (string $name) use ($point): void {
            if ($name === $point) {
                throw new RuntimeException('fixture_failpoint:' . $point);
            }
        });
        try {
            $fault->retire(7, CronV3RetirementForV4Service::CONFIRMATION_PHRASE);
            throw new RuntimeException($point . '_not_triggered');
        } catch (RuntimeException $error) {
            $assert($error->getMessage() === 'fixture_failpoint:' . $point, $point . '_reason_invalid');
        }
        $assert($snapshot($pdoB) === $before, $point . '_rollback_failed');
    }

    echo 'PASS cron_v3_retirement_2365_mysql checks=' . $checks . ' SQL_SERVICE_POSTSTATE_EQUIVALENCE=PASS' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL cron_v3_retirement_2365_mysql ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    foreach ($flags as $flag) {
        putenv($flag);
        unset($_ENV[$flag], $_SERVER[$flag]);
    }
    @unlink($config);
    if ($admin instanceof PDO) {
        foreach ([$dbA, $dbB] as $database) {
            try {
                $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
            } catch (Throwable) {
            }
        }
    }
}
