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
    foreach ([
        '215_cron_capacity_producer_contract_2_28_35.sql',
        '216_historical_intervention_scoped_ack_2_28_36.sql',
        '217_progressive_read_models_retention_2_28_37.sql',
    ] as $migration) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
        $stmt->execute([$migration]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException("La migración {$migration} no quedó aplicada exactamente una vez.");
        }
    }
    foreach ([
        'system_cron_capacity_plans',
        'system_cron_producer_metrics',
        'system_work_historical_reconciliations',
        'system_cron_backlog_run_totals',
    ] as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $stmt->execute([$table]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException("Falta la tabla técnica {$table}.");
        }
    }
    $version = (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn();
    if ($version !== '2.28.37') {
        throw new RuntimeException("app.version terminó en {$version}.");
    }
    $before = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $second = $migrator->run();
    $after = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($before !== $after || array_filter($second, static fn (array $row): bool => ($row['status'] ?? '') === 'applied') !== []) {
        throw new RuntimeException('La segunda actualización no fue idempotente.');
    }
    echo "PASS release_22837_full_migrations ({$serverVersion})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
