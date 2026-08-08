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
$database = 'erp_release_22828_' . bin2hex(random_bytes(5));
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
        '206_cron_directed_debt_scope_truth_2_28_26.sql',
        '207_api_health_scope_protocol_2_28_27.sql',
        '208_human_frontend_accessibility_2_28_28.sql',
    ];
    $quoted = implode(',', array_map(static fn(string $v): string => $pdo->quote($v), $expected));
    $applied = $pdo->query("SELECT version FROM schema_migrations WHERE version IN ({$quoted}) ORDER BY version")
        ->fetchAll(PDO::FETCH_COLUMN);
    if ($applied !== $expected) {
        throw new RuntimeException('No se aplicaron exactamente las migraciones 206–208.');
    }

    $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $second = $migrator->run();
    $countAfter = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($countBefore !== $countAfter || array_filter($second, static fn(array $row): bool => ($row['status'] ?? '') === 'applied') !== []) {
        throw new RuntimeException('La segunda actualización no fue idempotente.');
    }

    $custom = $pdo->prepare('UPDATE app_settings SET setting_value=? WHERE setting_key=?');
    $custom->execute(['custom_scope', 'cron.operational_scope']);
    $custom->execute(['777', 'api.health.remote_evidence_recent_seconds']);

    $execute = new ReflectionMethod(App\Services\Migrator::class, 'executeMigrationSql');
    foreach (array_slice($expected, 0, 2) as $migration) {
        $execute->invoke($migrator, (string) file_get_contents($root . '/database/migrations/' . $migration));
    }
    $settings = $pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('cron.operational_scope','api.health.remote_evidence_recent_seconds')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($settings['cron.operational_scope'] ?? '') !== 'custom_scope' || ($settings['api.health.remote_evidence_recent_seconds'] ?? '') !== '777') {
        throw new RuntimeException('Una migración sobrescribió configuración del operador.');
    }

    $appVersion = (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1")->fetchColumn();
    if ($appVersion !== '2.28.27') {
        throw new RuntimeException('La reevaluación controlada de 206–207 no dejó el orden esperado.');
    }

    echo "PASS release_22828_migrations_mysql_integration ({$serverVersion})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
