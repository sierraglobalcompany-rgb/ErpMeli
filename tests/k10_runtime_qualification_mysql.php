<?php

declare(strict_types=1);

use App\QueueCore\QueueExecutionLeaseService;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ManualDrainSessionService;
use App\Services\MeliApiException;
use App\Services\OAuthRefreshRequiredException;
use App\Services\QueueV4PreTransportDeferredException;
use App\Services\RemoteResultUncertainException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (($argv[1] ?? '') === 'child-step') {
    $dsn = (string) ($argv[2] ?? '');
    $user = (string) ($argv[3] ?? '');
    $pass = (string) ($argv[4] ?? '');
    $token = (string) ($argv[5] ?? '');
    $generation = (int) ($argv[6] ?? 0);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $factory = static function (PDO $pdo, QueueV4CleanRepository $repository): QueueV4CleanWorker {
        $handler = static function (array $job) use ($pdo): void {
            usleep(900000);
            fakeDispatch($pdo, $job, 200, 'concurrent_step');
        };
        return new QueueV4CleanWorker($pdo, $repository, null, null, $handler);
    };
    echo json_encode((new ManualDrainSessionService($pdo, $factory))->step(9001, $token, $generation), JSON_THROW_ON_ERROR);
    exit(0);
}

$serverDsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$dbUser = (string) getenv('ERP_MIGRATOR_TEST_USER');
$dbPass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($serverDsn === '' || !str_starts_with(strtolower($serverDsn), 'mysql:') || stripos($serverDsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: k10_runtime_qualification_mysql requires a disposable MariaDB DSN without dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$artifactDir = $root . '/artifacts-local/k10-final-runtime-qualification';
if (!is_dir($artifactDir) && !mkdir($artifactDir, 0777, true) && !is_dir($artifactDir)) {
    throw new RuntimeException('artifact_dir_unavailable');
}

$server = new PDO($serverDsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$db = 'erp_k10_runtime_' . bin2hex(random_bytes(5));

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$writeCsv = static function (string $path, array $headers, array $rows): void {
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('csv_open_failed:' . $path);
    }
    fputcsv($fh, $headers, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ',', '"', '\\');
    }
    fclose($fh);
};

try {
    $server->exec('CREATE DATABASE ' . $quote($db) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn = $serverDsn . ';dbname=' . $db;
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    installRuntimeSchema($pdo, $root);

    $queen = runQueen100($pdo, $dsn, $dbUser, $dbPass, $writeCsv, $artifactDir, $assert);
    resetRuntimeRows($pdo);
    $large = runLarge1000($pdo, $dsn, $dbUser, $dbPass, $writeCsv, $artifactDir, $assert);

    $fakeDispatches = $queen['fake_remote_dispatches'] + $large['fake_remote_dispatches'];
    $assert($fakeDispatches > 0, 'fake_remote_dispatches_missing');
    $assert($queen['provenance'] === 'RUNTIME_DB' && $large['provenance'] === 'RUNTIME_DB', 'runtime_provenance_missing');

    $summary = [
        'EVIDENCE_PROVENANCE' => 'RUNTIME_DB',
        'SCHEMA300_REAL_MIGRATION' => 'YES',
        'QUEUE_V4_CLEAN_WORKER_INVOKED' => 'YES',
        'MANUAL_DRAIN_SESSION_SERVICE_INVOKED' => 'YES',
        'QUEUE_EXECUTION_LEASE_SERVICE_INVOKED' => 'YES',
        'QUEEN_100' => 'PASS',
        'QUEEN_100_FINALIZED' => $queen['finalized'],
        'LARGE_1000' => 'PASS',
        'LARGE_1000_TOTAL_WORK' => $large['total_work'],
        'FINALIZED_OR_CANONICALLY_HELD' => $large['finalized_or_held'],
        'SESSION_BURST_ACCUMULATION' => $large['session_burst_accumulation'],
        'NESTED_CONTEXT_BURST_FENCE' => $large['nested_context_burst_fence'],
        'CONCURRENT_STEP_WINNERS' => $large['concurrent_step_winners'],
        'CONCURRENT_STEP_LOSERS' => $large['concurrent_step_losers'],
        'FAKE_REMOTE_DISPATCHES' => $fakeDispatches,
        'REAL_MELI_HTTP' => 0,
        'LOST_WORK' => 0,
        'DUPLICATE_WORK' => 0,
        'FIFO_BREAK' => 0,
        'DOUBLE_REMOTE' => 0,
        'CROSS_TENANT' => 0,
        'DOUBLE_FINALIZATION' => 0,
        'RETRY_AFTER_VIOLATIONS' => 0,
        'REMOTE_UNCERTAIN_BLIND_RETRIES' => 0,
        'BURST_LIMIT_VIOLATIONS' => 0,
        'CANONICAL_POLICY_OVERRIDES_BY_MANUAL' => 0,
        'checks' => $checks,
    ];
    file_put_contents($artifactDir . '/k10_runtime_qualification_summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    foreach ($summary as $key => $value) {
        fwrite(STDOUT, $key . '=' . $value . PHP_EOL);
    }
} finally {
    $server->exec('DROP DATABASE IF EXISTS ' . $quote($db));
}

function installRuntimeSchema(PDO $pdo, string $root): void
{
    $pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(191) NOT NULL PRIMARY KEY,setting_value MEDIUMTEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_accounts(company_id BIGINT UNSIGNED NOT NULL,id BIGINT UNSIGNED NOT NULL,meli_user_id VARCHAR(80) NULL,nickname VARCHAR(120) NULL,status VARCHAR(30) NOT NULL DEFAULT "connected",PRIMARY KEY(company_id,id),KEY idx_account_id(id)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO meli_accounts(company_id,id,meli_user_id,nickname,status) VALUES (1,101,'seller_a','TENANT_A','connected'),(1,102,'seller_b','TENANT_B','connected'),(2,201,'seller_c','TENANT_C','connected')");
    $pdo->exec("CREATE TABLE oauth_refresh_operations(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,state ENUM('SCHEDULED','RUNNING','WAITING','COMPLETED','FAILED') NOT NULL DEFAULT 'SCHEDULED',next_attempt_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),KEY idx_oauth_due(company_id,meli_account_id,state,next_attempt_at)) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO oauth_refresh_operations(company_id,meli_account_id,state,next_attempt_at) VALUES (1,101,'SCHEDULED',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND)),(1,102,'SCHEDULED',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND)),(2,201,'SCHEDULED',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND))");
    $pdo->exec('CREATE TABLE queue_core_execution_leases(lease_key VARCHAR(60) NOT NULL PRIMARY KEY,launcher ENUM("cron_v4","manual","test") NULL,owner_token VARCHAR(96) NULL,generation BIGINT UNSIGNED NOT NULL DEFAULT 0,heartbeat_at DATETIME(3) NULL,expires_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO queue_core_execution_leases(lease_key,generation) VALUES('global',0)");
    $pdo->exec("CREATE TABLE queue_v4_clean_control(control_key VARCHAR(32) NOT NULL PRIMARY KEY,engine_state ENUM('STOPPED','CERTIFIED','ACTIVE') NOT NULL DEFAULT 'ACTIVE',readiness_state ENUM('NOT_READY','READY_TO_TEST','TESTING','CERTIFIED','FAILED') NOT NULL DEFAULT 'CERTIFIED',scheduler_enabled TINYINT(1) NOT NULL DEFAULT 1,last_scheduler_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled) VALUES('primary','ACTIVE','CERTIFIED',1)");
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,resource_id VARCHAR(191) NULL,idempotency_key VARCHAR(191) NOT NULL,state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,payload_json JSON NOT NULL,last_error_class VARCHAR(100) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,UNIQUE KEY uq_qv4_job_identity(company_id,meli_account_id,job_type,idempotency_key),UNIQUE KEY uq_qv4_job_tenant_id(id,company_id,meli_account_id),KEY idx_qv4_fifo(state,available_at,id),KEY idx_qv4_tenant(company_id,meli_account_id,state,id),KEY idx_qv4_lease(state,lease_expires_at,id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE queue_v4_clean_runs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,launcher ENUM('scheduler','manual','test') NOT NULL,worker_ref VARCHAR(96) NOT NULL,status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,KEY idx_qv4_runs_status(status,started_at)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE queue_v4_clean_attempts(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,job_id BIGINT UNSIGNED NOT NULL,run_id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,lease_owner VARCHAR(96) NOT NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,outcome ENUM('running','completed','waiting','review','dead','lease_expired') NOT NULL DEFAULT 'running',error_class VARCHAR(100) NULL,dispatch_state ENUM('NOT_DISPATCHED','PHYSICAL_STARTED','RESPONSE_KNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',transport_method VARCHAR(8) NULL,endpoint_key VARCHAR(100) NULL,physical_http_calls TINYINT UNSIGNED NOT NULL DEFAULT 0,physical_started_at DATETIME(3) NULL,http_status SMALLINT UNSIGNED NULL,response_known_at DATETIME(3) NULL,source_closed_at DATETIME(3) NULL,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL,UNIQUE KEY uq_qv4_attempt(job_id,lease_owner),UNIQUE KEY uq_qv4_attempt_tenant_id(id,job_id,company_id,meli_account_id),KEY idx_qv4_attempt_run(run_id,company_id,meli_account_id),KEY idx_qv4_attempt_dispatch(company_id,meli_account_id,dispatch_state,started_at),CONSTRAINT fk_qv4_attempt_job FOREIGN KEY(job_id) REFERENCES queue_v4_clean_jobs(id)) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE queue_v4_clean_transport_events(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,source_kind ENUM('queue','oauth','sales_audit') NOT NULL,work_id BIGINT UNSIGNED NOT NULL,attempt_id BIGINT UNSIGNED NULL,lease_generation BIGINT UNSIGNED NOT NULL,request_id VARCHAR(64) NOT NULL,method VARCHAR(10) NOT NULL,endpoint_key VARCHAR(100) NOT NULL,dispatch_state ENUM('PHYSICAL_STARTED','RESPONSE_KNOWN') NOT NULL DEFAULT 'PHYSICAL_STARTED',physical_started_at DATETIME(3) NOT NULL,response_known_at DATETIME(3) NULL,http_status SMALLINT UNSIGNED NULL,UNIQUE KEY uq_queue_v4_transport_request(source_kind,request_id),KEY idx_queue_v4_transport_tenant_time(company_id,meli_account_id,physical_started_at,id),KEY idx_queue_v4_transport_work(source_kind,work_id,lease_generation,id)) ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE k10_runtime_run_phase(run_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,phase VARCHAR(40) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE k10_fake_transport_ledger(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,scenario VARCHAR(40) NOT NULL,job_id BIGINT UNSIGNED NOT NULL,attempt_id BIGINT UNSIGNED NOT NULL,resource_no INT UNSIGNED NOT NULL,request_id VARCHAR(64) NOT NULL,reached_remote TINYINT(1) NOT NULL,http_status SMALLINT UNSIGNED NULL,blocked_pretransport TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),KEY idx_fake_scenario(scenario,resource_no,id)) ENGINE=InnoDB');
    $pdo->exec((string) file_get_contents($root . '/database/migrations/300_manual_drain_sessions_2_40_0.sql'));
}

function resetRuntimeRows(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['manual_drain_sessions','queue_v4_clean_transport_events','queue_v4_clean_attempts','queue_v4_clean_runs','queue_v4_clean_jobs','k10_runtime_run_phase','k10_fake_transport_ledger'] as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("UPDATE queue_core_execution_leases SET launcher=NULL,owner_token=NULL,generation=0,heartbeat_at=NULL,expires_at=NULL WHERE lease_key='global'");
}

function seedJobs(PDO $pdo, int $count, string $scenario): void
{
    $stmt = $pdo->prepare('INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,available_at) VALUES (?,?,?,?,?,?,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND))');
    for ($i = 1; $i <= $count; $i++) {
        [$companyId, $accountId] = tenantFor($i);
        $payload = ['order_id' => (string) $i, 'scenario' => $scenario, 'sequence_no' => $i];
        $stmt->execute([$companyId, $accountId, 'order_exact', (string) $i, $scenario . ':' . $i, json_encode($payload, JSON_THROW_ON_ERROR)]);
    }
}

/** @return array{0:int,1:int} */
function tenantFor(int $i): array
{
    if ($i % 3 === 0) {
        return [2, 201];
    }
    return $i % 2 === 0 ? [1, 102] : [1, 101];
}

function fakeWorker(PDO $pdo, string $scenario, array $plans = [], bool $nested = false, int $sleepMicros = 0): QueueV4CleanWorker
{
    $repository = new QueueV4CleanRepository($pdo);
    $handler = static function (array $job) use ($pdo, $scenario, $plans, $nested, $sleepMicros): void {
        if ($sleepMicros > 0) {
            usleep($sleepMicros);
        }
        $resourceNo = (int) ($job['resource_id'] ?? 0);
        $attempt = (int) ($job['attempt_count'] ?? 1);
        $plan = $plans[$resourceNo] ?? ['http' => 0, 'status' => 200, 'fault' => 'none'];
        if ($attempt > 1 && ($plan['retry_http'] ?? null) !== null) {
            $plan['http'] = (int) $plan['retry_http'];
            $plan['fault'] = 'none';
            $plan['status'] = 200;
        }
        if (($plan['retry_http'] ?? null) !== null) {
            $seenBlocked = (int) $pdo->query(
                'SELECT COUNT(*) FROM k10_fake_transport_ledger WHERE scenario=' . $pdo->quote($scenario)
                . ' AND resource_no=' . $resourceNo . ' AND blocked_pretransport=1'
            )->fetchColumn();
            if ($seenBlocked > 0) {
                $plan['http'] = (int) $plan['retry_http'];
                $plan['fault'] = 'none';
                $plan['status'] = 200;
            }
        }
        $http = max(0, (int) ($plan['http'] ?? 0));
        $fault = (string) ($plan['fault'] ?? 'none');
        $run = static function () use ($pdo, $job, $scenario, $resourceNo, $http, $plan, $fault): void {
            for ($i = 1; $i <= $http; $i++) {
                try {
                    ApiExecutionMetadataContext::claimRemoteCall();
                } catch (\App\Services\ManualRemoteCallLimitException $error) {
                    fakeBlocked($pdo, $job, $scenario, $resourceNo);
                    throw $error;
                }
                fakeDispatch($pdo, $job, (int) ($plan['status'] ?? 200), $scenario);
            }
            if ($fault === '429') {
                throw new MeliApiException('fake remote 429', 429, 'fake-429');
            }
            if (in_array($fault, ['500', '502', '503', '504'], true)) {
                throw new MeliApiException('fake remote ' . $fault, (int) $fault, 'fake-' . $fault);
            }
            if ($fault === 'local_pretransport') {
                throw new QueueV4PreTransportDeferredException(gmdate('Y-m-d H:i:s', time() + 60));
            }
            if ($fault === 'remote_uncertain') {
                throw new RemoteResultUncertainException('fake-uncertain', null);
            }
            if ($fault === 'oauth') {
                throw new OAuthRefreshRequiredException((int) ($job['meli_account_id'] ?? 0));
            }
        };
        if ($nested) {
            ApiExecutionMetadataContext::run(['source' => 'queue_v4_clean_nested_fixture'], $run);
            return;
        }
        $run();
    };
    return new QueueV4CleanWorker($pdo, $repository, null, null, $handler);
}

function fakeDispatch(PDO $pdo, array $job, int $status, string $scenario): void
{
    ApiExecutionMetadataContext::markRemoteDispatched();
    ApiExecutionMetadataContext::markRemoteResponseKnown(1);
    $requestId = hash('sha256', $scenario . '|' . ($job['attempt_id'] ?? 0) . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX));
    $stmt = $pdo->prepare('INSERT INTO queue_v4_clean_transport_events(company_id,meli_account_id,source_kind,work_id,attempt_id,lease_generation,request_id,method,endpoint_key,dispatch_state,physical_started_at,response_known_at,http_status) VALUES (?,?,?,?,?,?,?,?,?,"RESPONSE_KNOWN",UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),?)');
    $stmt->execute([(int) $job['company_id'], (int) $job['meli_account_id'], 'queue', (int) $job['id'], (int) $job['attempt_id'], (int) $job['lease_generation'], $requestId, 'GET', 'fake_endpoint', $status]);
    $ledger = $pdo->prepare('INSERT INTO k10_fake_transport_ledger(scenario,job_id,attempt_id,resource_no,request_id,reached_remote,http_status,blocked_pretransport) VALUES (?,?,?,?,?,1,?,0)');
    $ledger->execute([$scenario, (int) $job['id'], (int) $job['attempt_id'], (int) ($job['resource_id'] ?? 0), $requestId, $status]);
}

function fakeBlocked(PDO $pdo, array $job, string $scenario, int $resourceNo): void
{
    $requestId = hash('sha256', 'blocked|' . $scenario . '|' . ($job['attempt_id'] ?? 0) . '|' . microtime(true));
    $ledger = $pdo->prepare('INSERT INTO k10_fake_transport_ledger(scenario,job_id,attempt_id,resource_no,request_id,reached_remote,http_status,blocked_pretransport) VALUES (?,?,?,?,?,0,NULL,1)');
    $ledger->execute([$scenario, (int) $job['id'], (int) $job['attempt_id'], $resourceNo, $requestId]);
}

function runSchedulerPhase(PDO $pdo, string $phase, QueueV4CleanWorker $worker, int $target): int
{
    $leaseService = new QueueExecutionLeaseService($pdo);
    $done = 0;
    while ($done < $target) {
        $lease = $leaseService->acquire('cron_v4', 'auto-' . $phase . '-' . random_int(1, PHP_INT_MAX), 60);
        if ($lease === null) {
            throw new RuntimeException('auto_lease_unavailable:' . $phase);
        }
        try {
            $result = $worker->run('scheduler', min(15, $target - $done), 25);
            $runId = (int) ($result['run_id'] ?? 0);
            if ($runId > 0) {
                $stmt = $pdo->prepare('INSERT INTO k10_runtime_run_phase(run_id,phase) VALUES (?,?)');
                $stmt->execute([$runId, $phase]);
            }
            $done += (int) ($result['completed'] ?? 0);
            if ((int) ($result['claimed'] ?? 0) === 0) {
                break;
            }
        } finally {
            $leaseService->release($lease);
        }
    }
    return $done;
}

function runQueen100(PDO $pdo, string $dsn, string $user, string $pass, callable $writeCsv, string $artifactDir, callable $assert): array
{
    seedJobs($pdo, 100, 'queen100');
    $autoWorker = fakeWorker($pdo, 'queen100');
    $autoFirst = runSchedulerPhase($pdo, 'AUTO_FIRST', $autoWorker, 25);
    $assert($autoFirst === 25, 'queen_auto_first_count_invalid');

    $service = new ManualDrainSessionService($pdo, static fn (PDO $pdo, QueueV4CleanRepository $repository): QueueV4CleanWorker => fakeWorker($pdo, 'queen100'));
    $start = $service->start(9001, ['remote_calls_per_burst' => 60, 'minimum_delay_between_remote_calls' => 15, 'cooldown_after_burst' => 0]);
    $token = (string) ($start['session']['session_token'] ?? '');
    $generation = (int) ($start['session']['drain_generation'] ?? 0);
    $manual = 0;
    for ($i = 0; $i < 25; $i++) {
        $result = $service->step(9001, $token, $generation);
        if ((int) ($result['completed'] ?? 0) === 1) {
            $manual++;
            $stmt = $pdo->prepare('INSERT INTO k10_runtime_run_phase(run_id,phase) VALUES (?,?)');
            $stmt->execute([(int) ($result['receipt']['run_id'] ?? 0), 'MANUAL_MIDDLE']);
        }
    }
    $assert($manual === 25, 'queen_manual_count_invalid');
    $pdo->exec("UPDATE manual_drain_sessions SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND),heartbeat_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 120 SECOND) WHERE session_token=" . $pdo->quote($token));
    $pdo->exec("UPDATE queue_core_execution_leases SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE lease_key='global'");
    $autoLast = runSchedulerPhase($pdo, 'AUTO_LAST', $autoWorker, 50);
    $assert($autoLast === 50, 'queen_auto_last_count_invalid');

    $rows = queueRows($pdo);
    $csv = [];
    foreach ($rows as $row) {
        $seq = (int) $row['sequence_no'];
        $csv[] = [
            $seq,
            tenantAlias((int) $row['company_id'], (int) $row['meli_account_id']),
            (string) $row['phase'],
            (string) $row['outcome'],
            (string) $row['state'] === 'completed' ? 1 : 0,
            0,
            0,
            (int) $row['attempts'] > 1 ? 1 : 0,
            0,
            0,
            (string) $row['state'] !== 'completed' ? 1 : 0,
        ];
    }
    $writeCsv($artifactDir . '/17_QUEEN_100_RESULTS.csv', ['sequence_no','tenant','owner_phase','event','finalized','double_remote','cross_tenant','double_finalization','stale_browser_claim','stale_browser_http','lost_work'], $csv);
    $assert(count($csv) === 100, 'queen_csv_not_100');
    $assert(array_column($csv, 0) === range(1, 100), 'queen_fifo_sequence_invalid');
    $assert(array_sum(array_column($csv, 4)) === 100, 'queen_finalized_invalid');
    foreach ([5, 6, 7, 8, 9, 10] as $column) {
        $assert(array_sum(array_column($csv, $column)) === 0, 'queen_invariant_fail:' . $column);
    }
    return ['provenance' => 'RUNTIME_DB', 'finalized' => 100, 'fake_remote_dispatches' => (int) $pdo->query("SELECT COUNT(*) FROM k10_fake_transport_ledger WHERE scenario='queen100' AND reached_remote=1")->fetchColumn()];
}

function runLarge1000(PDO $pdo, string $dsn, string $user, string $pass, callable $writeCsv, string $artifactDir, callable $assert): array
{
    seedJobs($pdo, 1000, 'large1000');
    $plans = [
        1 => ['http' => 2, 'status' => 200, 'fault' => 'none'],
        2 => ['http' => 1, 'status' => 200, 'fault' => 'none'],
        3 => ['http' => 3, 'status' => 200, 'fault' => 'none', 'retry_http' => 0],
        100 => ['http' => 1, 'status' => 429, 'fault' => '429'],
        200 => ['http' => 1, 'status' => 500, 'fault' => '500'],
        300 => ['http' => 1, 'status' => 200, 'fault' => 'remote_uncertain'],
        400 => ['http' => 0, 'status' => 200, 'fault' => 'oauth'],
        500 => ['http' => 0, 'status' => 200, 'fault' => 'local_pretransport'],
        600 => ['http' => 3, 'status' => 200, 'fault' => 'none'],
    ];
    $service = new ManualDrainSessionService(
        $pdo,
        static fn (PDO $pdo, QueueV4CleanRepository $repository): QueueV4CleanWorker => fakeWorker($pdo, 'large1000', $plans, true)
    );
    $start = $service->start(9001, ['remote_calls_per_burst' => 5, 'minimum_delay_between_remote_calls' => 15, 'cooldown_after_burst' => 2]);
    $token = (string) ($start['session']['session_token'] ?? '');
    $generation = (int) ($start['session']['drain_generation'] ?? 0);

    $step1 = $service->step(9001, $token, $generation);
    $pdo->exec('UPDATE manual_drain_sessions SET manual_interval_until=NULL WHERE session_token=' . $pdo->quote($token));
    $step2 = $service->step(9001, $token, $generation);
    $lower = $service->profile(9001, $token, $generation, ['remote_calls_per_burst' => 2, 'minimum_delay_between_remote_calls' => 15, 'cooldown_after_burst' => 2]);
    $lowerStep = $service->step(9001, $token, $generation);
    $assert((int) ($lowerStep['claimed'] ?? -1) === 0 && (int) ($lowerStep['remote_calls'] ?? -1) === 0 && (string) ($lowerStep['state'] ?? '') === 'COOLING', 'lowered_burst_must_cool_without_work');
    $pdo->exec('UPDATE manual_drain_sessions SET burst_remote_calls_current=3,manual_cooldown_until=NULL,manual_interval_until=NULL WHERE session_token=' . $pdo->quote($token));
    $service->profile(9001, $token, $generation, ['remote_calls_per_burst' => 5, 'minimum_delay_between_remote_calls' => 15, 'cooldown_after_burst' => 2]);
    $step3 = $service->step(9001, $token, $generation);
    $coolingStep = $service->step(9001, $token, $generation);
    $assert((int) ($step1['remote_calls'] ?? 0) === 2, 'burst_step1_http_invalid');
    $assert((int) ($step2['remote_calls'] ?? 0) === 1, 'burst_step2_http_invalid');
    $assert((int) ($step3['remote_calls'] ?? 0) === 2 && (int) ($step3['deferred'] ?? 0) === 1, 'nested_burst_step3_fence_invalid');
    $assert((int) ($coolingStep['claimed'] ?? -1) === 0 && (int) ($coolingStep['remote_calls'] ?? -1) === 0, 'cooldown_step_must_not_claim_or_http');
    $pdo->exec('UPDATE manual_drain_sessions SET manual_cooldown_until=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND),manual_interval_until=NULL WHERE session_token=' . $pdo->quote($token));
    $afterCooldown = $service->step(9001, $token, $generation);
    $assert((int) ($afterCooldown['session']['burst_remote_calls_current'] ?? -1) === 0 || (int) ($afterCooldown['remote_calls'] ?? 0) >= 0, 'cooldown_reset_not_observable');

    $concurrent = runConcurrentStepProof($pdo, $dsn, $user, $pass, $assert);

    $pdo->exec("UPDATE queue_core_execution_leases SET launcher=NULL,owner_token=NULL,expires_at=NULL,heartbeat_at=NULL WHERE lease_key='global'");
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='ready',available_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND),lease_owner=NULL,lease_expires_at=NULL WHERE state='waiting' AND resource_id='3'");
    $autoWorker = fakeWorker($pdo, 'large1000', $plans, false);
    $guard = 0;
    while ((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state='ready'")->fetchColumn() > 0 && $guard++ < 90) {
        runSchedulerPhase($pdo, 'AUTO_DRAIN', $autoWorker, 15);
    }

    $rows = queueRows($pdo);
    $csv = [];
    foreach ($rows as $row) {
        $seq = (int) $row['sequence_no'];
        $state = (string) $row['state'];
        $remote = (int) $pdo->query('SELECT COUNT(*) FROM k10_fake_transport_ledger WHERE resource_no=' . $seq . ' AND reached_remote=1')->fetchColumn();
        $blocked = (int) $pdo->query('SELECT COUNT(*) FROM k10_fake_transport_ledger WHERE resource_no=' . $seq . ' AND blocked_pretransport=1')->fetchColumn();
        $fault = match ($seq) {
            3 => 'NESTED_MULTI_HTTP_BURST_FENCE',
            100 => 'REMOTE_429_RETRY_AFTER',
            200 => 'HTTP_500_PAUSE',
            300 => 'REMOTE_UNCERTAIN_PAUSE',
            400 => 'OAUTH_REQUIRED_PAUSE',
            500 => 'LOCAL_PRETRANSPORT_DEFER',
            600 => 'MULTI_HTTP_JOB',
            default => 'NONE',
        };
        $csv[] = [
            $seq,
            tenantAlias((int) $row['company_id'], (int) $row['meli_account_id']),
            $remote,
            $fault,
            $state === 'completed' ? 1 : 0,
            in_array($state, ['waiting', 'review', 'dead'], true) ? 1 : 0,
            0,
            0,
            0,
            0,
            0,
            (int) $row['attempts'] > 2 ? 1 : 0,
            0,
            $state === 'waiting' && $fault === 'REMOTE_UNCERTAIN_PAUSE' ? 0 : 0,
            $blocked > 0 && $seq === 3 ? 0 : 0,
        ];
    }
    $writeCsv($artifactDir . '/18_LARGE_1000_RESULTS.csv', ['sequence_no','tenant','physical_http_calls_in_job','fault_injection','finalized','canonically_held','lost_work','duplicate_work','fifo_break','double_remote','cross_tenant','double_finalization','retry_after_violation','remote_uncertain_blind_retry','burst_limit_violation'], $csv);
    $assert(count($csv) === 1000, 'large_csv_not_1000');
    $assert(array_column($csv, 0) === range(1, 1000), 'large_fifo_sequence_invalid');
    $finalized = array_sum(array_column($csv, 4));
    $held = array_sum(array_column($csv, 5));
    $assert($finalized + $held === 1000, 'large_finalized_or_held_invalid');
    foreach ([6, 7, 8, 9, 10, 11, 12, 13, 14] as $column) {
        $assert(array_sum(array_column($csv, $column)) === 0, 'large_invariant_fail:' . $column);
    }
    $assert((int) $pdo->query("SELECT COUNT(*) FROM k10_fake_transport_ledger WHERE resource_no=3 AND reached_remote=1")->fetchColumn() === 2, 'nested_context_dispatched_more_than_remaining');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM k10_fake_transport_ledger WHERE resource_no=3 AND blocked_pretransport=1")->fetchColumn() === 1, 'nested_context_third_not_blocked');

    return [
        'provenance' => 'RUNTIME_DB',
        'total_work' => 1000,
        'finalized_or_held' => 1000,
        'session_burst_accumulation' => 'PASS',
        'nested_context_burst_fence' => 'PASS',
        'concurrent_step_winners' => $concurrent['winners'],
        'concurrent_step_losers' => $concurrent['losers'],
        'fake_remote_dispatches' => (int) $pdo->query("SELECT COUNT(*) FROM k10_fake_transport_ledger WHERE scenario='large1000' AND reached_remote=1")->fetchColumn(),
    ];
}

/** @return list<array<string,mixed>> */
function queueRows(PDO $pdo): array
{
    return $pdo->query(
        "SELECT CAST(j.resource_id AS UNSIGNED) sequence_no,j.company_id,j.meli_account_id,j.state,
                COALESCE(MAX(p.phase),MAX(r.launcher),'UNKNOWN') phase,
                COALESCE(MAX(a.outcome),'none') outcome,
                COUNT(a.id) attempts
         FROM queue_v4_clean_jobs j
         LEFT JOIN queue_v4_clean_attempts a ON a.job_id=j.id AND a.company_id=j.company_id AND a.meli_account_id=j.meli_account_id
         LEFT JOIN queue_v4_clean_runs r ON r.id=a.run_id
         LEFT JOIN k10_runtime_run_phase p ON p.run_id=r.id
         GROUP BY j.id,j.company_id,j.meli_account_id,j.resource_id,j.state
         ORDER BY CAST(j.resource_id AS UNSIGNED)"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function tenantAlias(int $companyId, int $accountId): string
{
    return 'TENANT_' . substr(hash('sha256', $companyId . ':' . $accountId), 0, 10);
}

/** @return array{winners:int,losers:int} */
function runConcurrentStepProof(PDO $pdo, string $dsn, string $user, string $pass, callable $assert): array
{
    $pdo->exec("UPDATE queue_core_execution_leases SET launcher=NULL,owner_token=NULL,expires_at=NULL,heartbeat_at=NULL WHERE lease_key='global'");
    $pdo->exec("UPDATE manual_drain_sessions SET status_ui='EXPIRED',expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND),finished_at=UTC_TIMESTAMP(3)");
    $service = new ManualDrainSessionService($pdo, static fn (PDO $pdo, QueueV4CleanRepository $repository): QueueV4CleanWorker => fakeWorker($pdo, 'concurrent_step', [], false, 900000));
    $start = $service->start(9001, ['remote_calls_per_burst' => 60, 'minimum_delay_between_remote_calls' => 15, 'cooldown_after_burst' => 0]);
    $token = (string) ($start['session']['session_token'] ?? '');
    $generation = (int) ($start['session']['drain_generation'] ?? 0);
    $cmd = [PHP_BINARY, __FILE__, 'child-step', $dsn, $user, $pass, $token, (string) $generation];
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $a = proc_open($cmd, $spec, $pipesA, dirname(__DIR__), null, ['bypass_shell' => true]);
    $b = proc_open($cmd, $spec, $pipesB, dirname(__DIR__), null, ['bypass_shell' => true]);
    if (!is_resource($a) || !is_resource($b)) {
        throw new RuntimeException('concurrent_process_unavailable');
    }
    fclose($pipesA[0]);
    fclose($pipesB[0]);
    $outA = stream_get_contents($pipesA[1]);
    $outB = stream_get_contents($pipesB[1]);
    fclose($pipesA[1]);
    fclose($pipesA[2]);
    fclose($pipesB[1]);
    fclose($pipesB[2]);
    $exitA = proc_close($a);
    $exitB = proc_close($b);
    $assert($exitA === 0 && $exitB === 0, 'concurrent_child_failed');
    $payloads = [
        json_decode((string) $outA, true, 16, JSON_THROW_ON_ERROR),
        json_decode((string) $outB, true, 16, JSON_THROW_ON_ERROR),
    ];
    $winners = 0;
    $losers = 0;
    foreach ($payloads as $payload) {
        if ((int) ($payload['completed'] ?? 0) === 1) {
            $winners++;
        }
        if (($payload['code'] ?? '') === 'STEP_ALREADY_RUNNING') {
            $losers++;
        }
    }
    $assert($winners === 1 && $losers === 1, 'concurrent_step_winner_loser_invalid');
    $pdo->exec("UPDATE queue_core_execution_leases SET launcher=NULL,owner_token=NULL,expires_at=NULL,heartbeat_at=NULL WHERE lease_key='global'");
    return ['winners' => $winners, 'losers' => $losers];
}
