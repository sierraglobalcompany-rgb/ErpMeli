<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\QueueCore\QueueCoreCapacityService;
use App\QueueCore\QueueCoreHealthService;
use App\QueueCore\QueueCoreReleaseEvidenceService;
use App\QueueCore\QueueCoreRunLedger;
use App\Services\QueueCoreDeploymentGateService;

$dsn = (string) (getenv('QUEUE_CORE_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_CORE_TEST_DSN is required\n");
    exit(2);
}
$pdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_CORE_TEST_USER') ?: ''),
    (string) (getenv('QUEUE_CORE_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tables = [
    'queue_core_release_evidence', 'queue_core_health_snapshots', 'queue_core_readiness_receipts',
    'queue_core_webhook_triggers', 'queue_core_attempts', 'queue_core_runs',
    'queue_core_producer_checkpoints', 'queue_core_jobs', 'queue_engine_control',
    'meli_tokens', 'meli_accounts',
    'app_settings',
];
foreach ($tables as $table) {
    $pdo->exec('DROP TABLE IF EXISTS ' . $table);
}
$pdo->exec('CREATE TABLE meli_accounts(id BIGINT PRIMARY KEY,company_id BIGINT NOT NULL,status VARCHAR(30),last_error VARCHAR(255) NULL)');
$pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(191) PRIMARY KEY,setting_value TEXT,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NULL)');
$pdo->exec('CREATE TABLE meli_tokens(meli_account_id BIGINT PRIMARY KEY,expires_at DATETIME(3),refresh_version BIGINT NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE queue_core_producer_checkpoints(producer_key VARCHAR(80),company_id BIGINT,meli_account_id BIGINT,watermark_at DATETIME(3),next_due_at DATETIME(3),last_error_class VARCHAR(100),PRIMARY KEY(producer_key,company_id,meli_account_id))');
$pdo->exec("CREATE TABLE queue_core_jobs(id BIGINT PRIMARY KEY AUTO_INCREMENT,company_id BIGINT,meli_account_id BIGINT,queue_domain VARCHAR(20),state VARCHAR(30),lane VARCHAR(20),dispatch_state VARCHAR(40),created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3))");
$pdo->exec("CREATE TABLE queue_core_attempts(id BIGINT PRIMARY KEY AUTO_INCREMENT,run_id BIGINT NULL,job_id BIGINT NOT NULL,physical_http_calls INT NOT NULL DEFAULT 0,response_known_at DATETIME(3) NULL,resources_persisted INT NOT NULL DEFAULT 0,http_status INT NULL,started_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,source_closed_at DATETIME(3) NULL,outcome VARCHAR(40) NULL)");
$pdo->exec("CREATE TABLE queue_core_runs(id BIGINT PRIMARY KEY AUTO_INCREMENT,engine_generation BIGINT,launcher VARCHAR(40),worker_ref CHAR(64),status VARCHAR(30),close_reason VARCHAR(100),phase VARCHAR(60),jobs_claimed INT DEFAULT 0,physical_http_calls INT DEFAULT 0,known_responses INT DEFAULT 0,resources_persisted INT DEFAULT 0,started_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3),last_heartbeat_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3))");
$pdo->exec("CREATE TABLE queue_engine_control(control_key VARCHAR(30) PRIMARY KEY,active_engine VARCHAR(20),readiness_mode ENUM('idle','preparing') NOT NULL DEFAULT 'idle',readiness_context_hash CHAR(64) NULL,generation BIGINT,changed_at DATETIME(3),changed_by VARCHAR(100))");
$pdo->exec("INSERT INTO queue_engine_control VALUES('primary','disabled','idle',NULL,4,UTC_TIMESTAMP(3),'test')");
$pdo->exec("CREATE TABLE queue_core_webhook_triggers(id BIGINT PRIMARY KEY AUTO_INCREMENT,desired_watermark BIGINT,completed_watermark BIGINT,state VARCHAR(30),last_observed_at DATETIME(3))");
$pdo->exec("CREATE TABLE queue_core_readiness_receipts(id BIGINT PRIMARY KEY AUTO_INCREMENT,engine_generation BIGINT,receipt_type VARCHAR(30),company_id BIGINT NULL,meli_account_id BIGINT NULL,status VARCHAR(10),evidence_hash CHAR(64),metrics_json JSON,expires_at DATETIME(3),created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3))");
$pdo->exec("CREATE TABLE queue_core_health_snapshots(id BIGINT PRIMARY KEY AUTO_INCREMENT,engine_generation BIGINT,health_state VARCHAR(20),account_count INT,eligible_depth INT,waiting_oauth INT,waiting_dependency INT,review_depth INT,dead_depth INT,oldest_eligible_seconds INT NULL,freshness_lag_seconds INT NULL,reasons_json JSON,generated_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3))");
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/migrations/291_queue_core_release_health_capacity_b2_1.sql'));
$pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/migrations/293_queue_core_runtime_profile_defaults_b2_1.sql'));

$context = hash('sha256', 'b2.1-context');
$evidence = new QueueCoreReleaseEvidenceService($pdo);
$evidence->record(4, 'capacity', true, $context, ['margin' => 2.0], 3600);
$assert($evidence->requireLatest(4, 'capacity', $context)['ok'], 'Latest passing capacity evidence was rejected.');
$evidence->record(4, 'capacity', false, $context, ['reason' => 'regression'], 3600);
$assert(!$evidence->requireLatest(4, 'capacity', $context)['ok'], 'An older PASS survived a newer FAIL.');
$assert(!$evidence->requireLatest(4, 'capacity', hash('sha256', 'changed'))['ok'], 'Changed context accepted old evidence.');

$capacity = (new QueueCoreCapacityService())->calculate(0.1, 3.0, 1.0, 5.0, 3.0, 60, 45, 10, 3);
$capacity['measurement_known_responses'] = 3;
$capacity['measurement_resources_persisted'] = 1;
$capacity['measurement_window_minutes'] = 60;
$certified = $evidence->certifyCapacity(4, $context, $capacity, [
    'cadence_seconds' => 60, 'runtime_seconds' => 45, 'safe_close_seconds' => 10, 'max_remote_jobs' => 3,
], 120.0);
$assert($certified['ok'], 'Positive capacity margin did not certify.');
$failedCapacity = (new QueueCoreCapacityService())->calculate(2.0, 3.0, 1.0, 5.0, 3.0, 60, 45, 10, 3);
$failedCapacity['measurement_known_responses'] = 3;
$failedCapacity['measurement_resources_persisted'] = 1;
$failedCapacity['measurement_window_minutes'] = 60;
$assert(!$evidence->certifyCapacity(4, $context, $failedCapacity, [
    'cadence_seconds' => 60, 'runtime_seconds' => 45, 'safe_close_seconds' => 10, 'max_remote_jobs' => 3,
], null)['ok'], 'Insufficient sustainable capacity certified.');

$backupFixture = tempnam(sys_get_temp_dir(), 'b21-backup-') . '.sql.gz';
$backupSql = "CREATE TABLE `meli_accounts` (`id` BIGINT);\n"
    . "CREATE TABLE `meli_orders` (`id` BIGINT);\n"
    . "CREATE TABLE `schema_migrations` (`version` VARCHAR(100));\n"
    . "INSERT INTO `meli_accounts` VALUES (1);\n";
file_put_contents($backupFixture, gzencode($backupSql, 6));
$backupHash = hash_file('sha256', $backupFixture);
$assert(is_string($backupHash) && $evidence->verifyBackup($backupFixture, $backupHash)['ok'], 'Structured SQL backup was rejected.');
$invalidBackup = tempnam(sys_get_temp_dir(), 'b21-invalid-');
file_put_contents($invalidBackup, 'not a database backup');
$invalidHash = hash_file('sha256', $invalidBackup);
$assert(is_string($invalidHash) && !$evidence->verifyBackup($invalidBackup, $invalidHash)['ok'], 'Arbitrary file was accepted as backup evidence.');
@unlink($backupFixture);@unlink($invalidBackup);

$pdo->exec("INSERT INTO meli_accounts VALUES(1,10,'connected',NULL)");
$pdo->exec("INSERT INTO meli_tokens VALUES(1,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),2)");
$pdo->exec("INSERT INTO queue_core_producer_checkpoints VALUES('fresh_orders',10,1,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE),UTC_TIMESTAMP(3),NULL)");
$pdo->exec("INSERT INTO queue_core_jobs(company_id,meli_account_id,queue_domain,state,lane,dispatch_state) VALUES(10,1,'operational','review','remote','DISPATCHED_RESULT_KNOWN')");
$pdo->exec("INSERT INTO queue_core_webhook_triggers(desired_watermark,completed_watermark,state,last_observed_at) VALUES(2,1,'pending',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE))");
$pdo->exec("INSERT INTO queue_core_readiness_receipts(engine_generation,receipt_type,status,evidence_hash,metrics_json,expires_at) VALUES(4,'preflight','fail',REPEAT('a',64),'{}',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR))");
$health = (new QueueCoreHealthService($pdo))->snapshot();
$assert($health['health'] === 'RED', 'Critical degraded state was reported as GREEN.');
$reasons = array_map('strval', (array) $health['reasons']);
$assert(in_array('blocking_review_present', $reasons, true), 'Review work was invisible to health.');
$assert(in_array('freshness_stale', $reasons, true), 'Freshness lag was invisible to health.');
$assert(in_array('webhook_observation_stalled', $reasons, true), 'Webhook lag was invisible to health.');
$assert(in_array('dependency_query_unknown', $reasons, true), 'Dependency query failure failed open.');
$assert(in_array('latest_readiness_failed', $reasons, true), 'Latest readiness failure was invisible.');
$assert(in_array('latest_release_evidence_failed', $reasons, true), 'Latest capacity failure was invisible.');

$ledger = new QueueCoreRunLedger($pdo);
$runId = $ledger->begin(4, 'test', 'worker-b21');
$pdo->prepare("INSERT INTO queue_core_attempts(run_id,job_id,physical_http_calls,response_known_at,resources_persisted,outcome) VALUES(?,?,1,UTC_TIMESTAMP(3),1,'completed')")
    ->execute([$runId, 1]);
$ledger->finish($runId, 'completed', 'metrics_renderer_failed', ['claimed' => 0]);
$claimed = (int) $pdo->query('SELECT jobs_claimed FROM queue_core_runs WHERE id=' . $runId)->fetchColumn();
$assert($claimed === 1, 'Run ledger lost measured claims when summary rendering failed.');
$pdo->prepare("INSERT INTO queue_core_attempts(run_id,job_id,physical_http_calls,response_known_at,resources_persisted,outcome) VALUES(?,?,1,UTC_TIMESTAMP(3),1,'completed'),(?,?,1,UTC_TIMESTAMP(3),1,'completed')")
    ->execute([$runId, 1, $runId, 1]);
$measured = $evidence->measuredCapacity(60, [
    'cadence_seconds' => 60, 'runtime_seconds' => 45, 'safe_close_seconds' => 10,
    'max_remote_jobs' => 3, 'safe_http_per_minute' => 3.0,
]);
$assert((int) $measured['measurement_known_responses'] >= 3, 'Capacity did not use measured known responses.');
$assert($evidence->certifyCapacity(4, $context, $measured, [
    'cadence_seconds' => 60, 'runtime_seconds' => 45, 'safe_close_seconds' => 10,
    'max_remote_jobs' => 3,
], null)['ok'], 'Measured positive capacity did not certify.');

$deploy = (new QueueCoreDeploymentGateService($pdo))->inspect();
$assert(in_array('backup_evidence_required', $deploy['issues'], true), 'Deployment gate accepted missing backup evidence.');

echo 'Queue Core B2.1 release/health: ' . $checks . " scenarios passed\n";
