<?php

declare(strict_types=1);

$dsn = (string) (getenv('ERP_23611_MYSQL_DSN') ?: '');
$user = (string) (getenv('ERP_23611_MYSQL_USER') ?: '');
$password = (string) (getenv('ERP_23611_MYSQL_PASS') ?: '');
if ($dsn === '') {
    fwrite(STDOUT, "V4 generation rollback 2.36.11: SKIP (ERP_23611_MYSQL_DSN absent)\n");
    exit(0);
}

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/erp-meli-23611-v4-' . bin2hex(random_bytes(6));
$database = 'erp_23611_v4_' . bin2hex(random_bytes(5));
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
    $configPath = $fixture . '/config.env';
    file_put_contents($configPath, implode("\n", [
        'APP_ENV=local',
        'APP_KEY=local-23611-generation-key',
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
    ]) . "\n");
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
         VALUES ('primary','disabled','idle',NULL,2,UTC_TIMESTAMP(3),'post_rollback_fixture')"
    );
    foreach (\App\QueueCore\QueueCoreFeatureFlagService::READINESS_FEATURES as $feature) {
        $enabled = in_array($feature, ['fresh_producer', 'webhook_producer', 'pack_shipment_followups'], true) ? 1 : 0;
        $generation = $enabled === 1 ? 3 : 0;
        $statement = $pdo->prepare(
            'INSERT INTO queue_core_feature_flags(feature_key,enabled,hard_cap,generation) VALUES (?,?,NULL,?)'
        );
        $statement->execute([$feature, $enabled, $generation]);
    }
    $pdo->exec(
        "INSERT INTO app_settings VALUES
         ('app.version','2.36.11',0,'system'),
         ('unrelated.authority','preserve-me',0,'test')"
    );

    $service = new \App\Services\V4ReadinessBootstrapService(
        static fn (): PDO => $pdo,
        $configPath,
    );
    $rollback = new ReflectionMethod($service, 'rollbackAuthorities');
    $result = $rollback->invoke($service, $pdo, 7, 'generation_2_armed_rollback');
    $assert(($result['state'] ?? '') === 'rolled_back', 'armed_generation_rollback_failed');

    $engine = $pdo->query(
        "SELECT active_engine,readiness_mode,generation FROM queue_engine_control WHERE control_key='primary'"
    )->fetch(PDO::FETCH_ASSOC);
    $assert($engine === ['active_engine' => 'disabled', 'readiness_mode' => 'idle', 'generation' => 2], 'engine_generation_reset');
    $rows = $pdo->query(
        'SELECT feature_key,enabled,generation FROM queue_core_feature_flags ORDER BY feature_key'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $expectedGeneration = in_array(
            $row['feature_key'],
            ['fresh_producer', 'webhook_producer', 'pack_shipment_followups'],
            true,
        ) ? 2 : 0;
        $assert((int) $row['enabled'] === 0, 'feature_not_disabled:' . $row['feature_key']);
        $assert((int) $row['generation'] === $expectedGeneration, 'feature_generation_not_normalized:' . $row['feature_key']);
    }
    $assert(str_contains((string) file_get_contents($configPath), 'CRON_V4_ENABLED=false'), 'config_not_fail_closed');
    $assert(is_file($fixture . '/PAUSE_MELI_API'), 'api_not_stopped');
    $assert(
        $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='unrelated.authority'")->fetchColumn()
            === 'preserve-me',
        'unrelated_setting_changed',
    );

    $repeat = $rollback->invoke($service, $pdo, 7, 'generation_2_repeat');
    $assert(($repeat['state'] ?? '') === 'rolled_back', 'generation_rollback_not_idempotent');

    $pdo->exec("UPDATE queue_core_feature_flags SET generation=1 WHERE feature_key='fresh_producer'");
    $blocked = false;
    try {
        $rollback->invoke($service, $pdo, 7, 'mixed_generation_test');
    } catch (Throwable $error) {
        $blocked = str_contains($error->getMessage(), 'v4_bootstrap_rollback_incomplete:')
            && str_contains($error->getMessage(), 'feature_generation_authority');
    }
    $assert($blocked, 'mixed_generation_not_blocked');
    $assert(str_contains((string) file_get_contents($configPath), 'CRON_V4_ENABLED=false'), 'mixed_generation_config_not_safe');
    $assert(is_file($fixture . '/PAUSE_MELI_API'), 'mixed_generation_api_not_safe');

    echo 'V4 generation rollback 2.36.11: PASS checks=' . $checks . PHP_EOL;
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
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    };
    $remove($fixture);
    $remove($fixture . '-private');
}
