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
$database = 'erp_release_22837_' . bin2hex(random_bytes(5));
$serverVersion = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$collation = str_contains($serverVersion, 'mariadb') ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_unicode_ci';
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$collation}");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    App\Core\Database::setConnection($pdo);
    $migrator = new App\Services\Migrator($pdo, $root . '/database/migrations');
    $migrator->run();
    App\Services\SchemaInspectorService::clearCache();

    $version = '217_progressive_read_models_retention_2_28_37.sql';
    if ((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version=' . $pdo->quote($version))->fetchColumn() !== 1) {
        throw new RuntimeException('La migración 217 no fue aplicada exactamente una vez.');
    }
    foreach (['system_cron_backlog_run_totals', 'system_cron_backlog_snapshots'] as $table) {
        $count = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=' . $pdo->quote($table)
        )->fetchColumn();
        if ($count !== 1) {
            throw new RuntimeException("Falta el read model {$table}.");
        }
    }

    $snapshot = new App\Services\CronBacklogSnapshotService();
    $snapshot->record('QA-22837-A', [
        'orders_sync' => ['known' => true, 'measurement_state' => 'complete', 'total_pending' => 100, 'eligible_count' => 80],
        'manual_campaign' => ['known' => true, 'measurement_state' => 'complete', 'total_pending' => 20, 'eligible_count' => 10],
    ]);
    $snapshot->record('QA-22837-B', [
        'orders_sync' => ['known' => true, 'measurement_state' => 'complete', 'total_pending' => 90, 'eligible_count' => 70],
        'manual_campaign' => ['known' => true, 'measurement_state' => 'complete', 'total_pending' => 18, 'eligible_count' => 9],
    ]);
    $totals = $snapshot->recentTotals(2);
    if (count($totals) !== 2 || (int) $totals[0]['total_pending'] !== 108 || (int) $totals[1]['total_pending'] !== 120) {
        throw new RuntimeException('El snapshot agregado no conservó los totales reales por ciclo.');
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM system_cron_backlog_run_totals')->fetchColumn() !== 2) {
        throw new RuntimeException('Debe existir exactamente un total agregado por run_token.');
    }

    // El selector usado por las vistas debe poder ejecutarse dentro de una
    // transacción READ ONLY: cualquier mutación oculta hará fallar esta prueba.
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    (new App\Services\CronTaskStateService())->preview([
        ['key' => 'orders_sync', 'known' => true, 'work_count' => 1, 'api' => true, 'lane' => 'normal', 'priority' => 100],
    ], 1, 1);
    $pdo->rollBack();

    $before = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $second = $migrator->run();
    $after = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($before !== $after || array_filter($second, static fn (array $row): bool => $row['status'] === 'applied') !== []) {
        throw new RuntimeException('La segunda actualización no fue idempotente.');
    }

    echo "PASS release_22837_read_models_mysql_integration ({$serverVersion})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
