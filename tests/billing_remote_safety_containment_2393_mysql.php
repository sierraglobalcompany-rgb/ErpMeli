<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiRhythmDeferredException;
use App\Services\ApiRhythmPolicyService;
use App\Services\AppSettingsService;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\MeliTransportSourcePolicy;
use App\Services\SaleFinancialService;
use App\Services\SchemaInspectorService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: B429-C1 exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_b429_c1_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-b429-c1-' . bin2hex(random_bytes(5));
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

try {
    define('ERP_SHARED_ROOT', $temporary);
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    Database::setConnection($pdo);

    $contract = json_decode(
        (string) file_get_contents($root . '/resources/release/queue-v4-canonical-db-contract-2.38.9.json'),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    foreach ($contract['tables'] as $table => $definition) {
        $columns = [];
        foreach ($definition['columns'] as $column) {
            $sql = $quote((string) $column['COLUMN_NAME']) . ' ' . (string) $column['COLUMN_TYPE'];
            if (($column['CHARACTER_SET_NAME'] ?? null) !== null) {
                $sql .= ' CHARACTER SET ' . (string) $column['CHARACTER_SET_NAME'];
            }
            if (($column['COLLATION_NAME'] ?? null) !== null) {
                $sql .= ' COLLATE ' . (string) $column['COLLATION_NAME'];
            }
            if (str_contains((string) $column['EXTRA'], 'GENERATED')) {
                $expression = match ((string) $column['COLUMN_NAME']) {
                    'default_slot' => "CASE WHEN status='active' AND is_default=1 THEN 1 ELSE NULL END",
                    'available' => 'on_hand-reserved',
                    default => throw new RuntimeException('unknown_generated_column'),
                };
                $sql .= ' GENERATED ALWAYS AS (' . $expression . ') STORED';
            } else {
                $sql .= (string) $column['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
                if ($column['COLUMN_DEFAULT'] !== null) {
                    $sql .= ' DEFAULT ' . (string) $column['COLUMN_DEFAULT'];
                }
                if (trim((string) $column['EXTRA']) !== '') {
                    $sql .= ' ' . (string) $column['EXTRA'];
                }
            }
            $columns[] = $sql;
        }
        $indexes = [];
        foreach ($definition['indexes'] as $index) {
            $indexes[(string) $index['INDEX_NAME']][] = $index;
        }
        foreach ($indexes as $name => $parts) {
            usort($parts, static fn (array $a, array $b): int => (int) $a['SEQ_IN_INDEX'] <=> (int) $b['SEQ_IN_INDEX']);
            $indexed = array_map(
                static fn (array $part): string => $quote((string) $part['COLUMN_NAME'])
                    . ($part['SUB_PART'] !== null ? '(' . (int) $part['SUB_PART'] . ')' : ''),
                $parts
            );
            $prefix = $name === 'PRIMARY'
                ? 'PRIMARY KEY'
                : ((int) $parts[0]['NON_UNIQUE'] === 0 ? 'UNIQUE KEY ' . $quote($name) : 'KEY ' . $quote($name));
            $columns[] = $prefix . ' (' . implode(',', $indexed) . ')';
        }
        $pdo->exec(
            'CREATE TABLE ' . $quote((string) $table) . ' (' . implode(',', $columns) . ') ENGINE='
            . $definition['engine'] . ' DEFAULT CHARSET=utf8mb4 COLLATE=' . $definition['collation']
        );
    }
    $pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE internal_products(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_orders(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_states(
        scope_key VARCHAR(120) PRIMARY KEY,
        generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
        calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,
        block_started_at DATETIME(3) NULL,
        next_allowed_at DATETIME(3) NULL,
        block_pause_until DATETIME(3) NULL,
        last_dispatched_at DATETIME(3) NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_remote_permits(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        permit_token CHAR(40) NOT NULL,
        owner_token CHAR(32) NOT NULL,
        generation BIGINT UNSIGNED NOT NULL,
        run_token VARCHAR(100) NULL,
        work_key VARCHAR(120) NULL,
        company_id BIGINT UNSIGNED NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        endpoint_key VARCHAR(120) NOT NULL,
        job_type VARCHAR(80) NOT NULL,
        method VARCHAR(10) NOT NULL,
        status ENUM("reserved","dispatched","completed","released","expired") NOT NULL DEFAULT "reserved",
        requested_interval_ms INT UNSIGNED NOT NULL,
        effective_interval_ms INT UNSIGNED NOT NULL,
        blocking_scope VARCHAR(80) NULL,
        http_status SMALLINT UNSIGNED NULL,
        created_at DATETIME(3) NOT NULL,
        dispatched_at DATETIME(3) NULL,
        completed_at DATETIME(3) NULL,
        released_at DATETIME(3) NULL,
        expires_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        UNIQUE KEY uq_permit_token(permit_token),
        KEY idx_permit_active(status,expires_at),
        KEY idx_permit_endpoint_dispatch(endpoint_key,dispatched_at)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_penalties(
        scope_key VARCHAR(180) PRIMARY KEY,
        reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,
        blocked_until DATETIME(3) NULL,
        reduced_until DATETIME(3) NOT NULL,
        reason VARCHAR(80) NOT NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_request_pacing_state(
        scope_key VARCHAR(191) PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NULL,
        operation_key VARCHAR(120) NOT NULL,
        effective_rpm INT UNSIGNED NOT NULL,
        next_allowed_at DATETIME(6) NOT NULL,
        last_reserved_at DATETIME(6) NULL,
        last_wait_ms INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_request_logs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NULL,
        company_id BIGINT UNSIGNED NULL,
        method VARCHAR(10) NULL,
        endpoint_path VARCHAR(255) NULL,
        http_status INT NULL,
        retry_after_seconds INT NULL,
        outcome_class VARCHAR(80) NULL,
        reached_remote TINYINT(1) NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        KEY idx_log_created(created_at),
        KEY idx_log_endpoint_status(endpoint_path,http_status,created_at)
    ) ENGINE=InnoDB');
    $pdo->exec((string) file_get_contents($root . '/database/migrations/299_queue_v4_domain_exact_admission_2_39_3.sql'));
    $pdo->exec('CREATE TABLE sale_financial_reconciliation_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        sale_key VARCHAR(191) NOT NULL,
        external_sale_id VARCHAR(191) NOT NULL,
        input_version CHAR(64) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT "pending",
        priority_tier INT NOT NULL DEFAULT 30,
        origin_type VARCHAR(40) NOT NULL,
        origin_id BIGINT NULL,
        next_run_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        remote_pending_since DATETIME NULL,
        retry_until DATETIME NULL,
        last_remote_state VARCHAR(30) NULL,
        safe_message VARCHAR(500) NULL,
        completed_at DATETIME NULL,
        lock_owner VARCHAR(96) NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        lease_expires_at DATETIME NULL,
        heartbeat_at DATETIME NULL,
        attempts INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec("INSERT INTO companies(id,name) VALUES (10,'B429'),(20,'B429-2')");
    $pdo->exec("INSERT INTO meli_accounts(company_id,account_name) VALUES (10,'A'),(20,'B')");
    $accountA = (int) $pdo->query('SELECT id FROM meli_accounts WHERE company_id=10')->fetchColumn();
    $accountB = (int) $pdo->query('SELECT id FROM meli_accounts WHERE company_id=20')->fetchColumn();
    SchemaInspectorService::clearCache();

    $reset = static function () use ($pdo): void {
        $pdo->exec('DELETE FROM api_remote_permits');
        $pdo->exec('DELETE FROM api_request_logs');
        $pdo->exec('DELETE FROM api_rhythm_penalties');
        $pdo->exec('DELETE FROM api_rhythm_states');
    };
    $seedDispatch = static function (int $accountId, int $secondsAgo) use ($pdo): void {
        $pdo->prepare(
            'INSERT INTO api_remote_permits
             (permit_token,owner_token,generation,company_id,meli_account_id,endpoint_key,job_type,method,status,
              requested_interval_ms,effective_interval_ms,http_status,created_at,dispatched_at,completed_at,expires_at,updated_at)
             VALUES (?,?,1,10,?,"billing_orders","domain_exact","GET","completed",1000,1000,200,
                     DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? SECOND),DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? SECOND),
                     DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? SECOND),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 SECOND),UTC_TIMESTAMP(3))'
        )->execute([bin2hex(random_bytes(20)), bin2hex(random_bytes(16)), $accountId, $secondsAgo, $secondsAgo, $secondsAgo]);
    };
    $seed429 = static function (int $accountId, int $secondsAgo, int $retryAfter = 0) use ($pdo): void {
        $pdo->prepare(
            'INSERT INTO api_request_logs
             (meli_account_id,company_id,method,endpoint_path,http_status,retry_after_seconds,outcome_class,reached_remote,created_at)
             VALUES (?,10,"GET","/billing/integration/group/ML/order/details",429,?,"http_error",1,
                     DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? SECOND))'
        )->execute([$accountId, $retryAfter > 0 ? $retryAfter : null, $secondsAgo]);
    };
    $blocked = static function (ApiRhythmPolicyService $rhythm, int $accountId, string $path): ApiRhythmDeferredException {
        try {
            $permit = $rhythm->reserve($accountId, 'GET', $path, ['job_type' => 'domain_exact']);
            $rhythm->release($permit);
        } catch (ApiRhythmDeferredException $error) {
            return $error;
        }
        throw new RuntimeException('expected_rhythm_deferral');
    };

    // T1: persisted physical dispatch enforces the complete 900-second boundary.
    // Use a safe margin below/above the boundary; the 899→900 edge is a
    // wall-clock race in long suites because reserve() reads a fresh DB clock.
    foreach ([890, 840, 300, 1] as $secondsAgo) {
        $reset();
        $seedDispatch($accountA, $secondsAgo);
        $error = $blocked(new ApiRhythmPolicyService(), $accountA, '/billing/integration/group/ML/order/details');
        $assert($error->blockingScope === 'billing_endpoint_interval', 't1_wrong_scope_' . $secondsAgo);
        $assert((int) $pdo->query('SELECT COUNT(*) FROM api_remote_permits')->fetchColumn() === 1, 't1_created_permit_' . $secondsAgo);
    }
    $reset();
    $seedDispatch($accountA, 901);
    $eligible = (new ApiRhythmPolicyService())->reserve($accountA, 'GET', '/billing/integration/group/ML/order/details', ['job_type' => 'domain_exact']);
    $assert(!empty($eligible['permit_token']), 't1_not_eligible_after_billing_boundary');
    (new ApiRhythmPolicyService())->release($eligible);

    // T2: the billing-only boundary never blocks an unrelated endpoint.
    $reset();
    $seedDispatch($accountA, 1);
    $other = (new ApiRhythmPolicyService())->reserve($accountA, 'GET', '/orders/search', ['job_type' => 'fresh_orders_discovery']);
    $assert(!empty($other['permit_token']), 't2_other_endpoint_blocked');
    (new ApiRhythmPolicyService())->release($other);

    // T3-T6: progressive, application-wide backoff comes from durable physical Billing 429 logs.
    foreach ([1 => 43200, 2 => 86400, 3 => 172800, 4 => 259200] as $count => $minimum) {
        $reset();
        for ($i = $count; $i >= 1; $i--) {
            $seed429($accountA, $i === 1 ? 5 : 60 + $i);
        }
        $error = $blocked(new ApiRhythmPolicyService(), $accountB, '/billing/integration/group/ML/order/details');
        $delta = (strtotime((string) $error->nextSafeAt . ' UTC') ?: 0) - time();
        $assert($error->blockingScope === 'billing_429_backoff', 't' . ($count + 2) . '_wrong_scope');
        $assert($delta >= $minimum - 7, 't' . ($count + 2) . '_backoff_too_short:' . $delta);
    }

    // T7: Retry-After can lengthen, never shorten, the progressive policy.
    $reset();
    $seed429($accountA, 70);
    $seed429($accountA, 5, 7200);
    $retryPolicy = new ApiRhythmPolicyService();
    $retryAfter = $blocked($retryPolicy, $accountB, '/billing/integration/group/ML/order/details');
    $assert((strtotime((string) $retryAfter->nextSafeAt . ' UTC') ?: 0) - time() >= 7193, 't7_retry_after_reduced');
    $known429Next = $retryPolicy->rateLimitNextSafeAt(
        ['endpoint_key' => 'billing_orders', 'permit_token' => str_repeat('a', 40)],
        7200
    );
    $assert((strtotime($known429Next . ' UTC') ?: 0) - time() >= 7193, 't7_known_429_authority_reduced');

    $scopeKey = 'endpoint:shared:' . hash('sha256', 'billing_orders');
    $pdo->prepare(
        'INSERT INTO api_rhythm_penalties
         (scope_key,reduced_limit_per_minute,blocked_until,reduced_until,reason)
         VALUES (?,1,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 6 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 6 HOUR),"http_429")'
    )->execute([$scopeKey]);
    $knownToken = bin2hex(random_bytes(20));
    $knownOwner = bin2hex(random_bytes(16));
    $pdo->prepare(
        'INSERT INTO api_remote_permits
         (permit_token,owner_token,generation,company_id,meli_account_id,endpoint_key,job_type,method,status,
          requested_interval_ms,effective_interval_ms,created_at,dispatched_at,expires_at,updated_at)
         VALUES (?,?,1,10,?,"billing_orders","domain_exact","GET","dispatched",1000,1000,
                 UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 120 SECOND),UTC_TIMESTAMP(3))'
    )->execute([$knownToken, $knownOwner, $accountA]);
    $retryPolicy->finalizeKnownResult([
        'enabled' => true,
        'permit_token' => $knownToken,
        'owner_token' => $knownOwner,
        'generation' => 1,
        'endpoint_key' => 'billing_orders',
        'current_adaptive_limit' => 40,
    ], 429, 60);
    $persistedSeconds = (int) $pdo->query(
        'SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(3),blocked_until)
         FROM api_rhythm_penalties WHERE scope_key=' . $pdo->quote($scopeKey)
    )->fetchColumn();
    $assert($persistedSeconds >= 21593, 't7_penalty_upsert_reduced_existing_block');
    $persistedLonger = $blocked(new ApiRhythmPolicyService(), $accountA, '/billing/integration/group/ML/order/details');
    $assert((strtotime((string) $persistedLonger->nextSafeAt . ' UTC') ?: 0) - time() >= 21593, 't7_persisted_block_was_reduced');

    // T8: a new service instance reconstructs the same block from persisted evidence.
    $pdo->exec('DELETE FROM api_rhythm_penalties');
    $restart = $blocked(new ApiRhythmPolicyService(), $accountA, '/billing/integration/group/ML/order/details');
    $assert($restart->blockingScope === 'billing_429_backoff', 't8_restart_lost_backoff');

    // T9: one account's physical billing boundary is shared by the application endpoint.
    $reset();
    $seedDispatch($accountA, 1);
    $shared = $blocked(new ApiRhythmPolicyService(), $accountB, '/billing/integration/group/ML/order/details');
    $assert($shared->blockingScope === 'billing_endpoint_interval', 't9_account_b_not_blocked');

    // T10: two domain pointers can be claimed, but only one fake physical Billing dispatch occurs.
    $reset();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_attempts');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_runs');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_jobs');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled)
                VALUES ('primary','ACTIVE','CERTIFIED',1)");
    $sourceIds = [];
    foreach (['one', 'two'] as $suffix) {
        $pdo->prepare(
            'INSERT INTO sale_financial_reconciliation_jobs
             (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type,next_run_at)
             VALUES (10,?, ?, ?, ?, "pending","fixture",UTC_TIMESTAMP())'
        )->execute([$accountA, 'O:' . $suffix, $suffix, str_repeat($suffix === 'one' ? 'a' : 'b', 64)]);
        $sourceId = (int) $pdo->lastInsertId();
        $sourceIds[] = $sourceId;
        $payload = json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,max_attempts,payload_json)
             VALUES (10,?,"domain_exact",?, ?,"ready",UTC_TIMESTAMP(),3,?)'
        )->execute([$accountA, (string) $sourceId, 'b429:' . $sourceId, $payload]);
    }
    $physical = 0;
    $handler = static function (string $capability, int $sourceId, int $claimedAccount) use (
        $pdo,
        $accountA,
        &$physical
    ): void {
        if ($capability !== 'financial_reconciliation' || $claimedAccount !== $accountA) {
            throw new RuntimeException('t10_scope_invalid');
        }
        $rhythm = new ApiRhythmPolicyService();
        $permit = $rhythm->reserve($claimedAccount, 'GET', '/billing/integration/group/ML/order/details', [
            'job_type' => 'domain_exact',
        ]);
        if (!$rhythm->dispatched($permit)) {
            throw new RuntimeException('t10_dispatch_fence_failed');
        }
        $physical++;
        $rhythm->completed($permit, 200);
        $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET status="complete" WHERE id=? AND meli_account_id=?')
            ->execute([$sourceId, $claimedAccount]);
    };
    $result = (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo), null, null, null, $handler))
        ->run('test', 2, 10);
    $pointers = $pdo->query('SELECT state,attempt_count,last_error_class FROM queue_v4_clean_jobs ORDER BY id')->fetchAll();
    $assert($result['claimed'] === 2 && $physical === 1, 't10_physical_not_bounded');
    $assert((string) $pointers[0]['state'] === 'completed', 't10_first_pointer_not_complete');
    $assert((string) $pointers[1]['state'] === 'waiting' && (int) $pointers[1]['attempt_count'] === 0, 't10_second_pointer_penalized');
    $assert(str_contains((string) $pointers[1]['last_error_class'], 'billing_endpoint_interval'), 't10_classification_missing');

    // T11-T12: SaleFinancial keeps the later of its retry and the rhythm authority.
    $finish = new ReflectionMethod(SaleFinancialService::class, 'finish');
    $financial = new SaleFinancialService();
    $insertRunning = static function (int $attempts, string $key) use ($pdo, $accountA): array {
        $pdo->prepare(
            'INSERT INTO sale_financial_reconciliation_jobs
             (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type,next_run_at,
              lock_owner,lease_generation,lease_expires_at,attempts)
             VALUES (10,?,?,?, ?,"running","fixture",UTC_TIMESTAMP(),"owner",1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),?)'
        )->execute([$accountA, $key, $key, str_repeat('c', 64), $attempts]);
        return ['id' => (int) $pdo->lastInsertId(), 'lock_owner' => 'owner', 'lease_generation' => 1, 'attempts' => $attempts];
    };
    $job120 = $insertRunning(1, 'O:t11');
    $finish->invoke($financial, $job120, 'retry', 'safe', gmdate('Y-m-d H:i:s', time() + 7200));
    $next120 = (string) $pdo->query('SELECT next_run_at FROM sale_financial_reconciliation_jobs WHERE id=' . (int) $job120['id'])->fetchColumn();
    $assert((strtotime($next120 . ' UTC') ?: 0) >= time() + 7195, 't11_financial_shortened_rhythm');

    $job60 = $insertRunning(4, 'O:t12');
    $finish->invoke($financial, $job60, 'retry', 'safe', gmdate('Y-m-d H:i:s', time() + 1800));
    $next60 = (string) $pdo->query('SELECT next_run_at FROM sale_financial_reconciliation_jobs WHERE id=' . (int) $job60['id'])->fetchColumn();
    $assert((strtotime($next60 . ' UTC') ?: 0) >= time() + 3595, 't12_financial_later_retry_lost');

    // C1.1: every Queue V4 read source fails closed if the persistent
    // rhythm authority is unavailable. No pacing, budget or transport may
    // be reached as an alternative authority.
    $fakeTransport = new class implements MeliHttpTransportInterface {
        public int $calls = 0;

        public function request(
            string $method,
            string $url,
            array $data,
            array $headers,
            bool $form,
            array $timeouts,
        ): array {
            $this->calls++;
            throw new RuntimeException('c11_transport_must_not_be_reached');
        }
    };
    $send = new ReflectionMethod(MeliApiClient::class, 'send');
    $schemaAvailable = new ReflectionProperty(ApiRhythmPolicyService::class, 'schemaAvailable');
    $schemaAvailable->setValue(null, false);
    (new AppSettingsService())->set('api.guard.enabled', '0', 'b429_c11_test');
    AppSettingsService::clearCache();
    $pdo->exec('DELETE FROM api_request_pacing_state');
    $pacingBefore = (int) $pdo->query('SELECT COUNT(*) FROM api_request_pacing_state')->fetchColumn();
    $cases = [
        [MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT, '/billing/integration/group/ML/order/details', 'domain_exact'],
        ['queue_v4_clean', '/orders/2393001', 'order_exact'],
        [MeliTransportSourcePolicy::QUEUE_V4_SALES_AUDIT, '/orders/search', 'sales_audit'],
        [MeliTransportSourcePolicy::QUEUE_V4_SALES_REPAIR, '/orders/2393002', 'sales_repair'],
    ];
    foreach ($cases as [$source, $path, $jobType]) {
        $deferred = null;
        try {
            $send->invoke(
                new MeliApiClient($accountA, $fakeTransport),
                'GET',
                'https://api.mercadolibre.com' . $path,
                [],
                [],
                false,
                false,
                [
                    'source' => $source,
                    'job_type' => $jobType,
                    'company_id' => 10,
                    'account_id' => $accountA,
                ]
            );
        } catch (Throwable $error) {
            $deferred = $error;
        }
        $assert(
            $deferred instanceof ApiRhythmDeferredException,
            'c11_not_deferred:' . $source . ':' . ($deferred ? $deferred::class . ':' . $deferred->getMessage() : 'none')
        );
        $assert($deferred?->blockingScope === 'rhythm_authority_unavailable', 'c11_wrong_scope:' . $source);
        $assert((strtotime((string) $deferred?->nextSafeAt . ' UTC') ?: 0) >= time() + 55, 'c11_next_safe_too_short:' . $source);
    }
    $assert($fakeTransport->calls === 0, 'c11_queue_v4_crossed_transport');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM api_request_pacing_state')->fetchColumn() === $pacingBefore, 'c11_pacing_fallback_reached');

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_attempts');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_runs');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_jobs');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->prepare(
        'INSERT INTO sale_financial_reconciliation_jobs
         (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type,next_run_at)
         VALUES (10,?,"O:c11","c11",? ,"pending","fixture",UTC_TIMESTAMP())'
    )->execute([$accountA, str_repeat('d', 64)]);
    $c11SourceId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,max_attempts,payload_json)
         VALUES (10,?,"domain_exact",?,"b429-c11-pointer","ready",UTC_TIMESTAMP(),3,?)'
    )->execute([
        $accountA,
        (string) $c11SourceId,
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $c11SourceId], JSON_THROW_ON_ERROR),
    ]);
    $pointerResult = (new QueueV4CleanWorker(
        $pdo,
        new QueueV4CleanRepository($pdo),
        null,
        null,
        null,
        static function (string $capability, int $sourceId, int $claimedAccount) use (
            $send,
            $fakeTransport,
            $accountA,
            $c11SourceId
        ): void {
            if ($capability !== 'financial_reconciliation'
                || $sourceId !== $c11SourceId
                || $claimedAccount !== $accountA) {
                throw new RuntimeException('c11_pointer_scope_invalid');
            }
            $send->invoke(
                new MeliApiClient($claimedAccount, $fakeTransport),
                'GET',
                'https://api.mercadolibre.com/billing/integration/group/ML/order/details',
                [],
                [],
                false,
                false,
                [
                    'source' => MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT,
                    'job_type' => 'domain_exact',
                    'company_id' => 10,
                    'account_id' => $claimedAccount,
                ]
            );
        }
    ))->run('test', 1, 10);
    $pointer = $pdo->query(
        'SELECT state,attempt_count,last_error_class FROM queue_v4_clean_jobs WHERE idempotency_key="b429-c11-pointer"'
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert((int) ($pointerResult['claimed'] ?? 0) === 1, 'c11_pointer_not_claimed');
    $assert((string) ($pointer['state'] ?? '') === 'waiting', 'c11_pointer_not_waiting');
    $assert((int) ($pointer['attempt_count'] ?? -1) === 0, 'c11_pointer_penalized');
    $assert(str_contains((string) ($pointer['last_error_class'] ?? ''), 'rhythm_authority_unavailable'), 'c11_pointer_scope_missing');
    $assert($fakeTransport->calls === 0, 'c11_pointer_crossed_transport');
    $schemaAvailable->setValue(null, null);

    $policySource = (string) file_get_contents($root . '/app/Services/ApiRhythmPolicyService.php');
    $financialSource = (string) file_get_contents($root . '/app/Services/SaleFinancialService.php');
    $assert(str_contains($policySource, 'BILLING_MIN_INTERVAL_SECONDS = 900'), 'billing_interval_contract_missing');
    $assert(str_contains($financialSource, 'if ($error instanceof ApiRhythmDeferredException)'), 'financial_rhythm_catch_missing');

    fwrite(STDOUT, 'BILLING_REMOTE_SAFETY_CONTAINMENT_2393=PASS checks=' . $checks
        . ' interval=900 levels=12/24/48/72h physical_max=1 queue_v4_without_rhythm_http=0'
        . ' pointer=waiting attempt_penalty=0 real_http=0' . PHP_EOL);
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    @rmdir($temporary . DIRECTORY_SEPARATOR . 'storage');
    @rmdir($temporary);
}
