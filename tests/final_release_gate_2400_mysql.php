<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/UpdateManifestService.php';
require dirname(__DIR__) . '/app/Services/UpdateFilesystemService.php';

use App\Services\UpdateManifestService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
$artifactDir = rtrim((string) ($argv[1] ?? ''), "/\\");
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: final_release_gate_2400_mysql requires a disposable MariaDB DSN without dbname.\n");
    exit(2);
}
if ($artifactDir === '' || !is_dir($artifactDir) || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ERROR: final_release_gate_2400_mysql requires release artifact directory and ZipArchive.\n");
    exit(2);
}

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$artifactVersion = '2.40.0';
$packagePath = $artifactDir . '/ERP_MELI_' . $artifactVersion . '_UPDATE_PACKAGE.erpupd';
$assert(is_file($packagePath), 'update_package_missing');
$zip = new ZipArchive();
$assert($zip->open($packagePath) === true, 'update_package_zip_open_failed');
$manifestBytes = $zip->getFromName('update-manifest.json');
$assert(is_string($manifestBytes), 'update_manifest_missing');
$packageFiles = [];
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = (string) $zip->getNameIndex($index);
    $packageFiles[$name] = true;
}
$zip->close();
$manifest = (new UpdateManifestService())->decode($manifestBytes);
$assert(($manifest['version'] ?? null) === '2.40.0', 'manifest_version_invalid');
$assert(($manifest['upgrade_from'] ?? null) === ['2.39.19'], 'manifest_upgrade_from_invalid');
$assert(in_array('300_manual_drain_sessions_2_40_0.sql', (array) ($manifest['migrations'] ?? []), true), 'migration_300_missing');
$assert(count(array_filter((array) ($manifest['migrations'] ?? []), static fn (string $name): bool => $name === '300_manual_drain_sessions_2_40_0.sql')) === 1, 'migration_300_duplicate_in_manifest');
$assert(!array_filter((array) ($manifest['migrations'] ?? []), static fn (string $name): bool => str_starts_with($name, '301_')), 'migration_301_present');
foreach ((array) ($manifest['files'] ?? []) as $row) {
    $path = is_array($row) ? (string) ($row['path'] ?? '') : '';
    $assert($path !== '' && isset($packageFiles[$path]), 'manifest_file_not_packaged:' . $path);
}

