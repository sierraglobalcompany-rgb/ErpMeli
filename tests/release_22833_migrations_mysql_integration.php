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
$database = 'erp_release_22833_' . bin2hex(random_bytes(5));
$serverVersion = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$collation = str_contains($serverVersion, 'mariadb') ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_unicode_ci';
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$collation}");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    App\Core\Database::setConnection($pdo);
    $migrator = new App\Services\Migrator($pdo, $root . '/database/migrations');
    $first = $migrator->run();
    foreach ([
        '211_configurable_adaptive_http_scheduler_2_28_31.sql',
        '212_http_resource_backlog_health_truth_2_28_32.sql',
        '213_human_rhythm_capacity_certification_2_28_33.sql',
    ] as $version) {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE version=' . $pdo->quote($version))->fetchColumn();
        if ($count !== 1) {
            throw new RuntimeException("La migración {$version} no quedó aplicada exactamente una vez.");
        }
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='api_rhythm_penalties'")->fetchColumn() !== 1) {
        throw new RuntimeException('Falta api_rhythm_penalties.');
    }
    $responseColumns = ['response_count_state', 'response_resource_unit'];
    $stmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE()
         AND table_name="api_request_logs" AND column_name IN (?,?)'
    );
    $stmt->execute($responseColumns);
    $actualResponseColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    sort($actualResponseColumns);
    sort($responseColumns);
    if ($actualResponseColumns !== $responseColumns) {
        throw new RuntimeException('La instalación acumulativa no dejó el conteo certificado de respuestas.');
    }
    $appVersion = (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn();
    if ($appVersion !== '2.28.33') {
        throw new RuntimeException('app.version no terminó en 2.28.33.');
    }
    $before = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $second = $migrator->run();
    $after = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($before !== $after || array_filter($second, static fn(array $row): bool => ($row['status'] ?? '') === 'applied') !== []) {
        throw new RuntimeException('La segunda actualización no fue idempotente.');
    }
    if ($first === []) {
        throw new RuntimeException('La restauración nueva no aplicó migraciones.');
    }
    echo "PASS release_22833_migrations_mysql_integration ({$serverVersion})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
