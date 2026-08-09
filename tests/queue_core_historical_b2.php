<?php

declare(strict_types=1);

use App\QueueCore\HistoricalAdmissionPolicy;
use App\QueueCore\HistoricalBacklogImporter;
use App\QueueCore\HistoricalBacklogSourceRegistry;
use App\QueueCore\HistoricalSourceClosureService;
use App\QueueCore\LegacyWorkClassifier;
use App\QueueCore\QueueCoreRepository;
use App\QueueCore\QueueJob;
use App\Services\QueueCoreDeploymentGateService;
use App\Services\QueueCoreRollbackService;

require dirname(__DIR__) . '/bootstrap.php';

$dsn = (string) (getenv('QUEUE_CORE_TEST_DSN') ?: '');
$user = (string) (getenv('QUEUE_CORE_TEST_USER') ?: '');
$pass = (string) (getenv('QUEUE_CORE_TEST_PASS') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_CORE_TEST_DSN is required\n");
    exit(2);
}
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone='+00:00'");

$split = static function (string $sql): array {
    $statements = [];
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        $statement = preg_replace('/^--[^\n]*\n(?:--[^\n]*\n)*/', '', $statement) ?? $statement;
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }
    return $statements;
};
$apply = static function (PDO $pdo, string $file) use ($split): void {
    $sql = file_get_contents($file);
    if (!is_string($sql)) {
        throw new RuntimeException('Migration missing: ' . basename($file));
    }
    foreach ($split($sql) as $statement) {
        $pdo->exec($statement);
    }
};

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'queue_core_historical_reviews', 'queue_core_historical_receipts', 'queue_core_historical_checkpoints',
    'queue_core_release_evidence', 'queue_core_readiness_capture_items', 'queue_core_readiness_captures',
    'queue_core_health_snapshots', 'queue_core_readiness_receipts', 'queue_core_runs',
    'queue_core_feature_flags', 'queue_core_webhook_spool_items', 'queue_core_webhook_triggers',
    'queue_core_capability_edges', 'queue_core_capability_dependencies',
    'queue_core_pending_capabilities', 'queue_core_dispatch_journal', 'queue_core_attempts',
    'queue_core_events', 'queue_core_jobs', 'queue_core_producer_checkpoints',
    'queue_core_scheduler_state', 'queue_core_execution_leases', 'queue_engine_control',
    'meli_notification_work_items', 'meli_orders', 'meli_accounts', 'app_settings', 'schema_migrations',
] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec('CREATE TABLE meli_accounts (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(40) NOT NULL
) ENGINE=InnoDB');
$pdo->exec("INSERT INTO meli_accounts VALUES (1,1,'connected'),(2,2,'connected')");
$pdo->exec('CREATE TABLE meli_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(80) NOT NULL,
    synced_at DATETIME NULL,
    UNIQUE KEY uq_historical_order (meli_account_id,external_order_id)
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE meli_notification_work_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    resource_type VARCHAR(40) NOT NULL,
    remote_resource_id VARCHAR(100) NOT NULL,
    latest_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL,
    next_run_at DATETIME(3) NULL,
    last_result VARCHAR(100) NULL,
    completed_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    KEY idx_notification_scope (meli_account_id,status,id)
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE schema_migrations (
    version VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE app_settings (
    setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
    setting_value LONGTEXT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    setting_group VARCHAR(80) NOT NULL DEFAULT "general"
) ENGINE=InnoDB');

$root = dirname(__DIR__);
foreach ([
    '280_queue_core_cron_v4_phase_b1.sql',
    '281_queue_core_reaudit1_fifo_fencing.sql',
    '282_queue_core_architecture_closeout_b1_2.sql',
    '283_queue_engine_control_oauth_supervisor_b1_4.sql',
    '284_queue_core_sales_pipeline_b2.sql',
    '285_queue_core_webhook_ownership_b2.sql',
] as $migration) {
    $apply($pdo, $root . '/database/migrations/' . $migration);
    $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute([$migration]);
}

// Simulate interruption after the first DDL statement, then resume the same migration.
$migration286 = $root . '/database/migrations/286_queue_core_historical_deploy_b2.sql';
$sql286 = (string) file_get_contents($migration286);
$statements286 = $split($sql286);
$pdo->exec($statements286[0]);
$apply($pdo, $migration286);
$apply($pdo, $migration286);
$pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute(['286_queue_core_historical_deploy_b2.sql']);
$apply($pdo, $root . '/database/migrations/287_queue_core_readiness_observability_b2.sql');
$apply($pdo, $root . '/database/migrations/287_queue_core_readiness_observability_b2.sql');
$pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute(['287_queue_core_readiness_observability_b2.sql']);
foreach ([
    '288_queue_core_readiness_authority_b2_1.sql',
    '289_queue_core_webhook_lifecycle_b2_1.sql',
    '290_queue_core_sales_dependency_graph_b2_1.sql',
    '291_queue_core_release_health_capacity_b2_1.sql',
    '292_queue_core_authoritative_convergence_b2_1.sql',
    '293_queue_core_runtime_profile_defaults_b2_1.sql',
] as $migration) {
    $apply($pdo, $root . '/database/migrations/' . $migration);
    $apply($pdo, $root . '/database/migrations/' . $migration);
    $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute([$migration]);
}

$results = [];
$scenario = static function (string $name, callable $test) use (&$results): void {
    try {
        $test();
        $results[$name] = true;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        $results[$name] = false;
        fwrite(STDERR, "FAIL {$name} {$error->getMessage()}\n");
    }
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$reset = static function () use ($pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['queue_core_historical_reviews', 'queue_core_historical_receipts', 'queue_core_historical_checkpoints',
        'queue_core_dispatch_journal', 'queue_core_attempts', 'queue_core_events', 'queue_core_jobs',
        'queue_core_producer_checkpoints',
        'meli_notification_work_items'] as $table) {
        $pdo->exec("TRUNCATE TABLE `$table`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("UPDATE queue_core_scheduler_state SET cycle_position=0,generation=0 WHERE scheduler_key='default'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4',generation=0,changed_by='test'");
    $pdo->exec("UPDATE queue_core_execution_leases SET launcher=NULL,owner_token=NULL,expires_at=NULL,generation=0");
    $pdo->exec("UPDATE queue_core_feature_flags SET enabled=1,hard_cap=10 WHERE feature_key='historical_importer'");
    $pdo->exec("INSERT INTO queue_core_producer_checkpoints
        (producer_key,company_id,meli_account_id,watermark_at,next_due_at,generation)
        VALUES ('fresh_orders',1,1,UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE),0),
               ('fresh_orders',2,2,UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE),0)");
};
$importer = static function (int $cap = 10) use ($pdo): HistoricalBacklogImporter {
    return new HistoricalBacklogImporter(
        $pdo,
        new QueueCoreRepository($pdo),
        new HistoricalBacklogSourceRegistry(),
        new HistoricalAdmissionPolicy($cap),
    );
};
$insertSource = static function (int $account, string $type, string $remote, string $status = 'pending', int $event = 1) use ($pdo): int {
    $statement = $pdo->prepare(
        'INSERT INTO meli_notification_work_items
         (meli_account_id,resource_type,remote_resource_id,latest_event_id,status,next_run_at)
         VALUES (?,?,?,?,?,UTC_TIMESTAMP(3))'
    );
    $statement->execute([$account, $type, $remote, $event, $status]);
    return (int) $pdo->lastInsertId();
};

$scenario('migration_partial_resume_and_idempotency', static function () use ($assert, $pdo): void {
    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name IN
           ('queue_core_historical_checkpoints','queue_core_historical_receipts','queue_core_historical_reviews')"
    )->fetchColumn();
    $assert($count === 3, 'historical migration did not resume idempotently');
});

$scenario('disabled_by_default_and_scope_closed', static function () use ($reset, $insertSource, $importer, $assert, $pdo): void {
    $reset();
    $insertSource(1, 'order', '1001');
    $result = $importer()->run('notification_orders', 1, 1);
    $assert($result['status'] === 'disabled', 'historical import was not disabled by default');
    $blocked = false;
    try {
        $importer()->enable('notification_orders', 2, 1, 'test');
    } catch (RuntimeException) {
        $blocked = true;
    }
    $assert($blocked, 'cross-company source scope was accepted');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_core_jobs')->fetchColumn() === 0, 'disabled/scope failure created work');
});

$scenario('closed_registry_rejects_unknown', static function () use ($assert): void {
    $blocked = false;
    try {
        (new HistoricalBacklogSourceRegistry())->get('pack_exact');
    } catch (RuntimeException) {
        $blocked = true;
    }
    $assert($blocked, 'unknown or uncertified source entered the registry');
});

$scenario('poison_isolated_duplicate_free_and_capacity_bounded', static function () use ($reset, $insertSource, $importer, $assert, $pdo): void {
    $reset();
    $insertSource(1, 'shipment', '7001');
    $insertSource(1, 'order', 'bad-id');
    $insertSource(1, 'order', '1003', 'error');
    $validA = $insertSource(1, 'order', '1004', 'pending', 44);
    $insertSource(1, 'order', '1005', 'pending', 45);
    $insertSource(2, 'order', '9999');
    $service = $importer(2);
    $service->enable('notification_orders', 1, 1, 'test');

    // Pre-existing equivalent intent proves duplicates do not consume admission capacity.
    $inputVersion = hash('sha256', implode('|', ['notification_order', 44, '1004']));
    (new QueueCoreRepository($pdo))->enqueue(
        (new LegacyWorkClassifier(1))->notificationOrder(1, '1004', $validA, 44, $inputVersion)
    );
    $result = $service->run('notification_orders', 1, 1, 50);
    $assert($result['reviewed'] === 3, 'poison rows were not isolated in review');
    $assert($result['duplicates'] === 1, 'existing intent was not classified as duplicate');
    $assert($result['created'] === 1, 'duplicate consumed capacity or valid work was skipped');
    $assert($result['outstanding'] === 2, 'historical outstanding exceeded calculated cap');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_core_historical_reviews')->fetchColumn() === 3, 'review ledger is incomplete');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_core_jobs WHERE company_id=2 OR meli_account_id=2')->fetchColumn() === 0, 'tenant source crossed scope');
});

