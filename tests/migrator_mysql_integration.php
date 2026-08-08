<?php

declare(strict_types=1);

/**
 * Prueba de integración opcional del migrador contra MySQL/MariaDB real.
 *
 * Requiere variables de entorno:
 *   ERP_MIGRATOR_TEST_DSN=mysql:host=127.0.0.1;port=3306;charset=utf8mb4
 *   ERP_MIGRATOR_TEST_USER=root
 *   ERP_MIGRATOR_TEST_PASS=
 *
 * Crea y elimina una base temporal. Nunca utiliza la base configurada del ERP.
 */

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');

if ($dsn === '') {
    fwrite(STDOUT, "SKIP: defina ERP_MIGRATOR_TEST_DSN para ejecutar la prueba MySQL.\n");
    exit(0);
}
if (!str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: el DSN debe ser MySQL y no puede seleccionar una base existente.\n");
    exit(2);
}

$sourceRoot = dirname(__DIR__);
$temporaryRoot = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'erp-migrator-' . bin2hex(random_bytes(6));
$migrationDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'migrations';
$sharedRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'shared';
$databaseName = 'erp_migrator_' . bin2hex(random_bytes(6));

if (!mkdir($migrationDirectory, 0770, true) && !is_dir($migrationDirectory)) {
    throw new RuntimeException('No se pudo crear el directorio temporal de migraciones.');
}
if (!mkdir($sharedRoot . DIRECTORY_SEPARATOR . 'storage', 0770, true) && !is_dir($sharedRoot . DIRECTORY_SEPARATOR . 'storage')) {
    throw new RuntimeException('No se pudo crear storage temporal.');
}

define('ERP_SHARED_ROOT', $sharedRoot);
require $sourceRoot . '/bootstrap.php';

use App\Core\Database;
use App\Services\Migrator;

