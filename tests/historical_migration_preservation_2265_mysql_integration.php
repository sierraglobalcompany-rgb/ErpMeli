<?php

declare(strict_types=1);

use App\Services\HistoricalMigrationDataPreserver;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
if ($dsn === '' || str_contains(strtolower($dsn), 'dbname=')) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN sin base seleccionada es obligatorio.\n");
    exit(2);
}

$pdo = new PDO(
    $dsn,
    (string) getenv('ERP_MIGRATOR_TEST_USER'),
    (string) getenv('ERP_MIGRATOR_TEST_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$database = 'erp_meli_history_preserver_' . bin2hex(random_bytes(5));
$quotedDatabase = '`' . str_replace('`', '``', $database) . '`';

try {
    $pdo->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE {$quotedDatabase}");
    $pdo->exec(
        "CREATE TABLE system_retention_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataset_key VARCHAR(80) NOT NULL,
            status VARCHAR(20) NOT NULL,
            rows_reviewed BIGINT UNSIGNED NOT NULL DEFAULT 0,
            safe_message VARCHAR(500) NULL
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "INSERT INTO system_retention_runs
         (dataset_key,status,rows_reviewed,safe_message)
         VALUES ('legacy','completed',123,'historical-row-must-survive')"
    );

    $preserver = new HistoricalMigrationDataPreserver($pdo);
    $preserver->before('146_runtime_consolidation_physical_recovery_2_26_0.sql');
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_retention_runs_preserved_2265'"
    )->fetchColumn() !== 1) {
        throw new RuntimeException('La evidencia histórica no se separó antes de la migración 146.');
    }

    $pdo->exec(
        "CREATE TABLE system_retention_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataset_key VARCHAR(80) NOT NULL,
            status VARCHAR(20) NOT NULL,
            rows_reviewed BIGINT UNSIGNED NOT NULL DEFAULT 0,
            rows_archived BIGINT UNSIGNED NOT NULL DEFAULT 0,
            safe_message VARCHAR(500) NULL
        ) ENGINE=InnoDB"
    );
    $preserver->after('153_runtime_safety_backup_recovery_2_26_5.sql');

    $row = $pdo->query(
        "SELECT rows_reviewed,safe_message FROM system_retention_runs WHERE id=1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)
        || (int) $row['rows_reviewed'] !== 123
        || (string) $row['safe_message'] !== 'historical-row-must-survive'
    ) {
        throw new RuntimeException('La evidencia histórica no sobrevivió a la migración 153.');
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_retention_runs_preserved_2265'"
    )->fetchColumn() !== 0) {
        throw new RuntimeException('La tabla temporal no se retiró después de verificar la copia.');
    }

    echo "OK: migraciones históricas inmutables y retención preservada.\n";
} finally {
    try {
        $pdo->exec('USE information_schema');
        $pdo->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
    } catch (Throwable) {
    }
}
