<?php

declare(strict_types=1);

/**
 * Opt-in destructive rehearsal for a disposable local MariaDB clone already
 * at schema 293 and app.version 2.35.1. The test applies zero migrations.
 *
 * Required environment:
 *   ERP_2362_REHEARSAL_ACK=DISPOSABLE_CLONE_SCHEMA_293
 *   ERP_2362_REHEARSAL_DSN=mysql:host=127.0.0.1;port=...;dbname=...;charset=utf8mb4
 *   ERP_2362_REHEARSAL_USER=...
 *   ERP_2362_REHEARSAL_PASS=...
 */

const EXTERNAL_CUTOVER_BASE_2362 = '75997f864b917c829d19f14e21cd7303d2685776';
const EXTERNAL_CUTOVER_LOCK_2362 = 'erp_meli_external_cutover_2_36_2';
const SOURCE_VERSION_2362 = '2.35.1';
const TARGET_VERSION_2362 = '2.36.2';

$root = dirname(__DIR__);
$fail = static function (string $message): never {
    throw new RuntimeException($message);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

set_exception_handler(static function (Throwable $error): void {
    $safe = preg_replace(
        '/(?i)(password|pass|app[_-]?key|access[_-]?token|refresh[_-]?token)\s*[:=]\s*\S+/',
        '$1=[REDACTED]',
        $error->getMessage(),
    ) ?? 'unknown_failure';
    fwrite(STDERR, 'FAIL external_cutover_mariadb_2362 ' . mb_substr($safe, 0, 500) . PHP_EOL);
    exit(1);
});

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

/** @return array{exit:int,stdout:string,stderr:string} */
$run = static function (array $command, string $cwd): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('git_authority_process_unavailable');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'exit' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
};

$migrationBlobs = [
    '280_queue_core_cron_v4_phase_b1.sql' => '032569e076b1b6e35bbb61a6f6fb2da7cd84aaa8',
    '281_queue_core_reaudit1_fifo_fencing.sql' => 'bd4e780bc6e36870310f43783b4e0ea3f780bc09',
    '282_queue_core_architecture_closeout_b1_2.sql' => '071dd12b32b2ac111b2511859c5ef5965868c3df',
    '283_queue_engine_control_oauth_supervisor_b1_4.sql' => 'dee778d450ab7ec04edb94e3f8c187a69548f5fc',
    '284_queue_core_sales_pipeline_b2.sql' => '02edfa92ed046d34de1c4e843f4ffa088afd8a23',
    '285_queue_core_webhook_ownership_b2.sql' => '2e134f0b8aed02843a6dffdf897a2efe3540cb54',
    '286_queue_core_historical_deploy_b2.sql' => '8a925db4a6081107bb1d6e4e8bab658616d2dd71',
    '287_queue_core_readiness_observability_b2.sql' => '7cc90ed921aff381f69de25a252fcfaea5c077b3',
    '288_queue_core_readiness_authority_b2_1.sql' => 'b13fdbd351b2a07fcd3183115978cecdd3c2df45',
    '289_queue_core_webhook_lifecycle_b2_1.sql' => 'd43690bacc7deeecca20befb918f875d73d5b151',
    '290_queue_core_sales_dependency_graph_b2_1.sql' => 'ba77dd69c1dbd6a9190eba8a1e8d5f1b18a4fea0',
    '291_queue_core_release_health_capacity_b2_1.sql' => 'cec79ffcca2f4687cf79c47c34107256851cf1c4',
    '292_queue_core_authoritative_convergence_b2_1.sql' => 'e3decbfc01889f3eebcdda0f58061b7ad34acb22',
    '293_queue_core_runtime_profile_defaults_b2_1.sql' => 'd15e244079c72003b379f6394d21e985c111e1ff',
];

// Raw Git bytes are the migration authority. Checkout line endings are never
// read here, which reproduces the corrected external-cutover verification.
foreach ($migrationBlobs as $migration => $expectedObject) {
    $path = 'database/migrations/' . $migration;
    $baseBytes = $run(['git', 'show', EXTERNAL_CUTOVER_BASE_2362 . ':' . $path], $root);
    $headBytes = $run(['git', 'show', 'HEAD:' . $path], $root);
    $headObject = $run(['git', 'rev-parse', 'HEAD:' . $path], $root);
    $baseObject = $run(['git', 'rev-parse', EXTERNAL_CUTOVER_BASE_2362 . ':' . $path], $root);
    $assert(
        $baseBytes['exit'] === 0 && $headBytes['exit'] === 0
        && hash_equals(hash('sha256', $baseBytes['stdout']), hash('sha256', $headBytes['stdout'])),
        'raw_git_migration_bytes_changed:' . $migration,
    );
    $assert(
        $headObject['exit'] === 0 && $baseObject['exit'] === 0
        && trim($baseObject['stdout']) === $expectedObject
        && trim($headObject['stdout']) === $expectedObject,
        'migration_git_blob_changed:' . $migration,
    );
}
$post293 = glob($root . '/database/migrations/*.sql') ?: [];
$post293 = array_filter($post293, static function (string $path): bool {
    $prefix = strstr(basename($path), '_', true);
    return is_string($prefix) && ctype_digit($prefix) && (int) $prefix > 293;
});
$assert($post293 === [], 'new_migration_not_authorized');