/** @param mixed $condition */
function assertIntegration($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Elimina únicamente el árbol temporal creado por esta prueba. */
function removeIntegrationTree(string $path, string $expectedRoot): void
{
    $resolved = realpath($path);
    $root = realpath($expectedRoot);
    if ($resolved === false || $root === false || $resolved !== $root || !str_contains($resolved, 'erp-migrator-')) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($resolved);
}

$server = null;
try {
    $server = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $server->exec(
        'CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
    );
    $databaseDsn = rtrim($dsn, ';') . ';dbname=' . $databaseName;
    $pdo = new PDO($databaseDsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    Database::setConnection($pdo);

    // Simula una instalación histórica con metadata unicode y base general.
    $pdo->exec(
        'CREATE TABLE app_versions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(40) NOT NULL,
            installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notes VARCHAR(500) NULL,
            UNIQUE KEY uq_app_versions_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        "INSERT INTO app_versions (version,notes) VALUES ('2.8.23','fixture sin datos comerciales')"
    );
    $pdo->exec(
        'CREATE TABLE app_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(160) NOT NULL,
            setting_value MEDIUMTEXT NULL,
            is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
            setting_group VARCHAR(80) NOT NULL DEFAULT \'general\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_app_settings_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    file_put_contents(
        $migrationDirectory . '/900_native_prepare_probe.sql',
        "CREATE TABLE IF NOT EXISTS migrator_native_prepare_probe (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            marker VARCHAR(80) NOT NULL,
            UNIQUE KEY uq_migrator_probe_marker (marker)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        INSERT IGNORE INTO migrator_native_prepare_probe (marker) VALUES ('applied');"
    );

    $first = (new Migrator($pdo, $migrationDirectory))->run();
    assertIntegration(($first[0]['status'] ?? null) === 'applied', 'La migración de prueba no quedó aplicada.');
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM migrator_native_prepare_probe WHERE marker='applied'")->fetchColumn() === 1,
        'La postcondición SQL no se cumplió.'
    );
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM system_update_migrations WHERE state='applied'")->fetchColumn() === 1,
        'El metadata del migrador no quedó aplicado.'
    );
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM system_update_migration_events WHERE stage='state_write_completed'")->fetchColumn() >= 1,
        'No se registró la traza previa al SQL.'
    );

    // Ejecuta el camino real que falló en producción: 2.8.23 -> 059..063.
    foreach (glob($sourceRoot . '/database/migrations/{059,060,061,062,063}_*.sql', GLOB_BRACE) ?: [] as $sourceMigration) {
        copy($sourceMigration, $migrationDirectory . '/' . basename($sourceMigration));
    }
    $upgrade = (new Migrator($pdo, $migrationDirectory))->run();
    $upgradeApplied = array_values(array_filter(
        $upgrade,
        static fn(array $row): bool => $row['status'] === 'applied'
    ));
    assertIntegration(count($upgradeApplied) === 5, 'No se aplicaron exactamente las migraciones 059–063.');
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.9.4'")->fetchColumn() === 1,
        'La versión 2.9.4 no quedó registrada.'
    );

    $second = (new Migrator($pdo, $migrationDirectory))->run();
    assertIntegration(
        count(array_filter($second, static fn(array $row): bool => $row['status'] !== 'skip')) === 0,
        'La segunda ejecución de 059–063 no fue idempotente.'
    );

    // Valida el camino real del cron verificable y el tooltip 071–079 sobre un esquema histórico.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS cron_health_checks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_name VARCHAR(100) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT "running",
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            duration_ms INT UNSIGNED NULL,
            processed_chunks INT UNSIGNED NOT NULL DEFAULT 0,
            completed_chunks INT UNSIGNED NOT NULL DEFAULT 0,
            partial_chunks INT UNSIGNED NOT NULL DEFAULT 0,
            error_chunks INT UNSIGNED NOT NULL DEFAULT 0,
            orders_count INT UNSIGNED NOT NULL DEFAULT 0,
            message VARCHAR(500) NULL,
            payload_json LONGTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS meli_notification_work_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            meli_account_id BIGINT UNSIGNED NULL,
            resource_type VARCHAR(40) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT "pending",
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error_code VARCHAR(80) NULL,
            last_error_message VARCHAR(500) NULL,
            last_processed_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS meli_oauth_states (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            state_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            UNIQUE KEY uq_oauth_state_hash (state_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    foreach (glob($sourceRoot . '/database/migrations/{071,072,073,074,075,076,077,078,079}_*.sql', GLOB_BRACE) ?: [] as $sourceMigration) {
        copy($sourceMigration, $migrationDirectory . '/' . basename($sourceMigration));
    }
    $cronUpgrade = (new Migrator($pdo, $migrationDirectory))->run();
    $cronApplied = array_values(array_filter(
        $cronUpgrade,
        static fn(array $row): bool => $row['status'] === 'applied'
    ));
    assertIntegration(count($cronApplied) === 9, 'No se aplicaron exactamente las migraciones 071–079.');
    assertIntegration(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_health_checks'
               AND COLUMN_NAME IN ('release_version','release_build_id','component_checksum')"
        )->fetchColumn() === 3,
        'Las columnas de identidad de release no quedaron creadas.'
    );
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.11.6'")->fetchColumn() === 1,
        'La versión 2.11.6 no quedó registrada.'
    );
    assertIntegration(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_notification_work_items'
               AND COLUMN_NAME IN ('last_error_diagnostic_id','last_error_stage')"
        )->fetchColumn() === 2,
        'Las columnas de diagnóstico del worker no quedaron creadas.'
    );
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.11.7'")->fetchColumn() === 1,
        'La versión 2.11.7 no quedó registrada.'
    );
    assertIntegration(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_notification_work_items'
               AND COLUMN_NAME IN ('consecutive_failures','last_success_at','processing_event_id')"
        )->fetchColumn() === 3,
        'Las columnas de estabilidad 2.11.8 no quedaron creadas.'
    );
    assertIntegration(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_oauth_states'
               AND COLUMN_NAME IN ('processing_token_hash','processing_at','last_error_message')"
        )->fetchColumn() === 3,
        'Las columnas de reclamación OAuth 2.11.8 no quedaron creadas.'
    );
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.11.8'")->fetchColumn() === 1,
        'La versión 2.11.8 no quedó registrada.'
    );
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.11.9'")->fetchColumn() === 1,
        'La versión 2.11.9 no quedó registrada.'
    );
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.11.10'")->fetchColumn() === 1,
        'La versión 2.11.10 no quedó registrada.'
    );
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.11.11'")->fetchColumn() === 1,
        'La versión 2.11.11 no quedó registrada.'
    );
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.11.12'")->fetchColumn() === 1,
        'La versión 2.11.12 no quedó registrada.'
    );
    assertIntegration(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_notification_recovery_runs'")->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cron_task_state' AND COLUMN_NAME IN ('last_work_count','oldest_due_at','last_selection_reason')")->fetchColumn() === 3,
        'La recuperación canaria y metadata del scheduler 2.11.9 no quedaron creadas.'
    );
    $cronSecond = (new Migrator($pdo, $migrationDirectory))->run();
    assertIntegration(
        count(array_filter($cronSecond, static fn(array $row): bool => $row['status'] !== 'skip')) === 0,
        'La segunda ejecución de 071–079 no fue idempotente.'
    );

    // Confirma que el logging secundario no altera la etapa del error SQL original.
    file_put_contents(
        $migrationDirectory . '/901_expected_failure.sql',
        'CREATE TABLE intentionally_broken_sql ('
    );
    try {
        (new Migrator($pdo, $migrationDirectory))->run();
        throw new RuntimeException('La migración inválida no generó el error esperado.');
    } catch (\App\Services\MigrationExecutionException $expected) {
        assertIntegration($expected->stage() === 'sql_execution', 'La etapa original del error SQL fue reemplazada.');
        assertIntegration(!$expected->safeToRetry(), 'Un SQL iniciado no debe marcarse como reintento automático seguro.');
        assertIntegration(
            (int) $pdo->query("SELECT COUNT(*) FROM system_update_migrations WHERE migration_key='901_expected_failure.sql' AND state='failed'")->fetchColumn() === 1,
            'La migración fallida no quedó trazada como failed.'
        );
    }

    fwrite(STDOUT, "OK: prepares nativos, 059–063, 071–079, collations mixtas, error original, traza e idempotencia verificados.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e::class . ': ' . $e->getMessage() . PHP_EOL);
    if ($e->getPrevious() instanceof Throwable) {
        fwrite(STDERR, 'CAUSE: ' . $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage() . PHP_EOL);
    }
    $exitCode = 1;
} finally {
    if ($server instanceof PDO && preg_match('/^erp_migrator_[a-f0-9]{12}$/', $databaseName) === 1) {
        try {
            $server->exec('DROP DATABASE IF EXISTS `' . $databaseName . '`');
        } catch (Throwable) {
            // La limpieza no sustituye el resultado original de la prueba.
        }
    }
    removeIntegrationTree($temporaryRoot, $temporaryRoot);
}

exit($exitCode ?? 0);
