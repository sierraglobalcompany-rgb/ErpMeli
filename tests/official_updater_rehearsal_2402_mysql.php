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
$componentKey = static function (string $path): string {
    return match ($path) {
        'jobs/cron_probe.php' => 'cron_probe',
        'jobs/process_sync_queue.php' => 'process_sync_queue',
        default => 'runtime_' . trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($path)), '_'),
    };
};
$textSha = static function (string $path): string {
    $contents = (string) file_get_contents($path);
    return hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents));
};
$writeSyntheticRuntimeManifest = static function (string $releaseDirectory, string $version) use ($componentKey, $textSha): void {
    $profiles = [
        '2.40.1' => [
            'build_id' => 'erp-meli-2.40.1-worker-cycle-control-single-truth-rc1-20260827',
            'minimum_migration' => '300_manual_drain_sessions_2_40_0.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.40.1.json',
        ],
        '2.40.2' => [
            'build_id' => 'erp-meli-2.40.2-operator-control-plane-kiss-rc1-20260827',
            'minimum_migration' => '300_manual_drain_sessions_2_40_0.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.40.2.json',
        ],
    ];
    $profile = $profiles[$version] ?? throw new RuntimeException('unsupported_synthetic_baseline:' . $version);
    $manifestPath = rtrim($releaseDirectory, '/\\') . '/resources/runtime-manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || !is_array($manifest['components'] ?? null) || !is_array($manifest['publication_policy'] ?? null)) {
        throw new RuntimeException('synthetic_runtime_manifest_malformed');
    }
    $manifest['version'] = $version;
    $manifest['build_id'] = $profile['build_id'];
    $manifest['minimum_migration'] = $profile['minimum_migration'];

    $registryPath = rtrim($releaseDirectory, '/\\') . '/' . $profile['dependency_registry'];
    if ($version === '2.40.1') {
        $currentRegistry = rtrim($releaseDirectory, '/\\') . '/resources/release/managed-runtime-dependencies-2.40.2.json';
        if (!is_file($currentRegistry) || !copy($currentRegistry, $registryPath)) {
            throw new RuntimeException('synthetic_2401_runtime_registry_failed');
        }
    }
    $registry = json_decode((string) file_get_contents($registryPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($registry)) {
        throw new RuntimeException('synthetic_registry_malformed');
    }
    $paths = [];
    foreach ($manifest['components'] as $definition) {
        if (is_array($definition) && isset($definition['path'])) {
            $paths[] = (string) $definition['path'];
        }
    }
    sort($paths, SORT_STRING);
    $pathsSha = hash('sha256', implode("\n", $paths) . "\n");
    $registry['runtime_manifest_paths_sha256'] = $pathsSha;
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    foreach (['VERSION', $profile['dependency_registry']] as $path) {
        $key = $componentKey($path);
        if (isset($manifest['components'][$key]) && is_array($manifest['components'][$key])) {
            $absolute = rtrim($releaseDirectory, '/\\') . '/' . $path;
            $manifest['components'][$key]['sha256'] = hash_file('sha256', $absolute);
            $manifest['components'][$key]['sha256_lf'] = $textSha($absolute);
            $manifest['components'][$key]['text'] = true;
        }
    }
    $manifest['publication_policy']['package_file_count'] = count($paths) + 1;
    $manifest['publication_policy']['component_count'] = count($paths);
    $manifest['publication_policy']['paths_sha256'] = $pathsSha;
    $manifest['publication_policy']['dependency_registry_sha256'] = hash_file('sha256', $registryPath);
    file_put_contents(
        $manifestPath,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
    );
};
$extractPackageAsRelease = static function (string $packagePath, string $releaseDirectory, string $version, bool $rewriteManifest) use ($rrmdir, $writeSyntheticRuntimeManifest): void {
    $rrmdir($releaseDirectory);
    if (!mkdir($releaseDirectory, 0770, true) && !is_dir($releaseDirectory)) {
        throw new RuntimeException('baseline_release_directory_failed');
    }
    $zip = new ZipArchive();
    if ($zip->open($packagePath) !== true) {
        throw new RuntimeException('baseline_package_open_failed');
    }
    try {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $entry);
            if ($normalized === '' || str_ends_with($normalized, '/')) {
                continue;
            }
            if (
                str_starts_with($normalized, '/')
                || preg_match('#^[A-Za-z]:/#', $normalized) === 1
                || in_array('..', explode('/', $normalized), true)
            ) {
                throw new RuntimeException('baseline_package_path_unsafe:' . $normalized);
            }
            $target = rtrim($releaseDirectory, '/\\') . '/' . $normalized;
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
                throw new RuntimeException('baseline_package_directory_failed:' . $normalized);
            }
            $bytes = $zip->getFromIndex($i);
            if (!is_string($bytes) || file_put_contents($target, $bytes) === false) {
                throw new RuntimeException('baseline_package_entry_failed:' . $normalized);
            }
        }
    } finally {
        $zip->close();
    }
    @unlink(rtrim($releaseDirectory, '/\\') . '/update-manifest.json');
    if ($rewriteManifest) {
        file_put_contents(rtrim($releaseDirectory, '/\\') . '/VERSION', $version . "\n");
        $writeSyntheticRuntimeManifest($releaseDirectory, $version);
    }
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
    $baselineReleaseDirectory = $installRoot . '/releases/erp-meli-2.40.1-baseline';
    $extractPackageAsRelease($package, $baselineReleaseDirectory, '2.40.1', true);
    (new \App\Services\InstalledVersionMarkerService())->write('2.40.1', '300_manual_drain_sessions_2_40_0.sql');

    $packageCopy = $sharedRoot . '/update-inbox/ERP_MELI_2.40.2_UPDATE_PACKAGE.erpupd';
    copy($package, $packageCopy);
    $sourceKey = hash('sha256', realpath($packageCopy) ?: $packageCopy);
    $readPointerVersion = static function () use ($sharedRoot): string {
        $pointer = json_decode((string) file_get_contents($sharedRoot . '/current-release.json'), true, 16, JSON_THROW_ON_ERROR);
        return is_array($pointer) ? (string) ($pointer['version'] ?? '') : '';
    };
    $readMarkerVersion = static function (): string {
        $marker = (new \App\Services\InstalledVersionMarkerService())->read();
        return ($marker['valid'] ?? false) ? (string) $marker['version'] : '';
    };
    $resetToBaseline = static function () use ($pdo, $sharedRoot): void {
        $pdo->exec("INSERT INTO app_settings(setting_key,setting_value,is_encrypted)
            VALUES ('app.version','2.40.1',0)
            ON DUPLICATE KEY UPDATE setting_value='2.40.1',is_encrypted=0");
        $pdo->exec("INSERT INTO app_versions(version,notes,installed_at)
            VALUES ('2.40.1','baseline reset',UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=UTC_TIMESTAMP()");
        file_put_contents($sharedRoot . '/current-release.json', json_encode([
            'release_id' => 'erp-meli-2.40.1-baseline',
            'version' => '2.40.1',
            'path' => 'releases/erp-meli-2.40.1-baseline',
            'activated_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        (new \App\Services\InstalledVersionMarkerService())->write('2.40.1', '300_manual_drain_sessions_2_40_0.sql');
    };
    $engine = new \App\Services\SecureUpdateEngineService();
    $assert($engine->engineReady(), 'official_engine_not_ready');
    $runOfficialToHealthCheck = static function () use ($engine, $sourceKey, $assert): array {
        $run = $engine->createRun($sourceKey, 'atomic', false, 'ACTUALIZAR SIN RESPALDO', 1);
        $assert((string) $run['version_from'] === '2.40.1', 'health_run_version_from_not_2401');
        $assert((string) $run['version_to'] === '2.40.2', 'health_run_version_to_not_2402');
        $runId = (int) $run['id'];
        for ($i = 0; $i < 16; $i++) {
            $run = $engine->process($runId);
            if ((string) $run['state'] === 'health_check') {
                return $run;
            }
            if (in_array((string) $run['state'], ['completed', 'failed', 'manual_intervention', 'rolled_back'], true)) {
                break;
            }
        }
        throw new RuntimeException('official_run_did_not_reach_health_check:' . (string) ($run['state'] ?? 'unknown'));
    };
    $forcePostActivationHealthFailure = static function (int $runId) use ($pdo, $engine, $assert): array {
        $pdo->exec('ALTER TABLE cron_health_checks DROP COLUMN current_step');
        try {
            $run = $engine->process($runId);
        } finally {
            $pdo->exec('ALTER TABLE cron_health_checks ADD COLUMN current_step VARCHAR(160) NULL AFTER id');
        }
        $assert((string) $run['state'] === 'rollback_pending', 'forced_health_failure_not_rollback_pending:' . (string) $run['state']);
        return $run;
    };

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

    $resetToBaseline();
    $migration300BeforeRollback = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='300_manual_drain_sessions_2_40_0.sql'")->fetchColumn();
    $rollbackCandidate = $runOfficialToHealthCheck();
    $assert($readPointerVersion() === '2.40.2', 'rollback_case_pointer_not_activated_to_2402');
    $assert((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.40.2', 'rollback_case_app_version_not_activated_to_2402');
    $assert($readMarkerVersion() === '2.40.2', 'rollback_case_marker_not_activated_to_2402');
    $forcePostActivationHealthFailure((int) $rollbackCandidate['id']);
    $rollbackPointer = $engine->rollback((int) $rollbackCandidate['id']);
    $rollbackRun = $engine->run((int) $rollbackCandidate['id']);
    $pointerAfterRollback = $readPointerVersion();
    $appVersionAfterRollback = (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn();
    $markerAfterRollback = $readMarkerVersion();
    $migration300AfterRollback = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='300_manual_drain_sessions_2_40_0.sql'")->fetchColumn();
    $migration301AfterRollback = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version LIKE '301\\_%'")->fetchColumn();
    $bugReproduced = $pointerAfterRollback === '2.40.1'
        && ($appVersionAfterRollback !== '2.40.1' || $markerAfterRollback !== '2.40.1');
    if ($bugReproduced) {
        fwrite(STDOUT, 'ROLLBACK_VERSION_AUTHORITY_BUG=REPRODUCED'
            . ' POINTER_AFTER_ROLLBACK=' . $pointerAfterRollback
            . ' APP_VERSION_AFTER_ROLLBACK=' . $appVersionAfterRollback
            . ' MARKER_AFTER_ROLLBACK=' . $markerAfterRollback
            . ' SCHEMA_AFTER_ROLLBACK=300' . PHP_EOL);
    }
    $assert(!$bugReproduced, 'rollback_version_authority_bug_reproduced');
    $assert(is_array($rollbackPointer) && (string) ($rollbackPointer['version'] ?? '') === '2.40.1', 'rollback_pointer_service_not_2401');
    $assert($pointerAfterRollback === '2.40.1', 'rollback_pointer_not_restored');
    $assert($appVersionAfterRollback === '2.40.1', 'rollback_app_version_not_restored');
    $assert($markerAfterRollback === '2.40.1', 'rollback_marker_not_restored');
    $assert($migration300AfterRollback === $migration300BeforeRollback, 'rollback_schema_300_count_changed');
    $assert($migration301AfterRollback === 0, 'rollback_schema_301_unexpected');
    $assert(is_array($rollbackRun) && (string) $rollbackRun['state'] === 'rolled_back', 'rollback_run_not_rolled_back');

    $retryRun = $engine->createRun($sourceKey, 'atomic', false, 'ACTUALIZAR SIN RESPALDO', 1);
    $assert((string) $retryRun['version_from'] === '2.40.1', 'retry_after_rollback_upgrade_from_not_2401');
    $retryRunId = (int) $retryRun['id'];
    for ($i = 0; $i < 16; $i++) {
        $retryRun = $engine->process($retryRunId);
        if (in_array((string) $retryRun['state'], ['completed', 'failed', 'manual_intervention', 'rolled_back'], true)) {
            break;
        }
    }
    $assert((string) $retryRun['state'] === 'completed', 'retry_after_rollback_not_completed:' . (string) $retryRun['state']);
    $assert($readPointerVersion() === '2.40.2', 'retry_after_rollback_pointer_not_2402');
    $assert((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.40.2', 'retry_after_rollback_app_version_not_2402');
    $assert($readMarkerVersion() === '2.40.2', 'retry_after_rollback_marker_not_2402');

    $resetToBaseline();
    $rollbackFailureCandidate = $runOfficialToHealthCheck();
    $forcePostActivationHealthFailure((int) $rollbackFailureCandidate['id']);
    $corruptPointer = json_decode((string) file_get_contents($sharedRoot . '/current-release.json'), true, 16, JSON_THROW_ON_ERROR);
    $corruptPointer['previous_release_id'] = 'missing-previous-release-for-failure-simulation';
    file_put_contents($sharedRoot . '/current-release.json', json_encode($corruptPointer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $rollbackFailureThrown = false;
    try {
        $engine->rollback((int) $rollbackFailureCandidate['id']);
    } catch (RuntimeException) {
        $rollbackFailureThrown = true;
    }
    $assert($rollbackFailureThrown, 'rollback_failure_simulation_not_thrown');
    $assert($readPointerVersion() === '2.40.2', 'rollback_failure_pointer_changed_before_physical_success');
    $assert((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.40.2', 'rollback_failure_app_version_changed_before_physical_success');
    $assert($readMarkerVersion() === '2.40.2', 'rollback_failure_marker_changed_before_physical_success');

    fwrite(STDOUT, 'OFFICIAL_UPDATER_REHEARSAL_2402=PASS checks=' . $checks
        . ' SOURCE_VERSION=2.40.1 TARGET_VERSION=2.40.2 SCHEMA=300 MIGRATIONS_NEW=0'
        . ' FORCED_HEALTH_FAILURE_TESTED=YES ROLLBACK_AUTHORITY_RESTORED=YES'
        . ' ROLLBACK_POINTER_RESTORED=YES ROLLBACK_APP_VERSION_RESTORED=YES'
        . ' ROLLBACK_MARKER_RESTORED=YES ROLLBACK_SCHEMA_PRESERVED=YES'
        . ' RETRY_AFTER_ROLLBACK=PASS DOWNGRADE_GUARD_NOT_FALSE_POSITIVE=PASS'
        . ' UPGRADE_FROM_2401_ACCEPTED=PASS ROLLBACK_FAILURE_PATH=PASS'
        . ' DIRECT_METADATA_PROMOTION_USED=NO REAL_MELI_HTTP=0 PRODUCTION_TOUCHED=NO' . PHP_EOL);
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $quote($dbName));
    $rrmdir($sandbox);
}
