<?php

declare(strict_types=1);

/**
 * Rehearsal destructivo exclusivamente para un clon MariaDB desechable.
 *
 * Requiere:
 *   ERP_2361_REHEARSAL_ACK=DISPOSABLE_CLONE_SCHEMA_279
 *   ERP_2361_REHEARSAL_DSN=mysql:host=127.0.0.1;port=...;dbname=...;charset=utf8mb4
 *   ERP_2361_REHEARSAL_USER=...
 *   ERP_2361_REHEARSAL_PASS=...
 *
 * El test rechaza hosts remotos y el puerto 3306. No usa el updater.
 */

$root = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . '/erp-meli-2361-version-' . bin2hex(random_bytes(6));
$sharedRoot = $temporaryRoot . '/shared';

define('ERP_RELEASE_ROOT', $root);
define('ERP_INSTALLATION_ROOT', $temporaryRoot);
define('ERP_SHARED_ROOT', $sharedRoot);

require $root . '/bootstrap.php';

use App\Core\Database;
use App\Services\InstalledVersionMarkerService;
use App\Services\Migrator;
use App\Services\MigrationTraceService;

set_exception_handler(static function (Throwable $error): void {
    $safe = MigrationTraceService::safeMessage($error) ?? 'unknown_failure';
    fwrite(STDERR, 'FAIL external_cutover_mariadb_2361 ' . $safe . PHP_EOL);
    exit(1);
});

const EXTERNAL_CUTOVER_LOCK_2361 = 'erp_meli_external_cutover_2_36_1';
const SOURCE_VERSION_2361 = '2.35.1';
const MIGRATION_INTERMEDIATE_VERSION_2361 = '2.36.0';
const TARGET_VERSION_2361 = '2.36.1';

/** @var array<string,string> $expectedMigrations */
$expectedMigrations = [
    '280_queue_core_cron_v4_phase_b1.sql' => '258f178398ef81af9926ede906ade933951a7ad3d011ad75f15f64ea77934470',
    '281_queue_core_reaudit1_fifo_fencing.sql' => '2c32b9a4f1f8d22b5f73c7a443587a0535487622240c4e422885aa7cf48d301e',
    '282_queue_core_architecture_closeout_b1_2.sql' => '3285961ebf339f14cdc16624facde6856e1cc82517adaa98f4fce95d8cf23709',
    '283_queue_engine_control_oauth_supervisor_b1_4.sql' => '40ae45a29f6d2c6b46bee8e281fbac93c318bbebeb5819db16e40381892a8ed5',
    '284_queue_core_sales_pipeline_b2.sql' => 'a6301a5e56b150f3fce547cfb7b6e322c764704b10f9fc2a130dfe5719686c96',
    '285_queue_core_webhook_ownership_b2.sql' => 'd2391e00e79377ecc88f4fe1651c6c7b1e7c49424bc5b5f88c2c5c79ddad0734',
    '286_queue_core_historical_deploy_b2.sql' => '7ec6d25a498e084756dc636e1354e81bbeb39391a0b0ce2670028f231848a90f',
    '287_queue_core_readiness_observability_b2.sql' => '0244b04e40834d91cfa5a5251cc72c927c568538cd576be032f2347816e27b4e',
    '288_queue_core_readiness_authority_b2_1.sql' => 'da6bfc5ab350552d3f96eb149301083162a9b384e2aacddafac6e617b31c2002',
    '289_queue_core_webhook_lifecycle_b2_1.sql' => '7a5bf71ed876a479d139dee94dc71a3d6c034dd47e4ea424f3f5dbc6d2f24be0',
    '290_queue_core_sales_dependency_graph_b2_1.sql' => 'df2b3e87e40b05a2b4db14f2ca260aa00f0d779e2f8dd7e578beb97bf252cb7a',
    '291_queue_core_release_health_capacity_b2_1.sql' => 'cecd56bb45e2fb93b7f9ba8ba8c9d03781bf39f0c89be05b950d8a6426d53223',
    '292_queue_core_authoritative_convergence_b2_1.sql' => '63a77b1c5120894eae583fc98c63a34533d25a06927c7af40d96c37a16ab96c1',
    '293_queue_core_runtime_profile_defaults_b2_1.sql' => 'fca26033511589bebad92ff07af9df468bddd60952029f36c4d2d72f18652e94',
];

