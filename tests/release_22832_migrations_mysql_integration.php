<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN MySQL sin dbname es obligatorio.\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$admin = new PDO($dsn, $user, $pass, $options);
$database = 'erp_release_22832_' . bin2hex(random_bytes(5));
$serverVersion = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$collation = str_contains($serverVersion, 'mariadb') ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_unicode_ci';
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$collation}");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    App\Core\Database::setConnection($pdo);
    $migrator = new App\Services\Migrator($pdo, $root . '/database/migrations');
    $migrator->run();
    App\Services\SchemaInspectorService::clearCache();

    $version = '212_http_resource_backlog_health_truth_2_28_32.sql';
    $applied = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version=' . $pdo->quote($version))->fetchColumn();
    if ($applied !== 1) {
        throw new RuntimeException('La migración 212 no fue aplicada exactamente una vez.');
    }

    $required = ['previous_pending','newly_discovered','deduplicated','finalized','current_pending','http_dispatched','known_responses','resources_received','equation_state'];
    $stmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE()
         AND table_name="system_cron_backlog_snapshots" AND column_name IN (' . implode(',', array_fill(0, count($required), '?')) . ')'
    );
    $stmt->execute($required);
    $actual = $stmt->fetchAll(PDO::FETCH_COLUMN);
    sort($actual); sort($required);
    if ($actual !== $required) {
        throw new RuntimeException('Faltan columnas del contrato de backlog 2.28.32.');
    }
    $responseColumns = ['response_count_state', 'response_resource_unit'];
    $stmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE()
         AND table_name="api_request_logs" AND column_name IN (?,?)'
    );
    $stmt->execute($responseColumns);
    $actualResponseColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    sort($actualResponseColumns); sort($responseColumns);
    if ($actualResponseColumns !== $responseColumns) {
        throw new RuntimeException('Faltan estado y unidad del conteo certificado por respuesta.');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='api_health_correction_events'")->fetchColumn() !== 1) {
        throw new RuntimeException('Falta la tabla de correcciones locales de Salud API.');
    }

    $execute = new ReflectionMethod(App\Services\Migrator::class, 'executeMigrationSql');
    $execute->invoke($migrator, (string) file_get_contents($root . '/database/migrations/' . $version));
    $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $second = $migrator->run();
    $countAfter = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($countBefore !== $countAfter || array_filter($second, static fn(array $row): bool => ($row['status'] ?? '') === 'applied') !== []) {
        throw new RuntimeException('La segunda actualización no fue idempotente.');
    }

    echo "PASS release_22832_migrations_mysql_integration ({$serverVersion})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
