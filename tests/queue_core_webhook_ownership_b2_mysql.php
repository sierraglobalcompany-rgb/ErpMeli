<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
}, true, true);

use App\Core\Database;
use App\QueueCore\QueueClaim;
use App\QueueCore\QueueCoreRepository;
use App\QueueCore\QueueJob;
use App\QueueCore\QueueExecutionContext;
use App\QueueCore\WebhookExactGateway;
use App\QueueCore\WebhookOrderExactHandler;
use App\QueueCore\WebhookProducer;
use App\QueueCore\WebhookSpoolLifecycleService;
use App\QueueCore\WebhookTriggerService;
use App\Services\QueueCoreRollbackService;

$dsn = getenv('QUEUE_CORE_TEST_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_CORE_TEST_DSN is required\n");
    exit(2);
}
$pdo = new PDO($dsn, getenv('QUEUE_CORE_TEST_USER') ?: '', getenv('QUEUE_CORE_TEST_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['queue_core_historical_receipts', 'queue_core_historical_checkpoints', 'queue_core_execution_leases',
    'queue_core_webhook_spool_items', 'queue_core_webhook_triggers', 'queue_core_events', 'queue_core_jobs',
    'queue_core_scheduler_state', 'queue_engine_control', 'meli_accounts', 'companies'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,status TINYINT NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE meli_accounts(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_user_id VARCHAR(80),status VARCHAR(40) NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO companies VALUES(1,1),(2,1),(3,1)");
$pdo->exec("INSERT INTO meli_accounts VALUES(11,1,'101','conectado'),(22,2,'202','connected'),(33,3,'303','conectado')");
$pdo->exec("CREATE TABLE queue_core_jobs(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,
 work_type VARCHAR(80) NOT NULL,resource_type VARCHAR(80) NOT NULL,resource_id VARCHAR(191),lane VARCHAR(40) NOT NULL,
 queue_domain VARCHAR(20) NOT NULL,priority INT NOT NULL,idempotency_key VARCHAR(191) NOT NULL,input_version VARCHAR(191) NOT NULL,
 state VARCHAR(30) NOT NULL,max_attempts INT NOT NULL,available_at DATETIME(3) NOT NULL,source VARCHAR(80) NOT NULL,source_ref VARCHAR(191),
 payload_json JSON NOT NULL,provenance_json JSON NOT NULL,dispatch_state VARCHAR(40) NOT NULL DEFAULT 'NOT_DISPATCHED',created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
 UNIQUE KEY uq_job(company_id,meli_account_id,work_type,idempotency_key,input_version)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE queue_core_events(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job_id BIGINT UNSIGNED NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,lane VARCHAR(40),event_type VARCHAR(40),event_count INT,resources_count INT) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE queue_core_scheduler_state(scheduler_key VARCHAR(32) PRIMARY KEY,cycle_position INT NOT NULL DEFAULT 0,generation BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB");
$pdo->exec("INSERT INTO queue_core_scheduler_state VALUES('default',0,0)");
$pdo->exec("CREATE TABLE queue_engine_control(control_key VARCHAR(32) PRIMARY KEY,active_engine VARCHAR(16) NOT NULL,readiness_mode ENUM('idle','preparing') NOT NULL DEFAULT 'idle',readiness_context_hash CHAR(64) NULL,generation BIGINT UNSIGNED NOT NULL,changed_at DATETIME(3) NOT NULL,changed_by VARCHAR(96) NOT NULL) ENGINE=InnoDB");
$pdo->exec("INSERT INTO queue_engine_control VALUES('primary','v4','idle',NULL,1,UTC_TIMESTAMP(3),'test')");
$migration = preg_replace('/^--.*$/m', '', (string) file_get_contents($root . '/database/migrations/285_queue_core_webhook_ownership_b2.sql'));
$pdo->exec((string) $migration);
$lifecycleMigration = preg_replace('/^--.*$/m', '', (string) file_get_contents($root . '/database/migrations/289_queue_core_webhook_lifecycle_b2_1.sql'));
$pdo->exec((string) $lifecycleMigration);

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
$validation = static fn (int $seller, string $type, string $id): array => [
    'user_id' => $seller,
    'classification' => ['resource_type' => $type, 'resource_id' => $id],
];
$triggers = new WebhookTriggerService($pdo);
for ($i = 0; $i < 100; $i++) {
    $assert($triggers->observe($validation(101, 'order', '9001'))['accepted'], 'duplicate observation failed');
}
$row = $pdo->query("SELECT * FROM queue_core_webhook_triggers WHERE resource_id='9001'")->fetch();
$assert((int) $pdo->query('SELECT COUNT(*) FROM queue_core_webhook_triggers')->fetchColumn() === 1, 'duplicates were not bounded');
$assert((int) $row['desired_watermark'] === 100 && (int) $row['occurrence_count'] === 100, 'duplicate watermark drifted');

$repository = new QueueCoreRepository($pdo);
$producer = new WebhookProducer($pdo, $repository, $triggers);
$first = $producer->schedule(100, null, false);
$row = $pdo->query("SELECT * FROM queue_core_webhook_triggers WHERE resource_id='9001'")->fetch();
$assert($first['enqueued'] === 1 && (int) $pdo->query('SELECT COUNT(*) FROM queue_core_jobs')->fetchColumn() === 1, 'bounded producer did not create exactly one job');
$assert((string) $row['state'] === 'inflight' && (int) $row['scheduled_watermark'] === 100, 'first watermark was not fenced');

$triggers->observe($validation(101, 'order', '9001'));
$assert((int) $pdo->query("SELECT desired_watermark FROM queue_core_webhook_triggers WHERE resource_id='9001'")->fetchColumn() === 101, 'inflight desired watermark was lost');
$jobId = (int) $row['inflight_job_id'];
$job = $repository->job($jobId);
$payload = json_decode((string) $job['payload_json'], true, 32, JSON_THROW_ON_ERROR);
$fake = new class implements WebhookExactGateway {
    public int $calls = 0;
    public function sync(int $companyId, int $meliAccountId, string $resourceType, string $resourceId, int $queueJobId): int
    {
        $this->calls++;
        if ([$companyId, $meliAccountId, $resourceType, $resourceId] !== [1, 11, 'order', '9001']) {
            throw new RuntimeException('cross-tenant handler scope');
        }
        return 77;
    }
};
$claim = new QueueClaim($jobId, 1, 11, 'webhook_order_exact', 'order', '9001', 'recovery', 50, 'running', 1, 5, 'test', 1, 'NOT_DISPATCHED', $payload, 'webhook_v4', null);
$result = (new WebhookOrderExactHandler($triggers, $fake))->handle($claim, new QueueExecutionContext(1, microtime(true) + 10, 'test'));
$assert($fake->calls === 1 && $result->outcome === 'completed', 'fake exact handler did not close one resource');
$pdo->prepare("UPDATE queue_core_jobs SET state='completed' WHERE id=?")
    ->execute([$jobId]);
$row = $pdo->query("SELECT * FROM queue_core_webhook_triggers WHERE resource_id='9001'")->fetch();
$assert((string) $row['state'] === 'pending' && (int) $row['completed_watermark'] === 100, 'inflight rerun was not preserved');
$second = $producer->schedule(100, null, false);
$assert($second['enqueued'] === 1 && (int) $pdo->query('SELECT COUNT(*) FROM queue_core_jobs')->fetchColumn() === 2, 'desired rerun did not create its exact next version');

// A later webhook observation must never attach to a GET that may already
// have crossed the transport boundary. FIFO serializes the distinct rerun.
$freshId = $repository->enqueue(new QueueJob(
    1, 11, 'order_exact', 'order', '9100', 'fresh_orders', 0,
    'order:9100', 'snapshot:9100', 'fresh_orders_discovery', 'order:9100',
    ['order_id' => '9100'], ['producer' => 'fresh_orders'], 5
));
$before = (int) $pdo->query('SELECT COUNT(*) FROM queue_core_jobs')->fetchColumn();
$triggers->observe($validation(101, 'order', '9100'));
$coalesced = $producer->schedule(100, null, false);
$attached = (int) $pdo->query(
    "SELECT inflight_job_id FROM queue_core_webhook_triggers WHERE resource_id='9100'"
)->fetchColumn();
$assert($attached !== $freshId, 'later webhook generation attached to an already active GET');
$assert((int) $pdo->query('SELECT COUNT(*) FROM queue_core_jobs')->fetchColumn() === $before + 1,
    'webhook generation did not retain its exact rerun');
$assert($coalesced['enqueued'] >= 1, 'distinct webhook generation was not reported as enqueued');

// A crash in materializing is reclaimable with the same spool identity.
$lifecycle = new WebhookSpoolLifecycleService($pdo);
$crashKey = hash('sha256', 'crash-spool-1');
$crashPayload = ['topic' => 'orders_v2', 'resource' => '/orders/9300', 'user_id' => 101];
$lifecycle->ensureReceived($crashKey, $crashPayload);
$assert($lifecycle->beginMaterialization($crashKey) === 'materializing', 'received spool was not claimed');
$pdo->prepare('UPDATE queue_core_webhook_spool_items SET materializing_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 11 MINUTE) WHERE spool_key=?')
    ->execute([$crashKey]);
$assert($lifecycle->beginMaterialization($crashKey) === 'materializing', 'stale materializing spool did not recover');
$lifecycle->returnToReceived($crashKey, 'simulated_crash');

// Rollback cannot report ready while an unresolved webhook remains, and a
// bounded replay can make the next invocation ready without remote HTTP.
$pdo->exec("CREATE TABLE queue_core_execution_leases(launcher VARCHAR(40),expires_at DATETIME(3)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE queue_core_historical_checkpoints(enabled TINYINT,state VARCHAR(30),generation BIGINT UNSIGNED,last_error_class VARCHAR(100)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE queue_core_historical_receipts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,closure_state VARCHAR(30)) ENGINE=InnoDB");
$rollbackCalls = 0;
$rollbackReplay = static function (int $limit) use ($pdo, &$rollbackCalls): array {
    $rollbackCalls++;
    if ($rollbackCalls === 1) {
        return ['replayed' => 0, 'errors' => 0, 'remaining' => 1];
    }
    $pdo->exec("UPDATE queue_core_webhook_spool_items SET lifecycle='archived',archived_at=UTC_TIMESTAMP(3) WHERE lifecycle IN ('received','materializing','materialized')");
    return ['replayed' => 1, 'errors' => 0, 'remaining' => 0];
};
$rollback = new QueueCoreRollbackService($pdo, static fn (): bool => true, $rollbackReplay);
$blocked = $rollback->prepare(1, 10);
$assert(!$blocked['ok'] && $blocked['webhooks_remaining'] === 1, 'rollback ignored unresolved webhook lifecycle');
$ready = $rollback->prepare((int) $blocked['generation'], 10);
$assert($ready['ok'] && $ready['webhooks_replayed'] === 1, 'rollback replay did not reach ready state');

$assert(!$triggers->observe($validation(999, 'order', '1'))['accepted'], 'unknown seller was not quarantinable');
$scope = $triggers->observe($validation(202, 'shipment', '7001'));
$assert($scope['company_id'] === 2 && $scope['account_id'] === 22, 'seller identity crossed tenant');
$pdo->exec("INSERT INTO meli_accounts VALUES(44,3,'202','conectado')");
$assert(!$triggers->observe($validation(202, 'pack', '8001'))['accepted'], 'ambiguous seller did not fail closed');

echo "PASS queue_core_webhook_ownership_b2_mysql\n";