$dsn = trim((string) getenv('ERP_2362_REHEARSAL_DSN'));
$user = (string) getenv('ERP_2362_REHEARSAL_USER');
$pass = (string) getenv('ERP_2362_REHEARSAL_PASS');
$ack = (string) getenv('ERP_2362_REHEARSAL_ACK');
if ($ack !== 'DISPOSABLE_CLONE_SCHEMA_293') {
    fwrite(STDERR, "ERROR rehearsal_ack_required\n");
    exit(2);
}
$parts = $parseDsn($dsn);
if (
    !in_array(strtolower($parts['host']), ['127.0.0.1', 'localhost'], true)
    || $parts['port'] < 1024
    || $parts['port'] === 3306
    || $parts['dbname'] === ''
) {
    fwrite(STDERR, "ERROR disposable_local_nonstandard_port_required\n");
    exit(2);
}

$connect = static function () use ($dsn, $user, $pass): PDO {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
};

$installedVersion = static function (PDO $pdo): string {
    return trim((string) $pdo->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
    )->fetchColumn());
};

$assertSchema293 = static function (PDO $pdo) use ($assert, $migrationBlobs): void {
    $names = array_keys($migrationBlobs);
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $statement = $pdo->prepare(
        "SELECT version,COUNT(*) occurrences FROM schema_migrations
         WHERE version IN ({$placeholders}) GROUP BY version ORDER BY version"
    );
    $statement->execute($names);
    $actual = [];
    foreach ($statement->fetchAll() as $row) {
        $actual[(string) $row['version']] = (int) $row['occurrences'];
    }
    $assert(array_keys($actual) === $names, 'schema_280_293_incomplete');
    $assert(array_filter($actual, static fn (int $count): bool => $count !== 1) === [],
        'schema_280_293_not_exactly_once');
    $post = (int) $pdo->query(
        "SELECT COUNT(*) FROM schema_migrations
         WHERE CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED)>293"
    )->fetchColumn();
    $assert($post === 0, 'schema_has_post_293_migration');
};

/** @return array<string,array{rows:int,checksum:string}> */
$businessSnapshot = static function (PDO $pdo): array {
    $technical = [
        'app_settings' => true,
        'app_versions' => true,
        'schema_migrations' => true,
        'system_update_locks' => true,
        'system_update_migrations' => true,
        'system_update_migration_events' => true,
        'system_update_migration_replacements' => true,
    ];
    $tables = $pdo->query(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];
    foreach ($tables as $raw) {
        $table = (string) $raw;
        if (isset($technical[$table]) || str_starts_with($table, 'queue_core_') || $table === 'queue_engine_control') {
            continue;
        }
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $row = $pdo->query("CHECKSUM TABLE {$quoted}")->fetch();
        $snapshot[$table] = [
            'rows' => (int) $pdo->query("SELECT COUNT(*) FROM {$quoted}")->fetchColumn(),
            'checksum' => is_array($row) && $row['Checksum'] !== null ? (string) $row['Checksum'] : 'unsupported',
        ];
    }
    return $snapshot;
};

$lockIsHeld = static function (PDO $pdo) use ($assert): void {
    $statement = $pdo->query("SELECT IS_USED_LOCK('" . EXTERNAL_CUTOVER_LOCK_2362 . "')");
    $assert($statement->fetchColumn() !== null, 'external_cutover_lock_not_held');
};

$promote = static function (string $expected) use (
    $connect,
    $installedVersion,
    $assertSchema293,
    $lockIsHeld,
    $assert
): void {
    $pdo = $connect(); // fresh verified connection immediately before mutation
    $lockIsHeld($pdo);
    $assertSchema293($pdo);
    $pdo->beginTransaction();
    try {
        $current = trim((string) $pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key='app.version' FOR UPDATE"
        )->fetchColumn());
        $assert($current === $expected, 'promotion_wrong_source_version');
        $update = $pdo->prepare(
            "UPDATE app_settings SET setting_value=?,is_encrypted=0,setting_group='app'
             WHERE setting_key='app.version'"
        );
        $update->execute([TARGET_VERSION_2362]);
        $history = $pdo->prepare(
            'INSERT INTO app_versions(version,notes,installed_at) VALUES (?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE notes=VALUES(notes),installed_at=VALUES(installed_at)'
        );
        $history->execute([TARGET_VERSION_2362, 'External managed release 2.36.2 rehearsal.']);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    $assert($installedVersion($pdo) === TARGET_VERSION_2362, 'promotion_not_visible');
};

