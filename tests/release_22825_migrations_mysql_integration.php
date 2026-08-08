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
$database = 'erp_release_22825_' . bin2hex(random_bytes(5));
$version = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$collation = str_contains($version, 'mariadb') ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_unicode_ci';
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$collation}");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    App\Core\Database::setConnection($pdo);
    $migrator = new App\Services\Migrator($pdo, $root . '/database/migrations');
    $first = $migrator->run();
    App\Services\SchemaInspectorService::clearCache();
    $migrationCountBeforeSecond = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $second = $migrator->run();
    $migrationCountAfterSecond = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();

    $applied = array_map(
        'strval',
        $pdo->query(
            "SELECT version FROM schema_migrations
             WHERE version IN (
                 '203_rhythm_generation_campaign_window_2_28_23.sql',
                 '204_api_health_incident_truth_2_28_24.sql',
                 '205_cron_observed_human_monitor_2_28_25.sql'
             ) ORDER BY version"
        )->fetchAll(PDO::FETCH_COLUMN)
    );
    if (count($applied) !== 3) {
        throw new RuntimeException('No se aplicaron exactamente las migraciones 203–205.');
    }
    if ($migrationCountBeforeSecond !== $migrationCountAfterSecond) {
        throw new RuntimeException('La segunda actualización modificó el catálogo de migraciones.');
    }
    $reapplied = array_filter($second, static fn(array $row): bool => ($row['status'] ?? '') === 'applied');
    if ($reapplied !== []) {
        throw new RuntimeException('La segunda actualización volvió a aplicar una migración.');
    }

    $columns = $pdo->query(
        "SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME)
         FROM information_schema.columns
         WHERE table_schema=DATABASE() AND (
             (TABLE_NAME='api_request_logs' AND COLUMN_NAME IN ('company_id','scope_kind','diagnostic_id')) OR
             (TABLE_NAME='system_work_queue_run_items' AND COLUMN_NAME IN
                 ('batch_configured','batch_effective','batch_executed','batch_limit_reason'))
         )"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (count($columns) !== 7) {
        throw new RuntimeException('Faltan columnas estructurales de 2.28.24/2.28.25.');
    }

    $appVersion = (string) $pdo->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
    )->fetchColumn();
    if ($appVersion !== '2.28.25') {
        throw new RuntimeException('La versión instalada final no quedó en 2.28.25.');
    }

    echo "PASS release_22825_migrations_mysql_integration ({$version}) first=" . count($first) . "\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