$migrationFiles = [
    '280_queue_core_cron_v4_phase_b1.sql',
    '281_queue_core_reaudit1_fifo_fencing.sql',
    '294_queue_v4_clean_greenfield_2_37_0.sql',
    '299_queue_v4_domain_exact_admission_2_39_3.sql',
    '300_manual_drain_sessions_2_40_0.sql',
];
$applyMigration = static function (PDO $pdo, string $name) use ($root): void {
    $sql = (string) file_get_contents($root . '/database/migrations/' . $name);
    $pdo->exec($sql);
};
$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() === 1;
};
$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() === 1;
};
$createSchemaMigrations = static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(191) NOT NULL UNIQUE,
        applied_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(191) NOT NULL UNIQUE,
        setting_value MEDIUMTEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
        setting_group VARCHAR(80) NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
};
$recordMigration = static function (PDO $pdo, string $name): void {
    $stmt = $pdo->prepare('INSERT IGNORE INTO schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
    $stmt->execute([$name]);
};

$databases = [
    'fresh' => 'erp_gate_2400_fresh_' . bin2hex(random_bytes(4)),
    'upgrade' => 'erp_gate_2400_upgrade_' . bin2hex(random_bytes(4)),
    'restore' => 'erp_gate_2400_restore_' . bin2hex(random_bytes(4)),
];

try {
    $server->exec('CREATE DATABASE ' . $quote($databases['fresh']) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $fresh = new PDO($dsn . ';dbname=' . $databases['fresh'], $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $fresh->exec("SET SESSION time_zone='+00:00'");
    $createSchemaMigrations($fresh);
    foreach ($migrationFiles as $file) {
        $applyMigration($fresh, $file);
        $recordMigration($fresh, $file);
    }
    $assert($tableExists($fresh, 'manual_drain_sessions'), 'fresh_manual_drain_sessions_missing');
    $assert($tableExists($fresh, 'queue_v4_clean_jobs'), 'fresh_queue_v4_clean_jobs_missing');
    $assert($tableExists($fresh, 'queue_core_execution_leases'), 'fresh_queue_core_execution_leases_missing');
    $assert($columnExists($fresh, 'manual_drain_sessions', 'burst_remote_calls_current'), 'fresh_burst_column_missing');

    $server->exec('CREATE DATABASE ' . $quote($databases['upgrade']) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $upgrade = new PDO($dsn . ';dbname=' . $databases['upgrade'], $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $upgrade->exec("SET SESSION time_zone='+00:00'");
    $createSchemaMigrations($upgrade);
    foreach (array_slice($migrationFiles, 0, 4) as $file) {
        $applyMigration($upgrade, $file);
        $recordMigration($upgrade, $file);
    }
    $upgrade->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1 WHERE control_key='primary'");
    $upgrade->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,available_at) VALUES (1,1,'order_exact','order:fixture','gate-fixture','ready',JSON_OBJECT('source','gate'),UTC_TIMESTAMP(3))");
    $backupRows = [
        'schema_migrations' => (int) $upgrade->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(),
        'queue_v4_clean_jobs' => (int) $upgrade->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn(),
        'queue_state' => (string) $upgrade->query("SELECT CONCAT(engine_state,'/',readiness_state,'/',scheduler_enabled) FROM queue_v4_clean_control WHERE control_key='primary'")->fetchColumn(),
    ];
    $assert(!$tableExists($upgrade, 'manual_drain_sessions'), 'upgrade_preimage_manual_drain_sessions_must_be_absent');
    $applyMigration($upgrade, '300_manual_drain_sessions_2_40_0.sql');
    $recordMigration($upgrade, '300_manual_drain_sessions_2_40_0.sql');
    $assert($tableExists($upgrade, 'manual_drain_sessions'), 'upgrade_manual_drain_sessions_missing');
    $assert((int) $upgrade->query("SELECT COUNT(*) FROM schema_migrations WHERE version='300_manual_drain_sessions_2_40_0.sql'")->fetchColumn() === 1, 'migration_300_not_applied_once');
    $assert((int) $upgrade->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === $backupRows['queue_v4_clean_jobs'], 'queue_state_not_preserved');
    $assert((string) $upgrade->query("SELECT CONCAT(engine_state,'/',readiness_state,'/',scheduler_enabled) FROM queue_v4_clean_control WHERE control_key='primary'")->fetchColumn() === $backupRows['queue_state'], 'queue_control_not_preserved');
    $applyMigration($upgrade, '300_manual_drain_sessions_2_40_0.sql');
    $recordMigration($upgrade, '300_manual_drain_sessions_2_40_0.sql');
    $assert((int) $upgrade->query("SELECT COUNT(*) FROM schema_migrations WHERE version='300_manual_drain_sessions_2_40_0.sql'")->fetchColumn() === 1, 'second_run_duplicate_migration_300');
    $assert((int) $upgrade->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='manual_drain_sessions'")->fetchColumn() === 1, 'second_run_duplicate_session_table');

    $server->exec('CREATE DATABASE ' . $quote($databases['restore']) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $restore = new PDO($dsn . ';dbname=' . $databases['restore'], $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $restore->exec("SET SESSION time_zone='+00:00'");
    $createSchemaMigrations($restore);
    foreach (array_slice($migrationFiles, 0, 4) as $file) {
        $applyMigration($restore, $file);
        $recordMigration($restore, $file);
    }
    $restore->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1 WHERE control_key='primary'");
    $restore->exec("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,available_at) VALUES (7,8,'order_exact','order:restore','restore-fixture','ready',JSON_OBJECT('source','restore'),UTC_TIMESTAMP(3))");
    $restoreBackup = [
        'schema_migrations' => $restore->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN),
        'queue_jobs' => $restore->query('SELECT company_id,meli_account_id,job_type,resource_id,idempotency_key,state FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
    $applyMigration($restore, '300_manual_drain_sessions_2_40_0.sql');
    $recordMigration($restore, '300_manual_drain_sessions_2_40_0.sql');
    $restore->exec('DROP TABLE manual_drain_sessions');
    $restore->exec("DELETE FROM schema_migrations WHERE version='300_manual_drain_sessions_2_40_0.sql'");
    $assert($restore->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN) === $restoreBackup['schema_migrations'], 'restore_schema_migrations_not_restored');
    $assert($restore->query('SELECT company_id,meli_account_id,job_type,resource_id,idempotency_key,state FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $restoreBackup['queue_jobs'], 'restore_queue_rows_not_restored');
} finally {
    foreach ($databases as $database) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
    }
}

fwrite(STDOUT, "FINAL_RELEASE_GATE_2400_MYSQL=PASS checks={$checks} FRESH_INSTALL_2400=PASS UPGRADE_23919_TO_2400=PASS MIGRATION_300_APPLIED_ONCE=YES SECOND_UPDATER_RUN_SAFE=YES DATA_PRESERVED=YES QUEUE_STATE_PRESERVED=YES RESTORE_DISPOSABLE_TEST=PASS REAL_MELI_HTTP=0\n");
