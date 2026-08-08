<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN sin dbname es obligatorio.\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$admin = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'erp_full_' . bin2hex(random_bytes(5));
$serverVersion = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$ucaAvailable = (int) $admin->query(
    "SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME='utf8mb4_uca1400_ai_ci'"
)->fetchColumn() === 1;
$databaseCollation = str_contains($serverVersion, 'mariadb') && $ucaAvailable
    ? 'utf8mb4_uca1400_ai_ci'
    : 'utf8mb4_unicode_ci';
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$databaseCollation}");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $results = (new App\Services\Migrator($pdo, $root . '/database/migrations'))->run();
    $pending = array_filter($results, static fn (array $row): bool => !in_array($row['status'], ['applied', 'adopted', 'skip'], true));
    if ($pending !== []) {
        throw new RuntimeException('Una migración no terminó en estado aceptado.');
    }
    $latest = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations
         WHERE version IN (
           '137_emergency_control_secure_update_2_25_12.sql',
           '138_web_performance_cli_contract_2_25_13.sql'
         )"
    )->fetchColumn();
    if ($latest !== 2) {
        throw new RuntimeException('Las migraciones 137 y 138 no quedaron registradas.');
    }
    $tables = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE()
           AND table_name IN (
             'system_emergency_control_events',
             'system_emergency_canary_runs',
             'system_retired_web_routes'
           )"
    )->fetchColumn();
    if ($tables !== 3) {
        throw new RuntimeException('Las tablas técnicas nuevas no quedaron disponibles.');
    }
    (new App\Services\Migrator($pdo, $root . '/database/migrations'))->run();
    echo 'full_migrations_22513_ok count=' . count($results) . PHP_EOL;
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
