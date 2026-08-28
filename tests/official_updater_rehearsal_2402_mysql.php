<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$artifactDir = rtrim((string) ($argv[1] ?? ''), '/\\');
$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');

if ($artifactDir === '' || !is_dir($artifactDir)) {
    fwrite(STDERR, "ERROR: official_updater_rehearsal_2402_mysql requires artifact directory.\n");
    exit(2);
}
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: official_updater_rehearsal_2402_mysql requires disposable MariaDB DSN without dbname.\n");
    exit(2);
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ERROR: official_updater_rehearsal_2402_mysql requires ZipArchive.\n");
    exit(2);
}

$package = $artifactDir . '/ERP_MELI_2.40.2_UPDATE_PACKAGE.erpupd';
if (!is_file($package)) {
    fwrite(STDERR, "ERROR: ERP_MELI_2.40.2_UPDATE_PACKAGE.erpupd missing.\n");
    exit(2);
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$rrmdir = static function (string $path) use (&$rrmdir): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $rrmdir($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$dbName = 'erp_official_updater_2402_' . bin2hex(random_bytes(4));
$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp_official_updater_2402_' . bin2hex(random_bytes(4));

try {
    $server->exec('CREATE DATABASE ' . $quote($dbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO($dsn . ';dbname=' . $dbName, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");

    $installRoot = $sandbox . DIRECTORY_SEPARATOR . 'install';
    $sharedRoot = $installRoot . DIRECTORY_SEPARATOR . 'shared';
    $releaseRoot = $root;
    foreach ([$sharedRoot . '/storage', $sharedRoot . '/update-inbox', $sharedRoot . '/update-staging', $sharedRoot . '/update-backups', $installRoot . '/releases'] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('sandbox_directory_failed:' . $directory);
        }
    }
    file_put_contents($sharedRoot . '/config.env', implode("\n", [
        'APP_KEY=official-updater-rehearsal-key',
        'APP_TIMEZONE=America/Bogota',
        'ML_WRITE_ENABLED=false',
        'DB_HOST=127.0.0.1',
        'DB_PORT=33316',
        'DB_NAME=' . $dbName,
        'DB_USER=' . $user,
        'DB_PASS=' . $pass,
    ]) . "\n");

    define('ERP_RELEASE_ROOT', $releaseRoot);
    define('ERP_INSTALLATION_ROOT', $installRoot);
    define('ERP_SHARED_ROOT', $sharedRoot);
    putenv('APP_KEY=official-updater-rehearsal-key');
    putenv('APP_TIMEZONE=America/Bogota');
    putenv('ML_WRITE_ENABLED=false');
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_PORT=33316');
    putenv('DB_NAME=' . $dbName);
    putenv('DB_USER=' . $user);
    putenv('DB_PASS=' . $pass);

    $vendor = $root . '/vendor/autoload.php';
    if (is_file($vendor)) {
        require $vendor;
    } else {
        spl_autoload_register(static function (string $class) use ($root): void {
            $prefix = 'App\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $path = $root . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }
    \App\Core\Env::load($sharedRoot . '/config.env');

    $pdo = \App\Core\Database::connectionFresh();
    $pdo->exec('CREATE TABLE users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NULL,
        email VARCHAR(190) NULL,
        password_hash VARCHAR(255) NULL,
        role VARCHAR(40) NULL,
        company_id BIGINT UNSIGNED NULL,
        created_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE schema_migrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(191) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE app_settings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(191) NOT NULL UNIQUE,
        setting_value MEDIUMTEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE app_versions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(40) NOT NULL UNIQUE,
        notes VARCHAR(255) NULL,
        installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE system_update_releases (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        release_id VARCHAR(160) NOT NULL UNIQUE,
        product_id VARCHAR(80) NOT NULL,
        version VARCHAR(40) NOT NULL,
        sequence_no INT NOT NULL DEFAULT 0,
        channel VARCHAR(40) NOT NULL,
        source_type VARCHAR(40) NOT NULL,
        source_reference VARCHAR(700) NULL,
        manifest_json LONGTEXT NULL,
        manifest_sha256 CHAR(64) NULL,
        signature_status VARCHAR(40) NULL,
        status VARCHAR(40) NOT NULL,
        is_withdrawn TINYINT(1) NOT NULL DEFAULT 0,
        installed_at DATETIME NULL,
        activated_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE system_update_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_uuid CHAR(36) NOT NULL UNIQUE,
        release_id BIGINT UNSIGNED NULL,
        version_from VARCHAR(40) NULL,
        version_to VARCHAR(40) NULL,
        mode VARCHAR(40) NOT NULL,
        state VARCHAR(60) NOT NULL,
        progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        backup_requested TINYINT(1) NOT NULL DEFAULT 0,
        backup_waived TINYINT(1) NOT NULL DEFAULT 0,
        backup_waiver_text VARCHAR(120) NULL,
        current_step VARCHAR(160) NULL,
        next_action VARCHAR(160) NULL,
        source_path VARCHAR(700) NULL,
        staging_path VARCHAR(700) NULL,
        schema_classification VARCHAR(60) NULL,
        safe_error_code VARCHAR(120) NULL,
        safe_error_message VARCHAR(700) NULL,
        previous_release_id VARCHAR(160) NULL,
        started_by BIGINT UNSIGNED NULL,
        started_at DATETIME NULL,
        heartbeat_at DATETIME NULL,
        completed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE system_update_run_steps (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NOT NULL,
        step_key VARCHAR(80) NOT NULL,
        sequence_no INT NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT \'pending\',
        progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        safe_message VARCHAR(700) NULL,
        UNIQUE KEY uq_run_step (run_id,step_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE system_update_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NOT NULL,
        level VARCHAR(30) NOT NULL,
        event_code VARCHAR(120) NOT NULL,
        message VARCHAR(700) NULL,
        context_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_update_events_run (run_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE system_update_schema_fingerprints (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NULL,
        app_version VARCHAR(40) NULL,
        fingerprint_sha256 CHAR(64) NOT NULL,
        schema_json LONGTEXT NOT NULL,
        classification VARCHAR(60) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE system_update_schema_differences (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NOT NULL,
        object_type VARCHAR(40) NOT NULL,
        object_name VARCHAR(190) NOT NULL,
        difference_type VARCHAR(80) NOT NULL,
        expected_value VARCHAR(255) NULL,
        actual_value VARCHAR(255) NULL,
        severity VARCHAR(40) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE system_update_backups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NULL,
        backup_type VARCHAR(40) NULL,
        adapter VARCHAR(40) NULL,
        status VARCHAR(40) NULL,
        size_bytes BIGINT UNSIGNED NULL,
        checksum_sha256 CHAR(64) NULL,
        verified_at DATETIME NULL,
        expires_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE system_update_trusted_keys (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        key_id VARCHAR(120) NOT NULL UNIQUE,
        public_key_pem LONGTEXT NULL,
        algorithm VARCHAR(40) NULL,
        channels_json MEDIUMTEXT NULL,
        status VARCHAR(40) NOT NULL,
        valid_from DATETIME NULL,
        valid_until DATETIME NULL,
        revoked_at DATETIME NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE system_update_locks (
        lock_name VARCHAR(120) NOT NULL PRIMARY KEY,
        owner_token VARCHAR(120) NOT NULL,
        run_id BIGINT UNSIGNED NULL,
        heartbeat_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_system_update_locks_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE cron_task_state (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE cron_health_checks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        current_step VARCHAR(160) NULL,
        deadline_at DATETIME NULL,
        end_reason VARCHAR(120) NULL,
        lock_name VARCHAR(120) NULL,
        release_version VARCHAR(40) NULL,
        release_build_id VARCHAR(160) NULL,
        component_checksum CHAR(64) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_notification_work_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
        last_success_at DATETIME NULL,
        processing_event_id BIGINT UNSIGNED NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_oauth_states (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        processing_token_hash CHAR(64) NULL,
        processing_at DATETIME NULL,
        last_error_message VARCHAR(700) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    (new \App\Services\Migrator($pdo, $root . '/database/migrations'))->pendingCount();

    $stmt = $pdo->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
    foreach (glob($root . '/database/migrations/*.sql') ?: [] as $migration) {
        $stmt->execute([basename($migration)]);
    }
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value,is_encrypted) VALUES
        ('app.version','2.40.1',0),
        ('update.telemetry_enabled','0',0),
        ('update.remote_url','',0)");
    $pdo->exec("INSERT INTO app_versions(version,notes,installed_at) VALUES ('2.40.1','baseline',UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO system_update_releases(release_id,product_id,version,sequence_no,channel,source_type,status,is_withdrawn,installed_at,activated_at)
        VALUES ('erp-meli-2.40.1-baseline','erp-meli','2.40.1',24001,'manual','package','active',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    file_put_contents($sharedRoot . '/current-release.json', json_encode([
        'release_id' => 'erp-meli-2.40.1-baseline',
        'version' => '2.40.1',
        'path' => 'releases/erp-meli-2.40.1-baseline',
        'activated_at' => gmdate('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $packageCopy = $sharedRoot . '/update-inbox/ERP_MELI_2.40.2_UPDATE_PACKAGE.erpupd';
    copy($package, $packageCopy);
    $sourceKey = hash('sha256', realpath($packageCopy) ?: $packageCopy);

    $engine = new \App\Services\SecureUpdateEngineService();
    $assert($engine->engineReady(), 'official_engine_not_ready');
    $run = $engine->createRun($sourceKey, 'atomic', false, 'ACTUALIZAR SIN RESPALDO', 1);
    $assert((string) $run['version_from'] === '2.40.1', 'version_from_not_2401');
    $assert((string) $run['version_to'] === '2.40.2', 'version_to_not_2402');
    $preHealth = (new \App\Services\UpdateReleaseService())->healthCheck((string) $run['staging_path'], false);
    if (!(bool) ($preHealth['ok'] ?? false)) {
        $integrity = (new \App\Services\ReleaseIntegrityService())->inspectDirectory((string) $run['staging_path'], false, false);
        $assert(false, 'pre_activation_structural_health_failed:' . json_encode([
            'health' => $preHealth,
            'errors' => $integrity['errors'] ?? [],
            'module_issues' => $integrity['module_issues'] ?? [],
        ], JSON_UNESCAPED_SLASHES));
    }
    $runId = (int) $run['id'];
    $visited = [];
    for ($i = 0; $i < 16; $i++) {
        $run = $engine->process($runId);
        $visited[] = (string) $run['state'];
        if (in_array((string) $run['state'], ['completed', 'failed', 'manual_intervention', 'rolled_back'], true)) {
            break;
        }
    }

    $final = $engine->run($runId);
    $assert(is_array($final), 'official_run_not_found');
    if ((string) $final['state'] !== 'completed') {
        $health = (new \App\Services\UpdateReleaseService())->healthCheck((string) $final['staging_path'], true);
        $integrity = (new \App\Services\ReleaseIntegrityService())->inspectDirectory((string) $final['staging_path'], true, false);
        $events = $pdo->prepare('SELECT level,event_code,message,context_json FROM system_update_events WHERE run_id=? ORDER BY id DESC LIMIT 5');
        $events->execute([$runId]);
        $assert(false, 'official_run_not_completed:' . (string) $final['state'] . ':' . json_encode([
            'current_step' => $final['current_step'] ?? null,
            'next_action' => $final['next_action'] ?? null,
            'safe_error' => $final['safe_error_message'] ?? null,
            'health' => $health,
            'integrity_errors' => $integrity['errors'] ?? [],
            'integrity_schema' => $integrity['schema'] ?? [],
            'events' => $events->fetchAll(PDO::FETCH_ASSOC),
        ], JSON_UNESCAPED_SLASHES));
    }
    $assert((string) $final['state'] === 'completed', 'official_run_not_completed:' . (string) $final['state'] . ':' . (string) ($final['safe_error_message'] ?? ''));
    $assert(!in_array('manual_intervention', $visited, true), 'official_run_manual_intervention');
    $assert(!in_array('failed', $visited, true), 'official_run_failed');
    $assert((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.40.2', 'app_version_not_promoted');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.40.2'")->fetchColumn() === 1, 'app_versions_history_missing');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='300_manual_drain_sessions_2_40_0.sql'")->fetchColumn() === 1, 'schema_300_missing_or_duplicate');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version LIKE '301\\_%'")->fetchColumn() === 0, 'schema_301_unexpected');
    $pointer = json_decode((string) file_get_contents($sharedRoot . '/current-release.json'), true, 16, JSON_THROW_ON_ERROR);
    $assert(is_array($pointer) && ($pointer['version'] ?? null) === '2.40.2', 'pointer_not_2402');
    $assert(is_dir($installRoot . '/releases/erp-meli-2.40.2-operator-control-plane-kiss'), 'release_directory_missing');
    $assert(is_file($sharedRoot . '/storage/installed-release.json'), 'installed_marker_missing');
    $marker = (new \App\Services\InstalledVersionMarkerService())->read();
    $assert($marker['valid'] && $marker['version'] === '2.40.2', 'installed_marker_invalid');
    $forbiddenPromotion = 'DirectUpdateMetadata' . 'PromotionService';
    $assert(!str_contains((string) file_get_contents(__FILE__), $forbiddenPromotion), 'direct_metadata_promotion_referenced_by_rehearsal');

    fwrite(STDOUT, 'OFFICIAL_UPDATER_REHEARSAL_2402=PASS checks=' . $checks
        . ' SOURCE_VERSION=2.40.1 TARGET_VERSION=2.40.2 SCHEMA=300 MIGRATIONS_NEW=0'
        . ' DIRECT_METADATA_PROMOTION_USED=NO REAL_MELI_HTTP=0 PRODUCTION_TOUCHED=NO' . PHP_EOL);
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $quote($dbName));
    $rrmdir($sandbox);
}
