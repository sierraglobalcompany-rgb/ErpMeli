<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$temporaryRoot = sys_get_temp_dir() . '/erp-qv4-clean-' . bin2hex(random_bytes(5));
mkdir($temporaryRoot, 0700, true);
define('ERP_INSTALLATION_ROOT', $temporaryRoot);
define('ERP_RELEASE_ROOT', dirname(__DIR__));

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Crypto;
use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanControlService;
use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanReadinessService;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanScheduler;
use App\QueueV4Clean\QueueV4CleanTransportContext;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiManualPauseException;
use App\Services\MeliEmergencyStopService;
use App\Services\MeliReadClientInterface;

$pdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);
putenv('APP_KEY=queue-v4-clean-local-test-only');
putenv('ML_WRITE_ENABLED=false');
putenv('CRON_V3_ENABLED=false');
putenv('CRON_V3_SHADOW_ENABLED=false');
file_put_contents($temporaryRoot . '/PAUSE_ERP_AUTOMATION', 'test');
file_put_contents($temporaryRoot . '/PAUSE_MELI_API', 'test');

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $tables = [
        'queue_v4_clean_readiness_accounts', 'queue_v4_clean_readiness_runs',
        'queue_v4_clean_attempts', 'queue_v4_clean_jobs', 'queue_v4_clean_runs',
        'queue_v4_clean_leases', 'queue_v4_clean_checkpoints', 'queue_v4_clean_control',
        'queue_core_dispatch_journal', 'queue_core_attempts', 'queue_core_jobs',
        'queue_engine_control', 'cron_v3_work_items', 'meli_tokens', 'meli_accounts',
        'schema_migrations', 'app_settings',
    ];
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec('CREATE TABLE schema_migrations (migration VARCHAR(191) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_settings (setting_key VARCHAR(191) NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL, is_encrypted TINYINT NOT NULL DEFAULT 0, setting_group VARCHAR(64) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO app_settings VALUES ('app.version','2.37.0',0,'system')");
    $pdo->exec('CREATE TABLE meli_accounts (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL, meli_user_id VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_tokens (meli_account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, access_token_encrypted TEXT NOT NULL, refresh_token_encrypted TEXT NOT NULL, expires_at DATETIME NOT NULL) ENGINE=InnoDB');

    // Deliberately dirty legacy state. Queue V4 Clean must never query it.
    $pdo->exec("CREATE TABLE queue_core_jobs (id BIGINT PRIMARY KEY,state VARCHAR(64),dispatch_state VARCHAR(64));");
    $pdo->exec("CREATE TABLE queue_core_attempts (id BIGINT PRIMARY KEY,job_id BIGINT,outcome VARCHAR(64));");
    $pdo->exec("CREATE TABLE queue_core_dispatch_journal (id BIGINT PRIMARY KEY,attempt_id BIGINT,state VARCHAR(64));");
    $pdo->exec("CREATE TABLE queue_engine_control (control_key VARCHAR(32) PRIMARY KEY,active_engine VARCHAR(32),readiness_mode VARCHAR(32),generation BIGINT);");
    $pdo->exec("CREATE TABLE cron_v3_work_items (id BIGINT PRIMARY KEY,status VARCHAR(32));");
    $pdo->exec("INSERT INTO queue_core_jobs VALUES (41001,'running','DISPATCHED_RESULT_UNCERTAIN')");
    $pdo->exec("INSERT INTO queue_core_attempts VALUES (51001,41001,'started')");
    $pdo->exec("INSERT INTO queue_core_dispatch_journal VALUES (61001,51001,'in_flight')");
    $pdo->exec("INSERT INTO queue_engine_control VALUES ('primary','disabled','preparing',9)");
    $pdo->exec("INSERT INTO cron_v3_work_items VALUES (71001,'running')");

    $encryptedAccess = Crypto::encrypt('local-access');
    $encryptedRefresh = Crypto::encrypt('local-refresh');
    $accountInsert = $pdo->prepare('INSERT INTO meli_accounts(id,company_id,meli_user_id,status) VALUES (?,?,?,"connected")');
    $tokenInsert = $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR))');
    foreach ([[1, 1, '1001'], [2, 4, '1002'], [3, 5, '1003']] as [$accountId, $companyId, $remoteId]) {
        $accountInsert->execute([$accountId, $companyId, $remoteId]);
        $tokenInsert->execute([$accountId, $encryptedAccess, $encryptedRefresh]);
    }

    $migration = file_get_contents(dirname(__DIR__) . '/database/migrations/294_queue_v4_clean_greenfield_2_37_0.sql');
    $assert(is_string($migration) && $migration !== '', 'migration missing');
    $pdo->exec($migration);
    $pdo->exec("INSERT INTO schema_migrations(migration) VALUES ('294_queue_v4_clean_greenfield_2_37_0.sql')");

    $repository = new QueueV4CleanRepository($pdo);
    $assert($repository->counts()['total'] === 0, 'new queue must start empty');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE dispatch_state='DISPATCHED_RESULT_UNCERTAIN'")->fetchColumn() === 1, 'legacy uncertain fixture absent');

    $outsideBlocked = false;
    try { (new MeliEmergencyStopService())->assertAllowed(); } catch (ApiManualPauseException) { $outsideBlocked = true; }
    $assert($outsideBlocked, 'physical API stop did not block ordinary traffic');
    $readinessTransport = QueueV4CleanTransportContext::runReadiness(1, 1, static function (): array {
        return ApiExecutionMetadataContext::run(
            ['source' => 'queue_v4_clean_readiness', 'company_id' => 1, 'account_id' => 1],
            static function (): array {
                $guard = new MeliEmergencyStopService();
                $guard->assertAllowed();
                ApiExecutionMetadataContext::withTransportMetadata(
                    ['transport_meli_account_id' => 1],
                    static fn () => $guard->assertTransportAllowed('GET', 'https://api.mercadolibre.com/users/me'),
                );
                $postBlocked = false;
                try {
                    ApiExecutionMetadataContext::withTransportMetadata(
                        ['transport_meli_account_id' => 1],
                        static fn () => $guard->assertTransportAllowed('POST', 'https://api.mercadolibre.com/users/me'),
                    );
                } catch (ApiManualPauseException) {
                    $postBlocked = true;
                }
                return ['get' => true, 'post_blocked' => $postBlocked];
            },
        );
    });
    $assert($readinessTransport === ['get' => true, 'post_blocked' => true], 'readiness transport boundary is not exact GET-only');

    $remoteCalls = 0;
    $factory = static function (int $accountId) use (&$remoteCalls): MeliReadClientInterface {
        return new class($accountId, $remoteCalls) implements MeliReadClientInterface {
            public function __construct(private int $accountId, private int &$calls) {}
            public function get(string $path, array $query = [], array $meta = []): array
            {
                if ($path !== '/users/me') {
                    throw new RuntimeException('unexpected endpoint');
                }
                $this->calls++;
                return ['id' => (string) (1000 + $this->accountId)];
            }
        };
    };
    $readiness = new QueueV4CleanReadinessService($pdo, $factory);
    $before = $readiness->snapshot();
    $assert($before['state'] === 'READY_TO_TEST', 'readiness not ready despite isolated legacy dirt');
    $assert($before['legacy_state_consulted'] === false, 'legacy state reported consulted');
    $certified = $readiness->certify(1);
    $assert($certified['state'] === 'CERTIFIED' && $certified['readiness_get_passed'] === 3, 'readiness did not certify 3/3');
    $assert($remoteCalls === 3 && $certified['queue_jobs_created'] === 0, 'readiness must perform three GETs and zero queue jobs');
    $assert($repository->counts()['total'] === 0, 'readiness created operational work');

    $control = new QueueV4CleanControlService($pdo);
    $activated = $control->activate(1);
    $assert($activated['state'] === 'ACTIVE' && $activated['scheduler_created'] === false, 'activation contract invalid');
    $producer = new QueueV4CleanProducer($pdo, $repository);
    $firstProduction = $producer->produce();
    $firstTotal = $repository->counts()['total'];
    $secondProduction = $producer->produce();
    $assert($firstProduction['created'] === 3 && $firstTotal === 3, 'fresh producer did not create bounded three-account frontier');
    $assert($secondProduction['created'] === 0 && $repository->counts()['total'] === 3, 'fresh producer is not idempotent inside cadence');
    $assert($firstProduction['historical_used'] === false, 'historical importer was used');

    $seen = [];
    $worker = new QueueV4CleanWorker(
        $pdo,
        $repository,
        null,
        null,
        static function (array $job) use (&$seen): void { $seen[] = (int) $job['id']; },
    );
    $result = $worker->run('test', 3, 10);
    $assert($result['claimed'] === 3 && $result['completed'] === 3, 'FIFO worker did not complete three jobs');
    $sorted = $seen; sort($sorted, SORT_NUMERIC);
    $assert($seen === $sorted, 'FIFO order is not monotonically increasing by id');

    // Retry/wait, review, dead and restart are all local and bounded.
    $retryId = $repository->enqueue(1, 1, 'order_exact', '9001', 'retry:9001', ['order_id' => '9001'], 2);
    $tries = 0;
    $retryWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function () use (&$tries): void {
        $tries++;
        if ($tries === 1) throw new RuntimeException('transient_fixture');
    });
    $retryWorker->run('test', 1, 10);
    $assert((string) $pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$retryId}")->fetchColumn() === 'waiting', 'safe retry did not leave FIFO');
    $pdo->exec("UPDATE queue_v4_clean_jobs SET available_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id={$retryId}");
    $retryWorker->run('test', 1, 10);
    $assert((string) $pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$retryId}")->fetchColumn() === 'completed', 'worker restart did not complete retry');

    $reviewId = $repository->enqueue(1, 1, 'order_exact', '9002', 'review:9002', ['order_id' => '9002'], 1);
    (new QueueV4CleanWorker($pdo, $repository, null, null, static fn () => throw new RuntimeException('retry_exhausted')))->run('test', 1, 10);
    $assert((string) $pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$reviewId}")->fetchColumn() === 'review', 'exhausted job not moved to review');
    $deadId = $repository->enqueue(1, 1, 'order_exact', null, 'dead:invalid', [], 3);
    (new QueueV4CleanWorker($pdo, $repository, null, null, static fn () => throw new RuntimeException('queue_v4_clean_payload_fixture')))->run('test', 1, 10);
    $assert((string) $pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$deadId}")->fetchColumn() === 'dead', 'invalid payload not moved to dead');

    $tenantBlocked = false;
    try {
        $repository->enqueue(4, 1, 'order_exact', '9999', 'tenant:mismatch', ['order_id' => '9999']);
    } catch (RuntimeException $error) {
        $tenantBlocked = $error->getMessage() === 'queue_v4_clean_tenant_mismatch';
    }
    $assert($tenantBlocked, 'tenant mismatch was accepted');

    $leaseId = $repository->enqueue(4, 2, 'order_exact', '9010', 'lease:9010', ['order_id' => '9010']);
    $runId = $repository->beginRun('test', 'lease-fixture');
    $claim = $repository->claim($runId, 'lease-fixture', 10);
    $assert((int) ($claim['id'] ?? 0) === $leaseId, 'lease fixture claim mismatch');
    $pdo->exec("UPDATE queue_v4_clean_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id={$leaseId}");
    $assert($repository->expireLeases() === 1, 'expired lease was not recovered');
    $assert((string) $pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$leaseId}")->fetchColumn() === 'ready', 'expired safe GET did not return to FIFO');
    $repository->finishRun($runId, 'stopped');

    $control->stop(1);
    $assert((new QueueV4CleanWorker($pdo, $repository, null, null, static fn () => null))->run('test')['claimed'] === 0, 'stopped engine claimed work');
    $control->activate(1);
    $assert($repository->control()['engine_state'] === 'ACTIVE', 'certified engine could not restart');

    // Local scheduler invocation: explicitly authorize the local fixture, no due work, no HTTP.
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='completed',completed_at=UTC_TIMESTAMP(3) WHERE state='ready'");
    $pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR)");
    $pdo->exec("UPDATE queue_v4_clean_control SET scheduler_enabled=1 WHERE control_key='primary'");
    unlink($temporaryRoot . '/PAUSE_ERP_AUTOMATION');
    $scheduler = (new QueueV4CleanScheduler($pdo))->run(3, 5);
    $assert($scheduler['status'] === 'completed' && ($scheduler['worker']['claimed'] ?? -1) === 0, 'local scheduler invocation failed');
    $assert($remoteCalls === 3, 'local engine test performed unexpected HTTP');

    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_core_jobs')->fetchColumn() === 1, 'legacy job was mutated');
    $assert((int) $pdo->query('SELECT generation FROM queue_engine_control')->fetchColumn() === 9, 'legacy generation was mutated');
    echo json_encode([
        'ok' => true,
        'checks' => $checks,
        'legacy_uncertain_present' => true,
        'legacy_uncertain_blocks_new_v4' => false,
        'readiness' => 'CERTIFIED',
        'oauth' => '3/3',
        'read_only_get' => '3/3',
        'new_queue_initial_jobs' => 0,
        'fresh_producer' => 'PASS',
        'fifo' => 'PASS',
        'tenant_isolation' => 'PASS',
        'meli_business_writes' => 0,
        'raw_storage_touched' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    @unlink($temporaryRoot . '/PAUSE_ERP_AUTOMATION');
    @unlink($temporaryRoot . '/PAUSE_MELI_API');
    @rmdir($temporaryRoot);
}
