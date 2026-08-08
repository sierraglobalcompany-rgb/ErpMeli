<?php

declare(strict_types=1);

/**
 * Certifica 101 y el aislamiento de telemetría sobre MySQL/MariaDB real.
 * El DSN no puede seleccionar una base existente.
 */

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '') {
    fwrite(STDOUT, "SKIP: defina ERP_MIGRATOR_TEST_DSN para probar 101.\n");
    exit(0);
}
if (!str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: el DSN debe ser MySQL y no puede seleccionar una base existente.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_workload_2197_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-workload-2197-' . bin2hex(random_bytes(5));
$migrations = $temporary . DIRECTORY_SEPARATOR . 'migrations';
@mkdir($migrations, 0770, true);
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
copy(
    $root . '/database/migrations/101_account_scoped_workload_safety_2_19_7.sql',
    $migrations . '/101_account_scoped_workload_safety_2_19_7.sql'
);

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    define('ERP_SHARED_ROOT', $temporary);
    require $root . '/bootstrap.php';
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    \App\Core\Database::setConnection($pdo);
    $pdo->exec('CREATE TABLE app_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,version VARCHAR(40) NOT NULL UNIQUE,
        notes VARCHAR(255) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(190) NOT NULL UNIQUE,
        setting_value LONGTEXT NULL,setting_group VARCHAR(80) NOT NULL DEFAULT "general",
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metrics_hourly (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL DEFAULT 0,
        meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(24) NOT NULL,
        sample_count INT UNSIGNED NOT NULL DEFAULT 0,remote_count INT UNSIGNED NOT NULL DEFAULT 0,
        success_count INT UNSIGNED NOT NULL DEFAULT 0,error_count INT UNSIGNED NOT NULL DEFAULT 0,
        total_duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,max_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
        total_wire_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,total_decoded_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        total_items BIGINT UNSIGNED NOT NULL DEFAULT 0,total_fanout BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_api_operation_hour_scope(bucket_started_at,account_scope_key,operation_key)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metric_samples (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,
        account_scope_key BIGINT UNSIGNED NOT NULL DEFAULT 0,meli_account_id BIGINT UNSIGNED NULL,
        operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(24) NOT NULL,
        duration_ms INT UNSIGNED NOT NULL DEFAULT 0,wire_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        decoded_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,response_item_count INT UNSIGNED NOT NULL DEFAULT 0,
        fanout_count INT UNSIGNED NOT NULL DEFAULT 0,http_status SMALLINT UNSIGNED NULL,
        reached_remote TINYINT(1) NOT NULL DEFAULT 0,successful TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_processing_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,status VARCHAR(30) NOT NULL,
        grace_until DATETIME NULL,finished_at DATETIME NULL,safe_message VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_processing_scopes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,manual_processing_session_id BIGINT UNSIGNED NOT NULL,
        queue_key VARCHAR(80) NOT NULL,meli_account_id BIGINT UNSIGNED NULL,active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');

    $first = (new \App\Services\Migrator($pdo, $migrations))->run();
    if (($first[0]['status'] ?? '') !== 'applied') {
        throw new RuntimeException('La migración 101 no se aplicó.');
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_operation_metric_samples'
           AND INDEX_NAME='idx_api_metric_scope_remote'"
    )->fetchColumn() !== 5) {
        throw new RuntimeException('El índice de evidencia por cuenta no quedó completo.');
    }

    $hour = gmdate('Y-m-d H:00:00');
    $insertHourly = $pdo->prepare(
        'INSERT INTO api_operation_metrics_hourly
         (bucket_started_at,account_scope_key,meli_account_id,operation_key,load_class,
          sample_count,remote_count,success_count,error_count,total_duration_ms,max_duration_ms,
          total_wire_bytes,total_decoded_bytes,total_items,total_fanout)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $insertHourly->execute([$hour, 1, 1, 'item_description', 'very_heavy', 20, 20, 20, 0, 20000, 1200, 20000, 40000, 20, 0]);
    $insertHourly->execute([$hour, 2, 2, 'item_description', 'very_heavy', 1, 1, 1, 0, 1000, 1000, 1000, 2000, 1, 0]);
    $insertSample = $pdo->prepare(
        'INSERT INTO api_operation_metric_samples
         (bucket_started_at,account_scope_key,meli_account_id,operation_key,load_class,duration_ms,
          wire_bytes,decoded_bytes,response_item_count,http_status,reached_remote,successful)
         VALUES (?,?,?,?,?,?,?,?,?,?,1,1)'
    );
    for ($i = 0; $i < 20; $i++) {
        $insertSample->execute([$hour, 1, 1, 'item_description', 'very_heavy', 1000, 1000, 2000, 1, 200]);
    }
    $insertSample->execute([$hour, 2, 2, 'item_description', 'very_heavy', 1000, 1000, 2000, 1, 200]);

    $policy = new \App\Services\MeliWorkloadPolicyService();
    if ($policy->effectiveBatch('item_description', 3, 1) !== 3) {
        throw new RuntimeException('La cuenta con evidencia saludable no habilitó su lote verificado.');
    }
    if ($policy->effectiveBatch('item_description', 3, 2) !== 1) {
        throw new RuntimeException('La evidencia de otra cuenta aumentó un lote sin muestra propia.');
    }

    $pdo->prepare(
        'UPDATE api_operation_metrics_hourly
         SET sample_count=sample_count+1,error_count=error_count+1
         WHERE account_scope_key=1 AND operation_key="item_description"'
    )->execute();
    $reflection = new ReflectionClass(\App\Services\MeliOperationTelemetryService::class);
    foreach (['summaryCache','percentileCache'] as $propertyName) {
        $property = $reflection->getProperty($propertyName);
        $property->setValue(null, []);
    }
    if ($policy->effectiveBatch('item_description', 3, 1) !== 1) {
        throw new RuntimeException('Un fallo interno reciente no mantuvo el modo conservador.');
    }

    $pdo->exec(
        'INSERT INTO manual_processing_sessions (status,grace_until)
         VALUES ("active",DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))'
    );
    $sessionId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO manual_processing_scopes (manual_processing_session_id,queue_key,meli_account_id,active)
         VALUES (?,"items_sync",1,1)'
    )->execute([$sessionId]);
    $coordination = new \App\Services\ExecutionCoordinationService();
    if (!$coordination->queueReservedForManual('items_sync', null)) {
        throw new RuntimeException('Cron global ignoró una reserva limitada por cuenta.');
    }
    if ($coordination->queueReservedForManual('items_sync', 2)) {
        throw new RuntimeException('La reserva de una cuenta bloqueó una cuenta distinta.');
    }

    if ((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.19.7'")->fetchColumn() !== 1) {
        throw new RuntimeException('No se registró 2.19.7.');
    }
    $second = (new \App\Services\Migrator($pdo, $migrations))->run();
    if (count(array_filter($second, static fn (array $row): bool => ($row['status'] ?? '') !== 'skip')) !== 0) {
        throw new RuntimeException('La segunda ejecución no fue idempotente.');
    }
    fwrite(STDOUT, "OK: migración 101, perfiles por cuenta y reservas certificadas.\n");
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($temporary);
    }
}