/** @param array<string,mixed>|null $targetHistory */
$rollback = static function (?array $targetHistory) use (
    $connect,
    $installedVersion,
    $assertSchema293,
    $lockIsHeld,
    $assert
): void {
    $pdo = $connect(); // never reuse the connection that waited on FPM/filesystem
    $lockIsHeld($pdo);
    $assertSchema293($pdo);
    $pdo->beginTransaction();
    try {
        $current = trim((string) $pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key='app.version' FOR UPDATE"
        )->fetchColumn());
        $assert($current === TARGET_VERSION_2362, 'rollback_wrong_source_version');
        $update = $pdo->prepare(
            "UPDATE app_settings SET setting_value=?,is_encrypted=0,setting_group='app'
             WHERE setting_key='app.version'"
        );
        $update->execute([SOURCE_VERSION_2362]);
        if ($targetHistory === null) {
            $delete = $pdo->prepare('DELETE FROM app_versions WHERE version=?');
            $delete->execute([TARGET_VERSION_2362]);
        } else {
            $restore = $pdo->prepare(
                'UPDATE app_versions SET notes=?,installed_at=? WHERE id=? AND version=?'
            );
            $restore->execute([
                $targetHistory['notes'],
                $targetHistory['installed_at'],
                $targetHistory['id'],
                TARGET_VERSION_2362,
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
    $assert($installedVersion($pdo) === SOURCE_VERSION_2362, 'rollback_not_visible');
};

$lock = $connect();
$lockAcquired = false;
$originalHistory = null;
try {
    $assert(str_contains(strtolower((string) $lock->query('SELECT VERSION()')->fetchColumn()), 'mariadb'),
        'mariadb_required');
    $assert($installedVersion($lock) === SOURCE_VERSION_2362, 'source_app_version_not_2351');
    $assertSchema293($lock);
    $originalStatement = $lock->prepare(
        'SELECT id,version,notes,installed_at FROM app_versions WHERE version=? LIMIT 1'
    );
    $originalStatement->execute([TARGET_VERSION_2362]);
    $row = $originalStatement->fetch();
    $originalHistory = is_array($row) ? $row : null;
    $businessBefore = $businessSnapshot($lock);
    $migrationRowsBefore = (int) $lock->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();

    $lockAcquired = (int) $lock->query(
        "SELECT GET_LOCK('" . EXTERNAL_CUTOVER_LOCK_2362 . "',0)"
    )->fetchColumn() === 1;
    $assert($lockAcquired, 'external_cutover_lock_failed');

    $promote(SOURCE_VERSION_2362);

    // Reproduce the 2.36.1 operational P2: the connection that waits during
    // runtime smoke expires. Rollback must succeed through a new connection.
    $expired = $connect();
    $expired->exec('SET SESSION wait_timeout=1');
    sleep(2);
    $expiredObserved = false;
    try {
        $expired->query('SELECT 1')->fetchColumn();
    } catch (PDOException) {
        $expiredObserved = true;
    }
    $assert($expiredObserved, 'connection_expiry_not_observed');
    unset($expired);

    $rollback($originalHistory);
    $verifyRollback = $connect();
    $assert($installedVersion($verifyRollback) === SOURCE_VERSION_2362,
        'expired_connection_rollback_failed');
    $assertSchema293($verifyRollback);
    $assert((int) $verifyRollback->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === $migrationRowsBefore,
        'migrations_were_applied');
    $assert($businessSnapshot($verifyRollback) === $businessBefore, 'rollback_business_state_changed');

    // Successful technical transition: direct 2.35.1 -> 2.36.2, zero SQL
    // migrations. Clean it up afterward so the disposable fixture is rerunnable.
    $promote(SOURCE_VERSION_2362);
    $verifySuccess = $connect();
    $assert($installedVersion($verifySuccess) === TARGET_VERSION_2362, 'final_app_version_not_2362');
    $assertSchema293($verifySuccess);
    $assert((int) $verifySuccess->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === $migrationRowsBefore,
        'successful_path_applied_migration');
    $assert($businessSnapshot($verifySuccess) === $businessBefore, 'successful_path_business_state_changed');

    $rollback($originalHistory);
    echo 'PASS external_cutover_mariadb_2362 schema=293 migrations=0 transition=2351_to_2362'
        . ' expired_connection_rollback=PASS remote_http=0 tables_guarded=' . count($businessBefore) . PHP_EOL;
} finally {
    if ($lockAcquired) {
        try {
            $lock->query("SELECT RELEASE_LOCK('" . EXTERNAL_CUTOVER_LOCK_2362 . "')");
        } catch (Throwable) {
        }
    }
}