$scenario('high_water_excludes_late_arrivals', static function () use ($reset, $insertSource, $importer, $assert, $pdo): void {
    $reset();
    $insertSource(1, 'order', '2001');
    $service = $importer(10);
    $checkpoint = $service->enable('notification_orders', 1, 1, 'test');
    $late = $insertSource(1, 'order', '2002');
    $result = $service->run('notification_orders', 1, 1, 50);
    $assert($result['status'] === 'complete', 'bounded high-water did not complete');
    $assert($result['high_water_id'] === $checkpoint['high_water_id'], 'high-water moved during scan');
    $statement = $pdo->prepare('SELECT COUNT(*) FROM queue_core_historical_receipts WHERE source_id=?');
    $statement->execute([$late]);
    $assert((int) $statement->fetchColumn() === 0, 'late arrival leaked into current historical snapshot');
});

$scenario('two_importers_respect_global_cap', static function () use ($reset, $insertSource, $importer, $assert, $pdo, $dsn, $user, $pass): void {
    $reset();
    for ($i = 0; $i < 40; $i++) {
        $insertSource(1, 'order', (string) (3000 + $i), 'pending', 100 + $i);
    }
    $importer(10)->enable('notification_orders', 1, 1, 'test');
    $start = (int) floor(microtime(true) * 1000) + 400;
    $environment = array_merge($_ENV, [
        'QUEUE_CORE_TEST_DSN' => $dsn,
        'QUEUE_CORE_TEST_USER' => $user,
        'QUEUE_CORE_TEST_PASS' => $pass,
    ]);
    $processes = [];
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/historical_import_worker.php', (string) $start],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('historical worker did not start');
        }
        $processes[] = [$process, $pipes];
    }
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('historical worker failed: ' . $stderr . $stdout);
        }
    }
    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM queue_core_jobs
         WHERE lane='historical_backfill' AND state IN ('pending','claimed','running','retry_wait','waiting_oauth')"
    )->fetchColumn();
    $assert($count === 10, 'concurrent importers exceeded or underfilled global cap');
});