$fail = static function (string $message): never {
    throw new RuntimeException($message);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

/** @return array{host:string,port:int,dbname:string} */
$parseDsn = static function (string $dsn) use ($fail): array {
    if (!str_starts_with(strtolower($dsn), 'mysql:')) {
        $fail('dsn_not_mysql');
    }
    $values = [];
    foreach (explode(';', substr($dsn, 6)) as $part) {
        if (!str_contains($part, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $part, 2));
        $values[strtolower($key)] = $value;
    }
    return [
        'host' => (string) ($values['host'] ?? ''),
        'port' => (int) ($values['port'] ?? 0),
        'dbname' => (string) ($values['dbname'] ?? ''),
    ];
};

/** @return array<string,array{rows:int,checksum:string}> */
$businessSnapshot = static function (PDO $pdo): array {
    $technicalExact = [
        'app_settings' => true,
        'app_versions' => true,
        'schema_migrations' => true,
        'system_update_locks' => true,
        'system_update_migrations' => true,
        'system_update_migration_events' => true,
        'system_update_migration_replacements' => true,
    ];
    $tables = $pdo->query(
        "SELECT table_name
         FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_type='BASE TABLE'
         ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];
    foreach ($tables as $rawTable) {
        $table = (string) $rawTable;
        if (
            isset($technicalExact[$table])
            || str_starts_with($table, 'queue_core_')
            || $table === 'queue_engine_control'
        ) {
            continue;
        }
        $identifier = '`' . str_replace('`', '``', $table) . '`';
        $rows = (int) $pdo->query("SELECT COUNT(*) FROM {$identifier}")->fetchColumn();
        $checksumRow = $pdo->query("CHECKSUM TABLE {$identifier}")->fetch(PDO::FETCH_ASSOC);
        $checksum = is_array($checksumRow) && $checksumRow['Checksum'] !== null
            ? (string) $checksumRow['Checksum']
            : 'unsupported';
        $snapshot[$table] = ['rows' => $rows, 'checksum' => $checksum];
    }
    return $snapshot;
};

$installedVersion = static function (PDO $pdo): string {
    $statement = $pdo->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
    );
    return trim((string) $statement->fetchColumn());
};

$assertMigrationAuthority = static function (PDO $pdo, array $expected) use ($assert): void {
    $names = array_keys($expected);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $statement = $pdo->prepare(
        "SELECT version,COUNT(*) occurrences
         FROM schema_migrations
         WHERE version IN ({$placeholders})
         GROUP BY version
         ORDER BY version"
    );
    $statement->execute($names);
    $actual = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $actual[(string) $row['version']] = (int) $row['occurrences'];
    }
    $assert(array_keys($actual) === $names, 'migration_set_incomplete');
    $assert(array_filter($actual, static fn (int $count): bool => $count !== 1) === [], 'migration_not_once');
    $unexpected = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations
         WHERE CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED) > 293"
    )->fetchColumn();
    $assert($unexpected === 0, 'unexpected_post_293_migration');
};

