<?php

declare(strict_types=1);

$dsn = (string) (getenv('ERP_2368_MYSQL_DSN') ?: '');
$user = (string) (getenv('ERP_2368_MYSQL_USER') ?: '');
$password = (string) (getenv('ERP_2368_MYSQL_PASS') ?: '');
if ($dsn === '') {
    fwrite(STDOUT, "V4 partial-arm recovery 2.36.8: SKIP (ERP_2368_MYSQL_DSN absent)\n");
    exit(0);
}

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/erp-meli-2368-v4-' . bin2hex(random_bytes(6));
$database = 'erp_2368_v4_' . bin2hex(random_bytes(5));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$admin = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    if (!mkdir($fixture . '/storage', 0700, true) && !is_dir($fixture . '/storage')) {
        throw new RuntimeException('fixture_create_failed');
    }
    $private = $fixture . '-private';
    if (!mkdir($private, 0700, true) && !is_dir($private)) {
        throw new RuntimeException('private_create_failed');
    }
    preg_match('/host=([^;]+)/', $dsn, $hostMatch);
    preg_match('/port=([^;]+)/', $dsn, $portMatch);
    $config = implode("\n", [
        'APP_ENV=local',
        'APP_KEY=local-2368-v4-recovery-key',
        'DB_HOST=' . ($hostMatch[1] ?? '127.0.0.1'),
        'DB_PORT=' . ($portMatch[1] ?? '3306'),
        'DB_NAME=' . $database,
        'DB_USER=' . $user,
        'DB_PASS=' . $password,
        'ML_WRITE_ENABLED=false',
        'CRON_V3_ENABLED=false',
        'CRON_V3_SHADOW_ENABLED=false',
        'CRON_V4_ENABLED=true',
        'ERP_PRIVATE_PATH=' . str_replace('\\', '/', $private),
    ]) . "\n";
    file_put_contents($fixture . '/config.env', $config);
    file_put_contents($fixture . '/PAUSE_MELI_API', "test\n");
    file_put_contents($fixture . '/PAUSE_ERP_AUTOMATION', "test\n");

    define('ERP_RELEASE_ROOT', $root);
    define('ERP_INSTALLATION_ROOT', $fixture);
    define('ERP_SHARED_ROOT', $fixture);
    require $root . '/bootstrap.php';

    $pdo = new PDO(
        preg_replace('/;?charset=[^;]+/', '', $dsn) . ';dbname=' . $database . ';charset=utf8mb4',
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $pdo->exec(
        "CREATE TABLE app_settings (
            setting_key VARCHAR(191) PRIMARY KEY,
            setting_value LONGTEXT NULL,
            is_encrypted TINYINT NOT NULL DEFAULT 0,
            setting_group VARCHAR(64) NOT NULL DEFAULT 'general'
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE queue_engine_control (
            control_key VARCHAR(32) PRIMARY KEY,
            active_engine VARCHAR(16) NOT NULL,
            readiness_mode ENUM('idle','preparing') NOT NULL DEFAULT 'idle',
            readiness_context_hash CHAR(64) NULL,
            generation BIGINT UNSIGNED NOT NULL,
            changed_at DATETIME(3) NOT NULL,
            changed_by VARCHAR(96) NOT NULL
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE queue_core_feature_flags (
            feature_key VARCHAR(64) PRIMARY KEY,
            enabled TINYINT NOT NULL,
            hard_cap INT NULL,
            generation BIGINT UNSIGNED NOT NULL,
            updated_at DATETIME(3) NOT NULL DEFAULT UTC_TIMESTAMP(3)
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "INSERT INTO queue_engine_control
         VALUES ('primary','disabled','idle',NULL,0,UTC_TIMESTAMP(3),'fixture')"
    );
    foreach (\App\QueueCore\QueueCoreFeatureFlagService::READINESS_FEATURES as $feature) {
        $statement = $pdo->prepare(
            'INSERT INTO queue_core_feature_flags(feature_key,enabled,hard_cap,generation) VALUES (?,0,NULL,0)'
        );
        $statement->execute([$feature]);
    }
    $pdo->exec(
        "INSERT INTO app_settings VALUES
         ('app.version','2.36.8',0,'system'),
         ('unrelated.authority','preserve-me',0,'test')"
    );

    $service = new \App\Services\V4ReadinessBootstrapService(
        static fn (): PDO => $pdo,
        $fixture . '/config.env',
    );
    $rollback = new ReflectionMethod($service, 'rollbackAuthorities');
    $result = $rollback->invoke($service, $pdo, 7, 'partial_arm_recovery_2368_test');
    $assert(($result['state'] ?? '') === 'rolled_back', 'partial_arm_not_rolled_back');

    $configAfter = (string) file_get_contents($fixture . '/config.env');
    foreach ([
        'CRON_V3_ENABLED=false',
        'CRON_V3_SHADOW_ENABLED=false',
        'CRON_V4_ENABLED=false',
        'ML_WRITE_ENABLED=false',
    ] as $line) {
        $assert(substr_count($configAfter, $line) === 1, 'safe_config_invalid:' . $line);
    }
    $assert(is_file($fixture . '/PAUSE_MELI_API'), 'api_stop_marker_missing');
    $assert(is_file($fixture . '/PAUSE_ERP_AUTOMATION'), 'automation_stop_marker_missing');
    $assert(
        $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='unrelated.authority'")->fetchColumn()
            === 'preserve-me',
        'unrelated_setting_changed',
    );
    $scheduler = json_decode((string) $pdo->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='queue_core.v4.scheduler_authority'"
    )->fetchColumn(), true);
    $assert(($scheduler['status'] ?? '') === 'absent', 'scheduler_absence_not_preserved');
    $assert((int) $pdo->query('SELECT SUM(enabled) FROM queue_core_feature_flags')->fetchColumn() === 0, 'feature_enabled');
    $assert((int) $pdo->query('SELECT SUM(generation) FROM queue_core_feature_flags')->fetchColumn() === 0, 'feature_generation_changed');
    $engine = $pdo->query(
        "SELECT active_engine,readiness_mode,generation FROM queue_engine_control WHERE control_key='primary'"
    )->fetch(PDO::FETCH_ASSOC);
    $assert($engine === ['active_engine' => 'disabled', 'readiness_mode' => 'idle', 'generation' => 0], 'engine_changed');

    $repeat = $rollback->invoke($service, $pdo, 7, 'partial_arm_recovery_2368_repeat');
    $assert(($repeat['state'] ?? '') === 'rolled_back', 'recovery_not_idempotent');

    $pdo->exec("UPDATE queue_core_feature_flags SET enabled=1 WHERE feature_key='fresh_producer'");
    file_put_contents($fixture . '/config.env', str_replace('CRON_V4_ENABLED=false', 'CRON_V4_ENABLED=true', $configAfter));
    $blocked = false;
    try {
        $rollback->invoke($service, $pdo, 7, 'foreign_feature_test');
    } catch (Throwable $error) {
        $blocked = str_contains($error->getMessage(), 'v4_bootstrap_rollback_incomplete:')
            && str_contains($error->getMessage(), 'feature_authority_unknown');
    }
    $assert($blocked, 'foreign_feature_authority_not_blocked');
    $foreignConfig = (string) file_get_contents($fixture . '/config.env');
    $assert(str_contains($foreignConfig, 'CRON_V4_ENABLED=false'), 'fail_closed_config_not_attempted_before_feature_error');
    $assert(is_file($fixture . '/PAUSE_MELI_API'), 'api_not_stopped_before_feature_error');

    echo 'V4 partial-arm recovery 2.36.8: PASS checks=' . $checks . PHP_EOL;
} finally {
    try {
        $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable) {
    }
    $remove = static function (string $path) use (&$remove): void {
        if (!is_dir($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $remove($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    };
    $remove($fixture);
    $remove($fixture . '-private');
}
