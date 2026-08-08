<?php

declare(strict_types=1);

/**
 * Integración aislada de la recuperación certificada 087–089.
 *
 * Crea y elimina una base temporal. El DSN no puede seleccionar una base.
 */

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '') {
    if (getenv('ERP_RELEASE_STRICT') === '1') {
        fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN es obligatorio para certificar la release.\n");
        exit(3);
    }
    fwrite(STDOUT, "SKIP: defina ERP_MIGRATOR_TEST_DSN para probar 087–089.\n");
    exit(0);
}
if (!str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: el DSN debe ser MySQL y no puede seleccionar una base existente.\n");
    exit(2);
}

$root = dirname(__DIR__);
$databaseName = 'erp_modules_' . bin2hex(random_bytes(6));
$temporaryRoot = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'erp-modules-' . bin2hex(random_bytes(6));
$migrationDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
$resourceDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'resources';
@mkdir($migrationDirectory, 0770, true);
@mkdir($resourceDirectory, 0770, true);
copy($root . '/database/migrations/087_module_runtime_jobs_hardening_2_17_0.sql', $migrationDirectory . '/087_module_runtime_jobs_hardening_2_17_0.sql');
copy($root . '/database/migrations/088_module_runtime_logs_recovery_2_17_1.sql', $migrationDirectory . '/088_module_runtime_logs_recovery_2_17_1.sql');
copy($root . '/database/migrations/089_migration_drift_recovery_2_17_2.sql', $migrationDirectory . '/089_migration_drift_recovery_2_17_2.sql');
copy($root . '/resources/migration-replacements.json', $resourceDirectory . '/migration-replacements.json');

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec("CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$failure = null;

try {
    define('ERP_SHARED_ROOT', $temporaryRoot . DIRECTORY_SEPARATOR . 'shared');
    @mkdir(ERP_SHARED_ROOT . DIRECTORY_SEPARATOR . 'storage', 0770, true);
    require $root . '/bootstrap.php';

    $pdo = new PDO($dsn . ';dbname=' . $databaseName, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    \App\Core\Database::setConnection($pdo);

    $pdo->exec("CREATE TABLE app_versions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(40) NOT NULL UNIQUE,
        notes VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE app_settings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(190) NOT NULL UNIQUE,
        setting_value LONGTEXT NULL,
        setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE system_module_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        module_id VARCHAR(80) NOT NULL,
        job_type VARCHAR(80) NOT NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        status ENUM('pending','running','retry','paused','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
        priority INT NOT NULL DEFAULT 100,
        payload_json JSON NULL,
        result_json JSON NULL,
        progress_current INT UNSIGNED NOT NULL DEFAULT 0,
        progress_total INT UNSIGNED NOT NULL DEFAULT 0,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        next_run_at DATETIME NOT NULL,
        lock_owner VARCHAR(80) NULL,
        lock_expires_at DATETIME NULL,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        safe_error_message VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE system_module_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        module_id VARCHAR(80) NOT NULL,
        source_event_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        topic VARCHAR(100) NOT NULL,
        resource_type VARCHAR(80) NULL,
        remote_resource_id VARCHAR(120) NULL,
        status ENUM('pending','processed','ignored','error') NOT NULL DEFAULT 'pending',
        processed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_module_source_event (module_id,source_event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE api_error_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id VARCHAR(100) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE system_update_migrations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration_key VARCHAR(180) NOT NULL UNIQUE,
        checksum_sha256 CHAR(64) NOT NULL,
        release_version VARCHAR(40) NULL,
        state VARCHAR(40) NOT NULL DEFAULT 'pending',
        checkpoint_json LONGTEXT NULL,
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        safe_error_message VARCHAR(700) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE system_update_migration_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        diagnostic_id VARCHAR(64) NOT NULL,
        migration_key VARCHAR(180) NULL,
        stage VARCHAR(80) NOT NULL,
        status VARCHAR(30) NOT NULL,
        duration_ms INT UNSIGNED NULL,
        checksum_sha256 CHAR(64) NULL,
        file_version VARCHAR(40) NULL,
        installed_version VARCHAR(40) NULL,
        php_version VARCHAR(40) NULL,
        php_sapi VARCHAR(40) NULL,
        pdo_driver VARCHAR(40) NULL,
        sql_state VARCHAR(20) NULL,
        driver_code VARCHAR(30) NULL,
        exception_class VARCHAR(190) NULL,
        safe_message VARCHAR(700) NULL,
        context_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $migrator = new \App\Services\Migrator($pdo, $migrationDirectory);
    $oldChecksum = 'f2b58e5df23ff3c9c14534a449bb60b1853cada388eb89b55b65433445bd5046';
    $newChecksum = '3091de2752312006f59567effe7100d9993e2dde5f7260c967782c4afc958397';
    $migrationKey = '087_module_runtime_jobs_hardening_2_17_0.sql';
    // Fingerprint exacto dejado por el primer intento parcial de 2.17.0.
    $pdo->exec(
        "ALTER TABLE system_module_jobs
         ADD COLUMN dedupe_key CHAR(64) NULL AFTER meli_account_id,
         ADD COLUMN stage VARCHAR(80) NULL AFTER status,
         ADD COLUMN checkpoint_json JSON NULL AFTER payload_json,
         ADD COLUMN consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER attempts,
         ADD COLUMN last_success_at DATETIME NULL AFTER finished_at"
    );
    $serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $originalFailure = null;
    try {
        $pdo->exec(
            "ALTER TABLE system_module_jobs
             ADD COLUMN active_dedupe_key CHAR(64)
             GENERATED ALWAYS AS (
               CASE
                 WHEN status IN ('pending','running','retry','paused') THEN dedupe_key
                 ELSE NULL
               END
             ) STORED AFTER dedupe_key"
        );
    } catch (PDOException $error) {
        $originalFailure = $error;
    }
    if (stripos($serverVersion, 'MariaDB') !== false) {
        if (!$originalFailure instanceof PDOException
            || (string) ($originalFailure->errorInfo[0] ?? '') !== 'HY000'
            || (string) ($originalFailure->errorInfo[1] ?? '') !== '1901'
            || !str_contains($originalFailure->getMessage(), '`active_dedupe_key`')) {
            throw new RuntimeException(
                'MariaDB no reprodujo literalmente HY000/1901 con backticks para active_dedupe_key.'
            );
        }
    } elseif ($originalFailure === null) {
        $pdo->exec('ALTER TABLE system_module_jobs DROP COLUMN active_dedupe_key');
    }
    $pdo->prepare(
        "INSERT INTO system_update_migrations
         (migration_key,checksum_sha256,release_version,state,attempts,safe_error_message)
         VALUES (?,?,'2.17.1','drifted',1,'La sustitución anterior fue rechazada antes de ejecutar SQL')"
    )->execute([$migrationKey, $newChecksum]);
    $pdo->prepare(
        "INSERT INTO system_update_migration_events
         (diagnostic_id,migration_key,stage,status,checksum_sha256,sql_state,driver_code,safe_message,context_json)
         VALUES ('MIG-20260727-034412-c99011',?,'migration_failed','failed',?,'HY000','1901',
         'Function or expression cannot be used in the GENERATED ALWAYS AS clause of `active_dedupe_key`',
         '{\"failed_stage\":\"sql_execution\"}')"
    )->execute([$migrationKey, $oldChecksum]);
    $pdo->prepare(
        "INSERT INTO system_update_migration_events
         (diagnostic_id,migration_key,stage,status,checksum_sha256,safe_message,context_json)
         VALUES ('MIG-20260727-042748-dcfd34',?,'checksum_drifted','drifted',?,
         'La sustitución anterior fue rechazada antes de ejecutar SQL',
         '{\"failed_stage\":\"state_drifted\",\"registered_checksum\":\"f2b58e5d\",\"observed_checksum\":\"3091de27\"}')"
    )->execute([$migrationKey, $newChecksum]);

    // Un lease vigente debe bloquear la recuperación sin ejecutar SQL ni cambiar el checksum.
    $pdo->exec(
        "INSERT INTO system_module_jobs
         (module_id,job_type,status,priority,next_run_at,lock_owner,lock_expires_at,created_at,updated_at)
         VALUES ('meli-insights','snapshot_sync','running',100,UTC_TIMESTAMP(),'integration-live',
                 DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    );
    $blocked = false;
    try {
        $migrator->run();
    } catch (\App\Services\MigrationExecutionException $error) {
        $blocked = str_contains($error->getMessage(), 'active_module_job');
    }
    if (!$blocked) {
        throw new RuntimeException('Un lease modular vigente no bloqueó la recuperación certificada.');
    }
    $checksumAfterRejection = (string) $pdo->query(
        "SELECT checksum_sha256 FROM system_update_migrations
         WHERE migration_key='087_module_runtime_jobs_hardening_2_17_0.sql'"
    )->fetchColumn();
    if (!hash_equals($newChecksum, $checksumAfterRejection)) {
        throw new RuntimeException('El rechazo de recuperación sobrescribió el checksum registrado.');
    }
    $correctedSqlStarts = (int) $pdo->query(
        "SELECT COUNT(*) FROM system_update_migration_events
         WHERE migration_key='087_module_runtime_jobs_hardening_2_17_0.sql'
           AND checksum_sha256='{$newChecksum}'
           AND stage='sql_execution_started'"
    )->fetchColumn();
    if ($correctedSqlStarts !== 0) {
        throw new RuntimeException('La recuperación bloqueada alcanzó a ejecutar SQL.');
    }
    $pdo->exec(
        "UPDATE system_module_jobs
         SET status='cancelled',lock_owner=NULL,lock_expires_at=NULL,finished_at=UTC_TIMESTAMP()
         WHERE lock_owner='integration-live'"
    );

    $result = $migrator->run();
    if (count(array_filter($result, static fn (array $row): bool => $row['status'] === 'applied')) !== 3) {
        throw new RuntimeException('087, 088 y 089 no quedaron aplicadas.');
    }
    $replacementCount = (int) $pdo->query('SELECT COUNT(*) FROM system_update_migration_replacements')->fetchColumn();
    if ($replacementCount !== 1) {
        throw new RuntimeException('La sustitución exacta de 087 no quedó auditada.');
    }
    $column = $pdo->query(
        "SELECT EXTRA FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_module_jobs' AND COLUMN_NAME='active_dedupe_key'"
    )->fetchColumn();
    if ($column === false || str_contains(strtoupper((string) $column), 'GENERATED')) {
        throw new RuntimeException('active_dedupe_key no quedó como columna explícita.');
    }
    $second = $migrator->run();
    if (count(array_filter($second, static fn (array $row): bool => $row['status'] !== 'skip')) !== 0) {
        throw new RuntimeException('La segunda ejecución no fue idempotente.');
    }

    fwrite(STDOUT, "OK: secuencia real drifted, backticks, recuperación 087–089 e idempotencia verificadas.\n");
} catch (Throwable $error) {
    $failure = $error;
} finally {
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
    } catch (Throwable) {
    }
    if (is_dir($temporaryRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($temporaryRoot);
    }
}
if ($failure instanceof Throwable) {
    fwrite(STDERR, 'ERROR: ' . $failure::class . ': ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
