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
$activeVersion = trim((string) file_get_contents($root . '/VERSION'));
require $root . '/vendor/autoload.php';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
$admin = new PDO($dsn, $user, $pass, $options);
$database = 'erp_release_22834_' . bin2hex(random_bytes(5));
$serverVersion = strtolower((string) $admin->query('SELECT VERSION()')->fetchColumn());
$ucaAvailable = (int) $admin->query(
    "SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME='utf8mb4_uca1400_ai_ci'"
)->fetchColumn() === 1;
$collation = str_contains($serverVersion, 'mariadb') && $ucaAvailable
    ? 'utf8mb4_uca1400_ai_ci'
    : 'utf8mb4_unicode_ci';
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$collation}");

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    App\Core\Database::setConnection($pdo);
    $migrator = new App\Services\Migrator($pdo, $root . '/database/migrations');
    $first = $migrator->run();
    $migration = '214_installed_version_authority_2_28_34.sql';
    $count = (int) $pdo->query(
        'SELECT COUNT(*) FROM schema_migrations WHERE version=' . $pdo->quote($migration)
    )->fetchColumn();
    if ($count !== 1) {
        throw new RuntimeException('La migración 214 no quedó aplicada exactamente una vez.');
    }
    $schemaVersion = (string) $pdo->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='app.version'"
    )->fetchColumn();
    if ($schemaVersion !== $activeVersion) {
        throw new RuntimeException('app.version no terminó en la versión activa ' . $activeVersion . '.');
    }

    // Reproduce el defecto de producción: una versión histórica menor tiene
    // una fecha posterior, pero la autoridad canónica debe seguir en la versión activa.
    $pdo->exec("UPDATE app_versions SET installed_at='2020-01-01 00:00:00' WHERE version=" . $pdo->quote($activeVersion));
    $pdo->exec(
        "INSERT INTO app_versions (version,notes,installed_at) VALUES ('2.28.32','historial posterior','2030-01-01 00:00:00')
         ON DUPLICATE KEY UPDATE installed_at=VALUES(installed_at),notes=VALUES(notes)"
    );
    if ((new App\Services\AppVersionService())->installedVersion() !== $activeVersion) {
        throw new RuntimeException('La autoridad volvió a depender del orden histórico de app_versions.');
    }
    (new App\Services\AppVersionService())->registerVersion($activeVersion, 'Reinstalación comprobada.');
    $historyTimestamp = (string) $pdo->query(
        'SELECT installed_at FROM app_versions WHERE version=' . $pdo->quote($activeVersion)
    )->fetchColumn();
    if ($historyTimestamp <= '2020-01-01 00:00:00') {
        throw new RuntimeException('La reinstalación no refrescó installed_at.');
    }

    $previousClientId = getenv('MELI_CLIENT_ID');
    putenv('MELI_CLIENT_ID=doctor-release-test-2291');
    try {
        $doctor = (new App\Services\CronV3DoctorService($pdo))->snapshot('remote');
        if (!(bool) ($doctor['ok'] ?? false)) {
            throw new RuntimeException(
                'El doctor V3 no aprobó el esquema completo: '
                . json_encode($doctor['issues'] ?? [], JSON_UNESCAPED_SLASHES)
            );
        }
    } finally {
        if ($previousClientId === false) {
            putenv('MELI_CLIENT_ID');
        } else {
            putenv('MELI_CLIENT_ID=' . $previousClientId);
        }
    }

    $integrity = (new App\Services\ReleaseIntegrityService())->inspectDirectory($root, true, false);
    if (!(bool) ($integrity['ok'] ?? false)) {
        throw new RuntimeException('La release completa no superó su propia coherencia.');
    }
    $before = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $second = $migrator->run();
    $after = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ($before !== $after || array_filter($second, static fn (array $row): bool => ($row['status'] ?? '') === 'applied') !== []) {
        throw new RuntimeException('La segunda actualización no fue idempotente.');
    }
    if ($first === []) {
        throw new RuntimeException('La restauración nueva no aplicó migraciones.');
    }
    echo "PASS release_22834_migrations_mysql_integration ({$serverVersion})\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
