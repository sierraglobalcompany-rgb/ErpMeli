<?php

declare(strict_types=1);

/**
 * Certifica 099 sobre una base temporal MySQL/MariaDB real.
 * El DSN no puede seleccionar una base existente.
 */

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '') {
    fwrite(STDOUT, "SKIP: defina ERP_MIGRATOR_TEST_DSN para probar 099.\n");
    exit(0);
}
if (!str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: el DSN debe ser MySQL y no puede seleccionar una base existente.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_manual_2195_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-manual-2195-' . bin2hex(random_bytes(5));
$migrations = $temporary . DIRECTORY_SEPARATOR . 'migrations';
@mkdir($migrations, 0770, true);
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
copy(
    $root . '/database/migrations/099_manual_processing_integrity_2_19_5.sql',
    $migrations . '/099_manual_processing_integrity_2_19_5.sql'
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
    $pdo->exec('CREATE TABLE manual_processing_sessions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,session_token CHAR(40) NOT NULL,
        created_by_user_id BIGINT UNSIGNED NOT NULL,
        mode ENUM("conservative","balanced","automatic","advanced") NOT NULL DEFAULT "automatic",
        scope_key VARCHAR(50) NOT NULL,
        status ENUM("draft","active","paused","finishing","completed","abandoned","failed") NOT NULL DEFAULT "draft",
        heartbeat_at DATETIME NULL,grace_until DATETIME NULL,started_at DATETIME NULL,paused_at DATETIME NULL,
        finished_at DATETIME NULL,total_jobs INT UNSIGNED NOT NULL DEFAULT 0,
        completed_jobs INT UNSIGNED NOT NULL DEFAULT 0,failed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
        remote_calls INT UNSIGNED NOT NULL DEFAULT 0,transferred_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        safe_message VARCHAR(500) NULL,diagnostic_id VARCHAR(80) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_manual_session_token(session_token)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_processing_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,manual_processing_session_id BIGINT UNSIGNED NOT NULL,
        queue_key VARCHAR(80) NOT NULL,source_id VARCHAR(100) NOT NULL,meli_account_id BIGINT UNSIGNED NULL,
        human_label VARCHAR(160) NOT NULL,content_summary VARCHAR(500) NULL,operation_key VARCHAR(80) NULL,
        load_class VARCHAR(24) NULL,
        status ENUM("pending","running","waiting","completed","partial","retry","failed","returned") NOT NULL DEFAULT "pending",
        lease_owner VARCHAR(100) NULL,lease_generation INT UNSIGNED NOT NULL DEFAULT 0,
        lease_expires_at DATETIME NULL,progress_current INT UNSIGNED NOT NULL DEFAULT 0,
        progress_total INT UNSIGNED NOT NULL DEFAULT 0,result_summary VARCHAR(500) NULL,
        diagnostic_id VARCHAR(80) NULL,started_at DATETIME NULL,completed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metrics_hourly (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,
        meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(24) NOT NULL,
        sample_count INT UNSIGNED NOT NULL DEFAULT 0,remote_count INT UNSIGNED NOT NULL DEFAULT 0,
        success_count INT UNSIGNED NOT NULL DEFAULT 0,error_count INT UNSIGNED NOT NULL DEFAULT 0,
        total_duration_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,max_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
        total_wire_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,total_decoded_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        total_items BIGINT UNSIGNED NOT NULL DEFAULT 0,total_fanout BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_api_operation_hour(bucket_started_at,meli_account_id,operation_key)
    ) ENGINE=InnoDB');
    $pdo->exec(
        'INSERT INTO api_operation_metrics_hourly
         (bucket_started_at,meli_account_id,operation_key,load_class,sample_count,total_duration_ms)
         VALUES ("2026-07-27 10:00:00",NULL,"item_description","very_heavy",1,100),
                ("2026-07-27 10:00:00",NULL,"item_description","very_heavy",2,300)'
    );

    $first = (new \App\Services\Migrator($pdo, $migrations))->run();
    if (($first[0]['status'] ?? '') !== 'applied') {
        throw new RuntimeException('La migración 099 no se aplicó.');
    }
    $sessionColumns = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manual_processing_sessions'
           AND COLUMN_NAME IN ('worker_heartbeat_at','last_worker_result','last_worker_message')"
    )->fetchColumn();
    if ($sessionColumns !== 3) {
        throw new RuntimeException('Faltan columnas de salud del worker manual.');
    }
    $statusType = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='manual_processing_sessions' AND COLUMN_NAME='status'"
    )->fetchColumn();
    if (!str_contains($statusType, 'completed_with_issues')) {
        throw new RuntimeException('El estado final con asuntos no fue registrado.');
    }
    $aggregated = $pdo->query(
        "SELECT sample_count,total_duration_ms FROM api_operation_metrics_hourly
         WHERE operation_key='item_description'"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (count($aggregated) !== 1 || (int) $aggregated[0]['sample_count'] !== 3
        || (int) $aggregated[0]['total_duration_ms'] !== 400) {
        throw new RuntimeException('La telemetría horaria nula no se consolidó correctamente.');
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='api_operation_metrics_hourly'
           AND INDEX_NAME='uq_api_operation_hour_scope'"
    )->fetchColumn() !== 3) {
        throw new RuntimeException('La clave horaria determinista no quedó completa.');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.19.5'")->fetchColumn() !== 1) {
        throw new RuntimeException('No se registró 2.19.5.');
    }
    $second = (new \App\Services\Migrator($pdo, $migrations))->run();
    if (count(array_filter($second, static fn (array $row): bool => ($row['status'] ?? '') !== 'skip')) !== 0) {
        throw new RuntimeException('La segunda ejecución no fue idempotente.');
    }
    fwrite(STDOUT, "OK: migración 099 e idempotencia certificadas en base temporal.\n");
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