/** @return array{target_history:?array<string,mixed>} */
$metadataSnapshot = static function (PDO $pdo): array {
    $statement = $pdo->prepare('SELECT id,version,notes,installed_at FROM app_versions WHERE version=? LIMIT 1');
    $statement->execute([TARGET_VERSION_2361]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return ['target_history' => is_array($row) ? $row : null];
};

$rollbackMetadata = static function (PDO $pdo, array $snapshot) use ($installedVersion, $assert): void {
    $pdo->beginTransaction();
    try {
        $locked = trim((string) $pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key='app.version' FOR UPDATE"
        )->fetchColumn());
        $assert(in_array($locked, [
            SOURCE_VERSION_2361,
            MIGRATION_INTERMEDIATE_VERSION_2361,
            TARGET_VERSION_2361,
        ], true), 'rollback_unknown_version');
        $update = $pdo->prepare(
            "UPDATE app_settings
             SET setting_value=?,is_encrypted=0,setting_group='app'
             WHERE setting_key='app.version'"
        );
        $update->execute([SOURCE_VERSION_2361]);
        if ($snapshot['target_history'] === null) {
            $delete = $pdo->prepare('DELETE FROM app_versions WHERE version=?');
            $delete->execute([TARGET_VERSION_2361]);
        } else {
            $history = $snapshot['target_history'];
            $restore = $pdo->prepare(
                'UPDATE app_versions SET notes=?,installed_at=? WHERE id=? AND version=?'
            );
            $restore->execute([
                $history['notes'],
                $history['installed_at'],
                $history['id'],
                TARGET_VERSION_2361,
            ]);
            $assert($restore->rowCount() === 1, 'target_history_restore_failed');
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    $assert($installedVersion($pdo) === SOURCE_VERSION_2361, 'metadata_rollback_failed');
};

$promoteMetadata = static function (PDO $pdo, array $expected) use (
    $assert,
    $assertMigrationAuthority,
    $installedVersion
): void {
    $pdo->beginTransaction();
    try {
        $lockOwner = $pdo->query(
            "SELECT IS_USED_LOCK('" . EXTERNAL_CUTOVER_LOCK_2361 . "')=CONNECTION_ID()"
        )->fetchColumn();
        $assert((int) $lockOwner === 1, 'external_cutover_lock_not_owned');
        $current = trim((string) $pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key='app.version' FOR UPDATE"
        )->fetchColumn());
        $assert(in_array($current, [SOURCE_VERSION_2361, MIGRATION_INTERMEDIATE_VERSION_2361], true), 'promotion_wrong_source_version');
        $assertMigrationAuthority($pdo, $expected);
        $update = $pdo->prepare(
            "UPDATE app_settings
             SET setting_value=?,is_encrypted=0,setting_group='app'
             WHERE setting_key='app.version'"
        );
        $update->execute([TARGET_VERSION_2361]);
        $history = $pdo->prepare(
            'INSERT INTO app_versions(version,notes,installed_at)
             VALUES (?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=VALUES(installed_at)'
        );
        $history->execute([
            TARGET_VERSION_2361,
            'External managed release cutover rehearsal.',
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    $assert($installedVersion($pdo) === TARGET_VERSION_2361, 'metadata_promotion_failed');
};

$restoreMarker = static function (string $path, string $contents): void {
    $temporary = $path . '.rollback-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('marker_rollback_failed');
    }
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            $removeTree($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
};

$dsn = trim((string) getenv('ERP_2361_REHEARSAL_DSN'));
$user = (string) getenv('ERP_2361_REHEARSAL_USER');
$pass = (string) getenv('ERP_2361_REHEARSAL_PASS');
$ack = (string) getenv('ERP_2361_REHEARSAL_ACK');

if ($ack !== 'DISPOSABLE_CLONE_SCHEMA_279') {
    fwrite(STDERR, "ERROR rehearsal_ack_required\n");
    exit(2);
}
$dsnParts = $parseDsn($dsn);
if (
    !in_array(strtolower($dsnParts['host']), ['127.0.0.1', 'localhost'], true)
    || $dsnParts['port'] < 1024
    || $dsnParts['port'] === 3306
    || $dsnParts['dbname'] === ''
) {
    fwrite(STDERR, "ERROR disposable_local_nonstandard_port_required\n");
    exit(2);
}

foreach ($expectedMigrations as $migration => $expectedHash) {
    $path = $root . '/database/migrations/' . $migration;
    $assert(is_file($path), 'migration_missing:' . $migration);
    $actualHash = hash_file('sha256', $path);
    $assert($actualHash === $expectedHash, 'migration_hash_mismatch:' . $migration . ':' . (string) $actualHash);
}
$versionServiceSource = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$markerServiceSource = (string) file_get_contents($root . '/app/Services/InstalledVersionMarkerService.php');
$authControllerSource = (string) file_get_contents($root . '/app/Controllers/AuthController.php');
$assert(
    str_contains($versionServiceSource, "SELECT setting_value FROM app_settings WHERE setting_key='app.version'")
    && strpos($versionServiceSource, 'SELECT setting_value FROM app_settings')
        < strpos($versionServiceSource, 'SELECT version FROM app_versions'),
    'app_version_primary_authority_changed'
);
$assert(
    str_contains($markerServiceSource, "hash_hmac('sha256'")
    && str_contains($markerServiceSource, "hash_equals"),
    'installed_marker_signature_contract_changed'
);
$assert(
    str_contains($authControllerSource, 'InstalledVersionMarkerService')
    && str_contains($authControllerSource, 'requiresUpdate(AppVersionService::fileVersion())'),
    'admin_login_marker_gate_changed'
);
$migration291 = (string) file_get_contents($root . '/database/migrations/291_queue_core_release_health_capacity_b2_1.sql');
$migration292 = (string) file_get_contents($root . '/database/migrations/292_queue_core_authoritative_convergence_b2_1.sql');
$migration293 = (string) file_get_contents($root . '/database/migrations/293_queue_core_runtime_profile_defaults_b2_1.sql');
$assert(
    str_contains($migration291, "VALUES ('app.version','2.36.0'")
    && str_contains($migration292, "VALUES ('app.version','2.36.0'")
    && !str_contains($migration293, 'app.version'),
    'migration_version_transition_contract_changed'
);
$post293 = array_values(array_filter(
    glob($root . '/database/migrations/*.sql') ?: [],
    static function (string $path): bool {
        $prefix = strstr(basename($path), '_', true);
        return is_string($prefix) && ctype_digit($prefix) && (int) $prefix > 293;
    }
));
$assert($post293 === [], 'new_migration_not_authorized');
$lastMigration = array_key_last($expectedMigrations);
$assert(is_string($lastMigration), 'last_migration_missing');

mkdir($sharedRoot . '/storage', 0770, true);
$rehearsalKey = bin2hex(random_bytes(32));
$_ENV['APP_KEY'] = $rehearsalKey;
putenv('APP_KEY=' . $rehearsalKey);

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);

$lockAcquired = false;
try {
    $server = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
    $assert(str_contains($server, 'mariadb'), 'mariadb_required');
    $assert($installedVersion($pdo) === SOURCE_VERSION_2361, 'source_app_version_not_2351');
    $appliedBefore = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $maxBefore = (int) $pdo->query(
        "SELECT MAX(CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED)) FROM schema_migrations"
    )->fetchColumn();
    $assert($appliedBefore === 279 && $maxBefore === 279, 'source_schema_not_279');
    $alreadyNew = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations
         WHERE CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED)>=280"
    )->fetchColumn();
    $assert($alreadyNew === 0, 'source_has_280_plus');

    $metadataBefore = $metadataSnapshot($pdo);
    $businessBefore = $businessSnapshot($pdo);
    $markerService = new InstalledVersionMarkerService();
    $assert($markerService->write(SOURCE_VERSION_2361, '279'), 'source_marker_create_failed');
    $markerPath = $sharedRoot . '/storage/installed-release.json';
    $markerBefore = (string) file_get_contents($markerPath);

    $lockAcquired = (int) $pdo->query(
        "SELECT GET_LOCK('" . EXTERNAL_CUTOVER_LOCK_2361 . "',0)"
    )->fetchColumn() === 1;
    $assert($lockAcquired, 'external_cutover_lock_failed');

    // Falla inyectada antes de migrar: no existe ningún cambio observable.
    $assert($installedVersion($pdo) === SOURCE_VERSION_2361, 'before_migration_version_changed');
    $assert($businessSnapshot($pdo) === $businessBefore, 'before_migration_business_changed');

    // Corte controlado a mitad de la secuencia. El runner conserva su propia
    // autoridad/lock; no se llama a ningún servicio del updater.
    $migrator = new Migrator($pdo, $root . '/database/migrations');
    $first = $migrator->run(7);
    $firstApplied = array_values(array_filter(
        $first,
        static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['applied', 'adopted'], true)
    ));
    $assert(count($firstApplied) === 7, 'mid_migration_boundary_not_286');
    $assert($installedVersion($pdo) === SOURCE_VERSION_2361, 'mid_migration_version_changed');
    $assert($businessSnapshot($pdo) === $businessBefore, 'mid_migration_business_changed');

    $remaining = $migrator->run();
    $remainingApplied = array_values(array_filter(
        $remaining,
        static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['applied', 'adopted'], true)
    ));
    $assert(count($remainingApplied) === 7, 'migration_resume_not_293');
    $assertMigrationAuthority($pdo, $expectedMigrations);
    $assert(
        $installedVersion($pdo) === MIGRATION_INTERMEDIATE_VERSION_2361,
        'migration_intermediate_version_not_2360'
    );
    $assert($businessSnapshot($pdo) === $businessBefore, 'migration_business_invariant_failed');

    // Falla antes de la transición técnica: schema293 se conserva, metadata
    // vuelve al snapshot 2.35.1 antes de abandonar mantenimiento.
    $rollbackMetadata($pdo, $metadataBefore);
    $assertMigrationAuthority($pdo, $expectedMigrations);
    $assert($businessSnapshot($pdo) === $businessBefore, 'pre_version_rollback_business_changed');

    // Falla de publish del marcador después del COMMIT de metadata.
    $promoteMetadata($pdo, $expectedMigrations);
    $_ENV['APP_KEY'] = '';
    putenv('APP_KEY=');
    $assert(!$markerService->write(TARGET_VERSION_2361, $lastMigration), 'marker_failure_not_injected');
    $rollbackMetadata($pdo, $metadataBefore);
    $assert(hash_equals(hash('sha256', $markerBefore), hash_file('sha256', $markerPath)), 'marker_changed_on_publish_failure');

    // Falla del switch/smoke después de metadata+marcador: rollback externo
    // restaura ambas autoridades sin revertir el esquema aditivo.
    $_ENV['APP_KEY'] = $rehearsalKey;
    putenv('APP_KEY=' . $rehearsalKey);
    $promoteMetadata($pdo, $expectedMigrations);
    $assert($markerService->write(TARGET_VERSION_2361, $lastMigration), 'target_marker_write_failed');
    $rollbackMetadata($pdo, $metadataBefore);
    $restoreMarker($markerPath, $markerBefore);
    $rolledMarker = $markerService->read();
    $assert(
        $rolledMarker['valid']
        && $rolledMarker['version'] === SOURCE_VERSION_2361
        && $rolledMarker['last_migration'] === '279',
        'runtime_switch_rollback_marker_failed'
    );
    $assertMigrationAuthority($pdo, $expectedMigrations);

    // Camino exitoso final.
    $promoteMetadata($pdo, $expectedMigrations);
    $assert($markerService->write(TARGET_VERSION_2361, $lastMigration), 'final_marker_write_failed');
    $finalMarker = $markerService->read();
    $assert(
        $finalMarker['valid']
        && $finalMarker['version'] === TARGET_VERSION_2361
        && $finalMarker['last_migration'] === $lastMigration,
        'final_marker_invalid'
    );
    $assert($installedVersion($pdo) === TARGET_VERSION_2361, 'final_app_version_not_2361');
    $assert($businessSnapshot($pdo) === $businessBefore, 'final_business_invariant_failed');

    $second = $migrator->run();
    $secondApplied = array_filter(
        $second,
        static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['applied', 'adopted'], true)
    );
    $assert($secondApplied === [], 'migrations_not_idempotent');
    $assert($installedVersion($pdo) === TARGET_VERSION_2361, 'idempotency_changed_version');
    $assert($businessSnapshot($pdo) === $businessBefore, 'idempotency_changed_business');

    echo 'PASS external_cutover_mariadb_2361'
        . ' tables_guarded=' . count($businessBefore)
        . ' migrations=14 failures=5 rollback=PASS' . PHP_EOL;
} finally {
    if ($lockAcquired) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('" . EXTERNAL_CUTOVER_LOCK_2361 . "')");
        } catch (Throwable) {
        }
    }
    $removeTree($temporaryRoot);
}
