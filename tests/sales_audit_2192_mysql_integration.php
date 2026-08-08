<?php

declare(strict_types=1);

/**
 * Certifica la migración 096 sobre una base temporal real.
 * El DSN no puede seleccionar una base existente.
 */

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '') {
    fwrite(STDOUT, "SKIP: defina ERP_MIGRATOR_TEST_DSN para probar 096.\n");
    exit(0);
}
if (!str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: el DSN debe ser MySQL y no puede seleccionar una base existente.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_audit_2192_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-audit-2192-' . bin2hex(random_bytes(5));
$migrations = $temporary . DIRECTORY_SEPARATOR . 'migrations';
@mkdir($migrations, 0770, true);
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
copy(
    $root . '/database/migrations/096_exact_audit_repair_health_ux_2_19_2.sql',
    $migrations . '/096_exact_audit_repair_health_ux_2_19_2.sql'
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
    $pdo->exec('CREATE TABLE app_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,version VARCHAR(40) NOT NULL UNIQUE,notes VARCHAR(255) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(190) NOT NULL UNIQUE,setting_value LONGTEXT NULL,setting_group VARCHAR(80) NOT NULL DEFAULT "general",is_encrypted TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE sync_sales_repair_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sync_sales_audit_id BIGINT UNSIGNED NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,period_year SMALLINT UNSIGNED NOT NULL,
        period_month TINYINT UNSIGNED NOT NULL,status ENUM("pending","running","complete","error","cancelled") NOT NULL DEFAULT "pending",
        total_items INT UNSIGNED NOT NULL DEFAULT 0,processed_items INT UNSIGNED NOT NULL DEFAULT 0,
        error_message VARCHAR(500) NULL,created_by BIGINT UNSIGNED NULL,started_at DATETIME NULL,
        completed_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE sync_sales_repair_job_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sync_sales_repair_job_id BIGINT UNSIGNED NOT NULL,
        external_order_id VARCHAR(80) NOT NULL,audit_day_id BIGINT UNSIGNED NULL,
        action ENUM("fetch_missing","refresh_existing") NOT NULL DEFAULT "fetch_missing",
        status ENUM("pending","complete","error") NOT NULL DEFAULT "pending",
        error_message VARCHAR(500) NULL,processed_at DATETIME NULL,
        UNIQUE KEY uq_sync_sales_repair_item(sync_sales_repair_job_id,external_order_id)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_request_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,incident_key CHAR(64) NULL
    ) ENGINE=InnoDB');

    $first = (new \App\Services\Migrator($pdo, $migrations))->run();
    if (($first[0]['status'] ?? '') !== 'applied') {
        throw new RuntimeException('La migración 096 no se aplicó.');
    }
    $columns = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sync_sales_repair_jobs'
           AND COLUMN_NAME IN ('sync_sales_audit_run_id','lock_owner','heartbeat_at','verification_audit_job_id')"
    )->fetchColumn();
    if ($columns !== 4) {
        throw new RuntimeException('Faltan columnas de reparación exacta.');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.19.2'")->fetchColumn() !== 1) {
        throw new RuntimeException('No se registró 2.19.2.');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_work_queue_adapter_health'")->fetchColumn() !== 1) {
        throw new RuntimeException('No se creó la salud de adaptadores.');
    }
    $second = (new \App\Services\Migrator($pdo, $migrations))->run();
    if (count(array_filter($second, static fn(array $row): bool => ($row['status'] ?? '') !== 'skip')) !== 0) {
        throw new RuntimeException('La segunda ejecución no fue idempotente.');
    }
    fwrite(STDOUT, "OK: migración 096 e idempotencia certificadas en base temporal.\n");
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