$scenario('known_completion_closes_and_rollback_restores', static function () use ($reset, $insertSource, $importer, $assert, $pdo): void {
    $reset();
    $sourceId = $insertSource(1, 'order', '4001', 'pending', 401);
    $service = $importer(10);
    $service->enable('notification_orders', 1, 1, 'test');
    $service->run('notification_orders', 1, 1, 50);
    $pdo->exec(
        "UPDATE queue_core_jobs SET state='completed',dispatch_state='DISPATCHED_RESULT_KNOWN',completed_at=UTC_TIMESTAMP(3)"
    );
    $closed = (new HistoricalSourceClosureService($pdo, new HistoricalBacklogSourceRegistry()))->closeCompleted();
    $assert($closed['closed'] === 1, 'known terminal result did not close source');
    $state = $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $sourceId)->fetchColumn();
    $assert($state === 'complete', 'legacy source was not closed');
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4',generation=0");
    $rollback = new QueueCoreRollbackService($pdo, static fn (): bool => true);
    $prepared = $rollback->prepare(0, 50);
    $assert($prepared['ok'] && $prepared['status'] === 'ready_for_code_rollback', 'rollback did not reach safe handoff');
    $state = $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $sourceId)->fetchColumn();
    $assert($state === 'pending', 'rollback did not restore source state');
    $assert($pdo->query("SELECT active_engine FROM queue_engine_control WHERE control_key='primary'")->fetchColumn() === 'disabled', 'rollback did not disable engine');
});

