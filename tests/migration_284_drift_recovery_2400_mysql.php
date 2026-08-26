<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Database;
use App\Services\Migrator;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');

if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: migration_284_drift_recovery_2400_mysql requires a disposable MariaDB DSN without dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$migration284 = '284_queue_core_sales_pipeline_b2.sql';
$migration300 = '300_manual_drain_sessions_2_40_0.sql';
$expected284Sha = '4c78be7c47ef607038478eb4403ffda123296b6455280bb606c391a318b5a763';
$database = 'erp_2400_mig284_recovery_' . bin2hex(random_bytes(5));
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp_2400_mig284_recovery_' . bin2hex(random_bytes(5));

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($target)) {
            $removeTree($target);
        } else {
            @unlink($target);
        }
    }
    @rmdir($path);
};

try {
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    Database::setConnection($pdo);

    $tempMigrations = $tempRoot . '/database/migrations';
    $tempResources = $tempRoot . '/resources';
    if (!mkdir($tempMigrations, 0777, true) && !is_dir($tempMigrations)) {
        throw new RuntimeException('temp_migration_directory_unavailable');
    }
    if (!mkdir($tempResources, 0777, true) && !is_dir($tempResources)) {
        throw new RuntimeException('temp_resource_directory_unavailable');
    }

    foreach ([$migration284, $migration300] as $migration) {
        $source = $root . '/database/migrations/' . $migration;
        $target = $tempMigrations . '/' . $migration;
        if (!copy($source, $target)) {
            throw new RuntimeException('temp_migration_copy_failed:' . $migration);
        }
    }
    if (!copy($root . '/resources/migration-replacements.json', $tempResources . '/migration-replacements.json')) {
        throw new RuntimeException('temp_replacement_policy_copy_failed');
    }

    $actual284Sha = hash_file('sha256', $tempMigrations . '/' . $migration284);
    $assert($actual284Sha === $expected284Sha, 'migration_284_authority_sha_mismatch:' . $actual284Sha);

    $policy = json_decode((string) file_get_contents($tempResources . '/migration-replacements.json'), true, 512, JSON_THROW_ON_ERROR);
    $replacements = is_array($policy['replacements'] ?? null) ? $policy['replacements'] : [];
    $has284Replacement = false;
    foreach ($replacements as $replacement) {
        if (($replacement['migration_key'] ?? null) === $migration284) {
            $has284Replacement = true;
            break;
        }
    }
    $assert(!$has284Replacement, 'migration_284_must_not_use_replacement_policy');

    $pdo->exec('CREATE TABLE schema_migrations (
        version VARCHAR(100) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE system_update_migrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration_key VARCHAR(180) NOT NULL,
        checksum_sha256 CHAR(64) NOT NULL,
        release_version VARCHAR(40) NULL,
        state VARCHAR(40) NOT NULL DEFAULT "pending",
        checkpoint_json LONGTEXT NULL,
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        safe_error_message VARCHAR(700) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_system_update_migration_key (migration_key),
        KEY idx_system_update_migrations_state (state,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $insertSchema = $pdo->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
    $insertSchema->execute([$migration284]);

    $insertMeta = $pdo->prepare(
        'INSERT INTO system_update_migrations
         (migration_key,checksum_sha256,release_version,state,attempts,finished_at,safe_error_message)
         VALUES (?,?,?,?,0,UTC_TIMESTAMP(),?)'
    );
    $insertMeta->execute([
        $migration284,
        $expected284Sha,
        '2.40.0',
        'drifted',
        'La migración aplicada 284_queue_core_sales_pipeline_b2.sql fue modificada. El archivo aplicado cambió después de su registro.',
    ]);

    $results = (new Migrator($pdo, $tempMigrations))->run();
    $byVersion = [];
    foreach ($results as $row) {
        $byVersion[(string) $row['version']] = (string) $row['status'];
    }

    $assert(($byVersion[$migration284] ?? null) === 'adopted', 'migration_284_previous_drift_not_adopted');
    $assert(($byVersion[$migration300] ?? null) === 'applied', 'migration_300_not_applied_after_284_adoption');

    $row284 = $pdo->query(
        'SELECT checksum_sha256,state,safe_error_message,attempts FROM system_update_migrations WHERE migration_key='
        . $pdo->quote($migration284)
    )->fetch();
    $assert(is_array($row284), 'migration_284_metadata_missing_after_run');
    $assert(($row284['state'] ?? null) === 'adopted', 'migration_284_metadata_state_not_adopted');
    $assert(($row284['checksum_sha256'] ?? null) === $expected284Sha, 'migration_284_checksum_was_not_preserved');
    $assert((int) ($row284['attempts'] ?? -1) === 0, 'migration_284_adoption_must_not_increment_attempts');
    $assert(str_contains((string) ($row284['safe_error_message'] ?? ''), 'No se ejecutó SQL'), 'migration_284_adoption_message_must_certify_no_sql');

    $sql284Started = (int) $pdo->query(
        'SELECT COUNT(*) FROM system_update_migration_events
         WHERE migration_key=' . $pdo->quote($migration284) . " AND stage='sql_execution_started'"
    )->fetchColumn();
    $assert($sql284Started === 0, 'migration_284_sql_execution_started_must_be_zero');

    $adoptEvent = (int) $pdo->query(
        'SELECT COUNT(*) FROM system_update_migration_events
         WHERE migration_key=' . $pdo->quote($migration284) . " AND stage='checksum_previous_drift_reconciled' AND status='adopted'"
    )->fetchColumn();
    $assert($adoptEvent >= 1, 'migration_284_previous_drift_reconcile_event_missing');

    $schema300 = (int) $pdo->query(
        'SELECT COUNT(*) FROM schema_migrations WHERE version=' . $pdo->quote($migration300)
    )->fetchColumn();
    $assert($schema300 === 1, 'migration_300_schema_marker_missing_or_duplicated');

    $table300 = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name='manual_drain_sessions'"
    )->fetchColumn();
    $assert($table300 === 1, 'manual_drain_sessions_not_created_by_300');

    fwrite(
        STDOUT,
        'MIGRATION_284_DRIFT_RECOVERY_2400_MYSQL=PASS'
        . ' checks=' . $checks
        . ' migration_284=adopted'
        . ' sql_284=0'
        . ' migration_300=applied_once'
        . ' migration_284_replacement_rule=absent'
        . PHP_EOL
    );
} finally {
    try {
        $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
    } catch (Throwable) {
    }
    $removeTree($tempRoot);
}
