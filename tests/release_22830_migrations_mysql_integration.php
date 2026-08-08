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
$database = 'erp_release_22830_' . bin2hex(random_bytes(5));
$serverVersion = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$collation = str_contains($serverVersion, 'mariadb') ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_unicode_ci';
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$collation}");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    App\Core\Database::setConnection($pdo);
    $migrator = new App\Services\Migrator($pdo, $root . '/database/migrations');
    $migrator->run();
    App\Services\SchemaInspectorService::clearCache();

    $expected = [
        '209_incremental_cron_planner_campaign_recovery_2_28_29.sql',
        '210_cron_action_center_scoped_health_2_28_30.sql',
    ];
    $quoted = implode(',', array_map(static fn(string $v): string => $pdo->quote($v), $expected));
    $applied = $pdo->query("SELECT version FROM schema_migrations WHERE version IN ({$quoted}) ORDER BY version")
        ->fetchAll(PDO::FETCH_COLUMN);
    if ($applied !== $expected) {
        throw new RuntimeException('No se aplicaron exactamente las migraciones 209–210.');
    }

    $requiredColumns = ['company_id', 'meli_account_id', 'work_source_id', 'reached_remote', 'failure_class'];
    $columnStmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name="system_work_queue_run_items"
           AND column_name IN (' . implode(',', array_fill(0, count($requiredColumns), '?')) . ')'
    );
    $columnStmt->execute($requiredColumns);
    $columns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);
    sort($columns);
    $sortedRequired = $requiredColumns;
    sort($sortedRequired);
    if ($columns !== $sortedRequired) {
        throw new RuntimeException('Faltan columnas de trazabilidad exacta de Cron.');
    }

    $custom = $pdo->prepare('UPDATE app_settings SET setting_value=? WHERE setting_key=?');
    $custom->execute(['custom_planner', 'cron.incremental_planner_enabled']);
    $custom->execute(['custom_protocol', 'api.health.progressive_protocol_version']);

    $execute = new ReflectionMethod(App\Services\Migrator::class, 'executeMigrationSql');
    foreach ($expected as $migration) {
        $execute->invoke($migrator, (string) file_get_contents($root . '/database/migrations/' . $migration));
    }
    $settings = $pdo->query(
        "SELECT setting_key,setting_value FROM app_settings
         WHERE setting_key IN ('cron.incremental_planner_enabled','api.health.progressive_protocol_version')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($settings['cron.incremental_planner_enabled'] ?? '') !== 'custom_planner'
        || ($settings['api.health.progressive_protocol_version'] ?? '') !== 'custom_protocol') {
        throw new RuntimeException('Las migraciones sobrescribieron configuración del operador.');
    }

    $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $second = $migrator->run();
    $countAfter = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($countBefore !== $countAfter
        || array_filter($second, static fn(array $row): bool => ($row['status'] ?? '') === 'applied') !== []) {
        throw new RuntimeException('La segunda actualización no fue idempotente.');
    }

    $appVersion = (string) $pdo->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
    )->fetchColumn();
    if ($appVersion !== '2.28.30') {
        throw new RuntimeException('La versión instalada no terminó en 2.28.30.');
    }

    echo "PASS release_22830_migrations_mysql_integration ({$serverVersion})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