$scenario('rollback_blocks_uncertain_and_running', static function () use ($reset, $insertSource, $importer, $assert, $pdo): void {
    $reset();
    $insertSource(1, 'order', '5001');
    $service = $importer(10);
    $service->enable('notification_orders', 1, 1, 'test');
    $service->run('notification_orders', 1, 1, 50);
    $pdo->exec("UPDATE queue_core_jobs SET dispatch_state='DISPATCHED_RESULT_UNCERTAIN',state='review'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4',generation=0");
    $blocked = !(new QueueCoreRollbackService($pdo, static fn (): bool => true))->preflight()['ok'];
    $assert($blocked, 'rollback accepted uncertain remote evidence');
});

$scenario('deployment_gate_verifies_schema_and_backup', static function () use ($reset, $assert, $pdo): void {
    $reset();
    $pdo->exec("UPDATE queue_engine_control SET active_engine='disabled',generation=0");
    $pdo->exec("UPDATE queue_core_feature_flags SET enabled=0 WHERE feature_key='historical_importer'");
    putenv('ML_WRITE_ENABLED=false');
    $backup = tempnam(sys_get_temp_dir(), 'qc-b2-backup-');
    if (!is_string($backup)) {
        throw new RuntimeException('temporary backup could not be created');
    }
    file_put_contents($backup, "CREATE TABLE `meli_accounts` (`id` BIGINT);\n"
        . "CREATE TABLE `meli_orders` (`id` BIGINT);\n"
        . "CREATE TABLE `schema_migrations` (`version` VARCHAR(100));\n"
        . "INSERT INTO `meli_accounts` VALUES (1);\n");
    try {
        $sha = hash_file('sha256', $backup);
        $gate = (new QueueCoreDeploymentGateService($pdo))->inspect($backup, is_string($sha) ? $sha : '');
        $assert($gate['ok'], 'deployment gate rejected complete disabled schema: ' . implode(',', $gate['issues']));
    } finally {
        @unlink($backup);
    }
});

if (in_array(false, $results, true)) {
    exit(1);
}
fwrite(STDOUT, 'Queue Core historical B2: ' . count($results) . " scenarios passed\n");
