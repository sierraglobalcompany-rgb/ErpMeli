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
use App\QueueV4Clean\QueueV4CleanDatabaseContract;
use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanReadinessService;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanScheduler;
use App\QueueV4Clean\QueueV4CleanTransportContext;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiManualPauseException;
use App\Services\AppVersionService;
use App\Services\DirectUpdateMetadataPromotionService;
use App\Services\InstalledVersionMarkerService;
use App\Services\MeliEmergencyStopService;
use App\Services\MeliReadClientInterface;
use App\Services\Migrator;

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
    $migrationPath = (string) (getenv('QUEUE_V4_CANONICAL_MIGRATIONS') ?: '');
    if ($migrationPath === '' || !is_dir($migrationPath)) {
        throw new RuntimeException('canonical_migration_path_missing');
    }
    $migrationResults = (new Migrator($pdo, $migrationPath))->run();
    $assert(count($migrationResults) === 294, 'canonical migrations were not materialized');
    $assert((new Migrator($pdo, $migrationPath))->pendingCount() === 0, 'canonical migrations remain pending');
    $assert(glob($migrationPath . '/295_*.sql') === [], 'unexpected migration 295 present');
    $schemaColumns = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'
         ORDER BY ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_COLUMN);
    $assert($schemaColumns === ['version', 'applied_at'], 'canonical schema_migrations contract drift');
    $wrongColumnFixtureAccepted = false;
    try {
        $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $wrongColumnFixtureAccepted = true;
    } catch (PDOException) {
    }
    $assert(!$wrongColumnFixtureAccepted, 'wrong migration column fixture was accepted');

    $version = AppVersionService::fileVersion();
    $appVersion = $pdo->prepare(
        "INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
         VALUES ('app.version',?,0,'system')
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0"
    );
    $appVersion->execute(['2.37.1']);
    $pdo->prepare(
        'INSERT INTO app_versions(version,notes,installed_at) VALUES (?,\'canonical baseline\',UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE version=VALUES(version)'
    )->execute(['2.37.1']);
    $marker = new InstalledVersionMarkerService();
    $assert($marker->write('2.37.1', '294_queue_v4_clean_greenfield_2_37_0.sql'), 'baseline marker write failed');
    $promotion = (new DirectUpdateMetadataPromotionService())->promote(
        $pdo,
        $version,
        '294_queue_v4_clean_greenfield_2_37_0.sql',
        'Queue V4 2.37.2 canonical transition',
    );
    $assert(
        $promotion['previous_version'] === '2.37.1'
        && $promotion['target_version'] === '2.37.2'
        && $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.37.2'
        && ($marker->read()['version'] ?? '') === '2.37.2',
        'metadata transition 2.37.1 to 2.37.2 failed',
    );

    $companyInsert = $pdo->prepare(
        'INSERT INTO companies(id,name,nit,status) VALUES (?,?,?,1)
         ON DUPLICATE KEY UPDATE name=VALUES(name),status=1'
    );
    foreach ([[1, 'Tenant One', 'LAB-1'], [4, 'Tenant Four', 'LAB-4'], [5, 'Tenant Five', 'LAB-5'], [6, 'Tenant Six', 'LAB-6']] as $company) {
        $companyInsert->execute($company);
    }

    $encryptedAccess = Crypto::encrypt('local-access');
    $encryptedRefresh = Crypto::encrypt('local-refresh');
    $accountInsert = $pdo->prepare(
        'INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status)
         VALUES (?,?,?,? ,"conectado")'
    );
    $tokenInsert = $pdo->prepare(
        'INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at)
         VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR))'
    );
    foreach ([[1, 1, '1001'], [2, 4, '1002'], [3, 5, '1003']] as [$accountId, $companyId, $remoteId]) {
        $accountInsert->execute([$accountId, $companyId, 'Account ' . $accountId, $remoteId]);
        $tokenInsert->execute([$accountId, $encryptedAccess, $encryptedRefresh]);
    }

    // Deliberately dirty canonical legacy tables. Queue V4 Clean must neither
    // read nor mutate this state.
    $pdo->exec(
        "INSERT INTO queue_core_jobs
         (id,company_id,meli_account_id,work_type,resource_type,resource_id,lane,queue_domain,
          idempotency_key,input_version,state,dispatch_state,attempt_count,max_attempts,
          lease_owner,lease_generation,source,payload_json,provenance_json)
         VALUES (41001,1,1,'order_exact','order','9000','normal','operational',
                 'legacy-uncertain','legacy-v1','running','DISPATCHED_RESULT_UNCERTAIN',1,5,
                 'legacy-owner',9,'legacy','{}','{}')"
    );
    $pdo->exec(
        "INSERT INTO queue_core_attempts
         (id,job_id,company_id,meli_account_id,lease_owner,lease_generation,launcher,outcome,dispatch_state)
         VALUES (51001,41001,1,1,'legacy-owner',9,'canary_v4','started','DISPATCHED_RESULT_UNCERTAIN')"
    );
    $pdo->exec(
        "INSERT INTO queue_core_dispatch_journal
         (id,job_id,attempt_id,company_id,meli_account_id,lease_owner,lease_generation,method,endpoint_key,state)
         VALUES (61001,41001,51001,1,1,'legacy-owner',9,'GET','users_me','in_flight')"
    );
    $pdo->exec(
        "UPDATE queue_engine_control SET active_engine='disabled',readiness_mode='preparing',generation=9
         WHERE control_key='primary'"
    );

    $repository = new QueueV4CleanRepository($pdo);
    $assert($repository->counts()['total'] === 0, 'new queue must start empty');
    $databaseContract = new QueueV4CleanDatabaseContract($pdo);
    $assert($databaseContract->issues() === [], 'canonical database contract rejected');
    $assert($databaseContract->metadataQueryCount() === 4, 'database metadata query budget exceeded');
    $decoyPrefix = 'qv4_decoy_' . bin2hex(random_bytes(3));
    try {
        for ($decoy = 0; $decoy < 8; $decoy++) {
            $schema = $decoyPrefix . '_' . $decoy;
            $pdo->exec('CREATE DATABASE `' . $schema . '`');
            for ($table = 0; $table < 4; $table++) {
                $pdo->exec('CREATE TABLE `' . $schema . '`.`noise_' . $table . '` (id BIGINT PRIMARY KEY) ENGINE=InnoDB');
            }
        }
        $decoyContract = new QueueV4CleanDatabaseContract($pdo);
        $assert($decoyContract->issues() === [], 'decoy schemas altered the exact database contract');
        $assert($decoyContract->metadataQueryCount() === 4, 'decoy schemas expanded metadata query count');
    } finally {
        for ($decoy = 0; $decoy < 8; $decoy++) {
            $pdo->exec('DROP DATABASE IF EXISTS `' . $decoyPrefix . '_' . $decoy . '`');
        }
    }
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

    putenv('ML_WRITE_ENABLED=true');
    $assert(in_array('ml_write_enabled', $readiness->snapshot()['issues'], true), 'ml write negative not blocked');
    putenv('ML_WRITE_ENABLED=false');
    unlink($temporaryRoot . '/PAUSE_ERP_AUTOMATION');
    $assert(in_array('automation_not_stopped', $readiness->snapshot()['issues'], true), 'automation negative not blocked');
    file_put_contents($temporaryRoot . '/PAUSE_ERP_AUTOMATION', 'test');
    $pdo->exec("UPDATE queue_v4_clean_control SET scheduler_enabled=1 WHERE control_key='primary'");
    $assert(in_array('scheduler_not_stopped', $readiness->snapshot()['issues'], true), 'scheduler negative not blocked');
    $pdo->exec("UPDATE queue_v4_clean_control SET scheduler_enabled=0,engine_state='ACTIVE' WHERE control_key='primary'");
    $assert(in_array('engine_active', $readiness->snapshot()['issues'], true), 'engine negative not blocked');
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='STOPPED' WHERE control_key='primary'");
    $pdo->exec("UPDATE meli_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=1");
    $assert(in_array('oauth_account_invalid', $readiness->snapshot()['issues'], true), 'expired token negative not blocked');
    $pdo->exec("UPDATE meli_tokens SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR) WHERE meli_account_id=1");

    $before = $readiness->snapshot();
    $assert($before['state'] === 'READY_TO_TEST', 'readiness not ready despite isolated legacy dirt');
    $assert($before['legacy_state_consulted'] === false, 'legacy state reported consulted');

    $lockPdo = new PDO(
        $dsn,
        (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
        (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $assert((int) $lockPdo->query("SELECT GET_LOCK('erp_meli_queue_v4_clean_readiness',0)")->fetchColumn() === 1, 'readiness lock fixture failed');
    $lockBlocked = false;
    try {
        $readiness->certify(1);
    } catch (RuntimeException $error) {
        $lockBlocked = $error->getMessage() === 'queue_v4_clean_readiness_busy';
    }
    $assert($lockBlocked, 'second readiness lock was not blocked');
    $lockPdo->query("SELECT RELEASE_LOCK('erp_meli_queue_v4_clean_readiness')");

    $pdo->exec("INSERT INTO queue_v4_clean_readiness_runs(state,started_by) VALUES ('TESTING',1)");
    $interruptedRunId = (int) $pdo->lastInsertId();
    $pdo->exec("UPDATE queue_v4_clean_control SET readiness_state='TESTING' WHERE control_key='primary'");
    $certified = $readiness->certify(1);
    $assert($certified['state'] === 'CERTIFIED' && $certified['readiness_get_passed'] === 3, 'readiness did not certify 3/3');
    $assert(
        (string) $pdo->query("SELECT state FROM queue_v4_clean_readiness_runs WHERE id={$interruptedRunId}")->fetchColumn() === 'FAILED',
        'interrupted readiness was not closed before retry'
    );
    $assert($remoteCalls === 3 && $certified['queue_jobs_created'] === 0, 'readiness must perform three GETs and zero queue jobs');
    $assert($repository->counts()['total'] === 0, 'readiness created operational work');

    $control = new QueueV4CleanControlService($pdo);
    $activated = $control->activate(1);
    $assert(
        $activated['state'] === 'ACTIVE'
        && $activated['scheduler_created'] === false
        && $activated['scheduler_enabled'] === true,
        'activation contract invalid'
    );
    $assert(
        (new QueueV4CleanScheduler($pdo))->run(3, 5)['status'] === 'stopped',
        'automation stop did not interlock the active scheduler'
    );
    $producer = new QueueV4CleanProducer($pdo, $repository);
    $firstProduction = $producer->produce();
    $firstTotal = $repository->counts()['total'];
    $secondProduction = $producer->produce();
    $assert($firstProduction['created'] === 3 && $firstTotal === 3, 'fresh producer did not create bounded three-account frontier');
    $assert($secondProduction['created'] === 0 && $repository->counts()['total'] === 3, 'fresh producer is not idempotent inside cadence');
    $assert($firstProduction['historical_used'] === false, 'historical importer was used');

    $accountInsert->execute([4, 6, 'Account 4', '1004']);
    $tokenInsert->execute([4, $encryptedAccess, $encryptedRefresh]);
    $assert($producer->produce()['created'] === 0, 'uncertified connected account changed the certified producer set');
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_checkpoints WHERE meli_account_id=4')->fetchColumn() === 0,
        'uncertified account received a producer checkpoint'
    );
    $pdo->exec('DELETE FROM meli_tokens WHERE meli_account_id=4');
    $pdo->exec('DELETE FROM meli_accounts WHERE id=4 AND company_id=6');

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
    $assert((int) $repository->control()['scheduler_enabled'] === 0, 'stop did not disable scheduler');
    $assert((new QueueV4CleanWorker($pdo, $repository, null, null, static fn () => null))->run('test')['claimed'] === 0, 'stopped engine claimed work');
    $pdo->exec("UPDATE app_settings SET setting_value='2.37.1' WHERE setting_key='app.version'");
    $reactivationDriftBlocked = false;
    try {
        $control->activate(1);
    } catch (RuntimeException $error) {
        $reactivationDriftBlocked = str_contains(
            $error->getMessage(),
            'queue_v4_clean_activation_preconditions_invalid:app_version_invalid'
        );
    }
    $assert($reactivationDriftBlocked, 'stale readiness certification allowed reactivation');
    $pdo->exec("UPDATE app_settings SET setting_value='2.37.2' WHERE setting_key='app.version'");
    $control->activate(1);
    $assert(
        $repository->control()['engine_state'] === 'ACTIVE'
        && (int) $repository->control()['scheduler_enabled'] === 1,
        'certified engine could not restart'
    );

    // Local scheduler invocation: explicitly authorize the local fixture, no due work, no HTTP.
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='completed',completed_at=UTC_TIMESTAMP(3) WHERE state='ready'");
    $pdo->exec("UPDATE queue_v4_clean_checkpoints SET next_due_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR)");
    unlink($temporaryRoot . '/PAUSE_ERP_AUTOMATION');
    $pdo->exec(
        "UPDATE queue_v4_clean_leases
         SET owner_ref='other-scheduler',acquired_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
             expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE)
         WHERE lease_key='scheduler'"
    );
    $assert((new QueueV4CleanScheduler($pdo))->run(3, 5)['status'] === 'busy', 'second scheduler lease was not blocked');
    $pdo->exec(
        "UPDATE queue_v4_clean_leases SET owner_ref=NULL,acquired_at=NULL,heartbeat_at=NULL,expires_at=NULL
         WHERE lease_key='scheduler'"
    );
    $scheduler = (new QueueV4CleanScheduler($pdo))->run(3, 5);
    $assert($scheduler['status'] === 'completed' && ($scheduler['worker']['claimed'] ?? -1) === 0, 'local scheduler invocation failed');
    $assert($remoteCalls === 3, 'local engine test performed unexpected HTTP');

    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_core_jobs')->fetchColumn() === 1, 'legacy job was mutated');
    $assert((int) $pdo->query('SELECT generation FROM queue_engine_control')->fetchColumn() === 9, 'legacy generation was mutated');
    $pdo->exec('ALTER TABLE queue_v4_clean_control MODIFY scheduler_enabled INT NULL');
    $assert(
        in_array('db_contract_columns_invalid:queue_v4_clean_control', (new QueueV4CleanDatabaseContract($pdo))->issues(), true),
        'wrong nullability/type contract was not blocked'
    );
    $pdo->exec('ALTER TABLE queue_v4_clean_control MODIFY scheduler_enabled TINYINT(1) NOT NULL DEFAULT 0');
    $assert((new QueueV4CleanDatabaseContract($pdo))->issues() === [], 'column contract restore failed');

    $pdo->exec('ALTER TABLE queue_v4_clean_control DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $assert(
        in_array('db_contract_collation_invalid:queue_v4_clean_control', (new QueueV4CleanDatabaseContract($pdo))->issues(), true),
        'wrong table collation was not blocked'
    );
    $pdo->exec('ALTER TABLE queue_v4_clean_control DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $assert((new QueueV4CleanDatabaseContract($pdo))->issues() === [], 'collation contract restore failed');

    $pdo->exec('ALTER TABLE queue_v4_clean_jobs DROP INDEX idx_qv4_fifo');
    $assert(
        in_array('db_contract_indexes_invalid:queue_v4_clean_jobs', (new QueueV4CleanDatabaseContract($pdo))->issues(), true),
        'missing FIFO index was not blocked'
    );
    $pdo->exec('ALTER TABLE queue_v4_clean_jobs ADD INDEX idx_qv4_fifo (state,available_at,id)');
    $assert((new QueueV4CleanDatabaseContract($pdo))->issues() === [], 'index contract restore failed');

    $pdo->exec('ALTER TABLE meli_accounts DROP FOREIGN KEY fk_meli_accounts_company');
    $assert(
        in_array('db_contract_foreign_keys_invalid:meli_accounts', (new QueueV4CleanDatabaseContract($pdo))->issues(), true),
        'missing tenant foreign key was not blocked'
    );
    $pdo->exec(
        'ALTER TABLE meli_accounts ADD CONSTRAINT fk_meli_accounts_company FOREIGN KEY (company_id) '
        . 'REFERENCES companies(id) ON DELETE RESTRICT ON UPDATE RESTRICT'
    );
    $assert((new QueueV4CleanDatabaseContract($pdo))->issues() === [], 'foreign-key contract restore failed');

    $pdo->exec('RENAME TABLE queue_v4_clean_leases TO queue_v4_clean_leases_missing');
    $assert(
        in_array('db_contract_table_missing:queue_v4_clean_leases', (new QueueV4CleanDatabaseContract($pdo))->issues(), true),
        'missing contract table was not blocked'
    );
    $pdo->exec('RENAME TABLE queue_v4_clean_leases_missing TO queue_v4_clean_leases');
    $assert((new QueueV4CleanDatabaseContract($pdo))->issues() === [], 'table contract restore failed');
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
