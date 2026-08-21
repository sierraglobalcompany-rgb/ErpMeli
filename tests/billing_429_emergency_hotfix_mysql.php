<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiRhythmDeferredException;
use App\Services\ApiRhythmPolicyService;
use App\Services\OrderSyncService;
use App\Services\SchemaInspectorService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: billing_429_emergency_hotfix_mysql requires a disposable MariaDB DSN without dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_b429_hotfix_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-b429-hotfix-' . bin2hex(random_bytes(5));
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
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
    $pdo->exec('CREATE TABLE api_request_logs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NULL,
        company_id BIGINT UNSIGNED NULL,
        method VARCHAR(10) NULL,
        endpoint_path VARCHAR(255) NULL,
        http_status INT NULL,
        retry_after_seconds INT NULL,
        error_type VARCHAR(80) NULL,
        outcome_class VARCHAR(80) NULL,
        reached_remote TINYINT(1) NULL,
        was_blocked TINYINT(1) NULL,
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

    $resetRhythm = static function () use ($pdo): void {
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
             (meli_account_id,company_id,method,endpoint_path,http_status,retry_after_seconds,error_type,outcome_class,reached_remote,was_blocked,created_at)
             VALUES (?,10,"GET","/billing/integration/group/ML/order/details",429,?,"http_error","remote_error",1,0,
                     DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? SECOND))'
        )->execute([$accountId, $retryAfter > 0 ? $retryAfter : null, $secondsAgo]);
    };
    $blocked = static function (ApiRhythmPolicyService $rhythm, int $accountId): ApiRhythmDeferredException {
        try {
            $permit = $rhythm->reserve($accountId, 'GET', '/billing/integration/group/ML/order/details', ['job_type' => 'domain_exact']);
            $rhythm->release($permit);
        } catch (ApiRhythmDeferredException $error) {
            return $error;
        }
        throw new RuntimeException('expected_rhythm_deferral');
    };
    $deltaSeconds = static fn (string $nextSafeAt): int => (strtotime($nextSafeAt . ' UTC') ?: 0) - time();

    $source = (string) file_get_contents($root . '/app/Services/ApiRhythmPolicyService.php');
    $assert(str_contains($source, 'BILLING_MIN_INTERVAL_SECONDS = 900'), 'NORMAL_BILLING_INTERVAL_900_PRESERVED');
    $assert(str_contains($source, 'BILLING_429_ESCALATION_WINDOW_HOURS = 72'), 'BILLING_ESCALATION_WINDOW_72H_MISSING');

    $resetRhythm();
    $seedDispatch($accountA, 300);
    $interval = $blocked(new ApiRhythmPolicyService(), $accountA);
    $assert($interval->blockingScope === 'billing_endpoint_interval', 'NORMAL_BILLING_INTERVAL_900_PRESERVED_block_scope');
    $resetRhythm();
    $seedDispatch($accountA, 901);
    $permit = (new ApiRhythmPolicyService())->reserve($accountA, 'GET', '/billing/integration/group/ML/order/details', ['job_type' => 'domain_exact']);
    $assert(!empty($permit['permit_token']), 'NORMAL_BILLING_INTERVAL_900_PRESERVED_allow_at_900');
    (new ApiRhythmPolicyService())->release($permit);

    foreach ([1 => 12 * 3600, 2 => 24 * 3600, 3 => 48 * 3600, 4 => 72 * 3600] as $count => $minimum) {
        $resetRhythm();
        for ($i = 1; $i <= $count; $i++) {
            $seed429($accountA, 60 + $i);
        }
        $error = $blocked(new ApiRhythmPolicyService(), $accountB);
        $label = match ($count) {
            1 => 'FIRST_REMOTE_429_BLOCKS_12H',
            2 => 'SECOND_REMOTE_429_BLOCKS_24H',
            3 => 'THIRD_REMOTE_429_BLOCKS_48H',
            default => 'FOURTH_REMOTE_429_BLOCKS_72H',
        };
        $assert($error->blockingScope === 'billing_429_backoff', $label . '_scope');
        $assert($deltaSeconds($error->nextSafeAt) >= $minimum - 90, $label . '_duration');
    }

    $resetRhythm();
    $pdo->exec(
        "INSERT INTO api_request_logs
         (meli_account_id,company_id,method,endpoint_path,http_status,error_type,outcome_class,reached_remote,was_blocked,created_at)
         VALUES ({$accountA},10,'GET','/billing/integration/group/ML/order/details',NULL,'api_rhythm_deferred','policy_delay',0,1,UTC_TIMESTAMP(3))"
    );
    $localAllowed = (new ApiRhythmPolicyService())->reserve($accountA, 'GET', '/billing/integration/group/ML/order/details', ['job_type' => 'domain_exact']);
    $assert(!empty($localAllowed['permit_token']), 'LOCAL_DEFER_DOES_NOT_ESCALATE_429');
    (new ApiRhythmPolicyService())->release($localAllowed);

    $resetRhythm();
    $seed429($accountA, 10, 15 * 3600);
    $retryAfter = $blocked(new ApiRhythmPolicyService(), $accountA);
    $assert($deltaSeconds($retryAfter->nextSafeAt) >= 15 * 3600 - 90, 'RETRY_AFTER_LONGER_WINS');

    $resetRhythm();
    $scopeKey = 'endpoint:shared:' . hash('sha256', 'billing_orders');
    $pdo->prepare(
        'INSERT INTO api_rhythm_penalties
         (scope_key,reduced_limit_per_minute,blocked_until,reduced_until,reason)
         VALUES (?,1,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 80 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 80 HOUR),"http_429")'
    )->execute([$scopeKey]);
    $seed429($accountA, 10);
    $longer = $blocked(new ApiRhythmPolicyService(), $accountB);
    $assert($deltaSeconds($longer->nextSafeAt) >= 80 * 3600 - 90, 'EXISTING_LONGER_BLOCK_NOT_REDUCED');

    $resetRhythm();
    $seed429($accountA, 10);
    $firstInstance = $blocked(new ApiRhythmPolicyService(), $accountA);
    $secondInstance = $blocked(new ApiRhythmPolicyService(), $accountA);
    $assert(abs($deltaSeconds($firstInstance->nextSafeAt) - $deltaSeconds($secondInstance->nextSafeAt)) < 5, 'RESTART_PRESERVES_BREAKER');

    $resetRhythm();
    $seedDispatch($accountA, 901);
    $probe = (new ApiRhythmPolicyService())->reserve($accountA, 'GET', '/billing/integration/group/ML/order/details', ['job_type' => 'domain_exact']);
    $assert(!empty($probe['permit_token']), 'AT_MOST_ONE_PROBE_AFTER_BREAKER_first_probe');
    $assert((new ApiRhythmPolicyService())->dispatched($probe), 'AT_MOST_ONE_PROBE_AFTER_BREAKER_dispatch');
    $secondProbe = $blocked(new ApiRhythmPolicyService(), $accountA);
    $assert($secondProbe->blockingScope === 'billing_endpoint_interval', 'SECOND_PROBE_PRETRANSPORT_BLOCKED');

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_attempts');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_runs');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_jobs');
    $pdo->exec('TRUNCATE TABLE sale_financial_reconciliation_jobs');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled)
                VALUES ('primary','ACTIVE','CERTIFIED',1)
                ON DUPLICATE KEY UPDATE engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1");
    $futureKeep = gmdate('Y-m-d H:i:s', time() + 2 * 86400);
    for ($i = 1; $i <= 100; $i++) {
        $pdo->prepare(
            'INSERT INTO sale_financial_reconciliation_jobs
             (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type,next_run_at,attempts)
             VALUES (10,?,?,?,?, "pending","fixture",UTC_TIMESTAMP(),8)'
        )->execute([$accountA, 'F:' . $i, 'F' . $i, hash('sha256', 'F' . $i)]);
        $sourceId = (int) $pdo->lastInsertId();
        $payload = json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR);
        $state = $i === 100 ? 'waiting' : 'ready';
        $available = $i === 100 ? $futureKeep : gmdate('Y-m-d H:i:s', time() - 60);
        $pdo->prepare(
            'INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,max_attempts,payload_json,attempt_count)
             VALUES (10,?,"domain_exact",?, ?, ?, ?, 3, ?, 8)'
        )->execute([$accountA, (string) $sourceId, 'finance:' . $sourceId, $state, $available, $payload]);
    }
    $pdo->prepare(
        'INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,max_attempts,payload_json)
         VALUES (10,?,"order_exact","9001","order:9001","ready",UTC_TIMESTAMP(3),3,?)'
    )->execute([$accountA, json_encode(['order_id' => '9001'], JSON_THROW_ON_ERROR)]);

    $nextSafe = gmdate('Y-m-d H:i:s', time() + 12 * 3600);
    $orderCalls = 0;
    $syncFactory = static function () use (&$orderCalls): object {
        return new class($orderCalls) {
            public function __construct(private int &$orderCalls)
            {
            }

            /** @param array<string,mixed> $metadata */
            public function syncOrderByIdForQueueV4Clean(string $orderId, array $metadata): int
            {
                if ($orderId !== '9001') {
                    throw new RuntimeException('unexpected_order');
                }
                $this->orderCalls++;
                return 1;
            }
        };
    };
    $domainHandler = static function (string $capability, int $sourceId, int $claimedAccount) use ($nextSafe, $accountA): void {
        if ($capability !== 'financial_reconciliation' || $claimedAccount !== $accountA || $sourceId < 1) {
            throw new RuntimeException('financial_scope_invalid');
        }
        throw new ApiRhythmDeferredException('Billing endpoint blocked by emergency test.', $nextSafe, 'billing_429_backoff');
    };
    $worker = new QueueV4CleanWorker(
        $pdo,
        new QueueV4CleanRepository($pdo),
        null,
        $syncFactory,
        null,
        $domainHandler,
    );
    $result = $worker->run('test', 3, 20);
    $assert((int) $result['claimed'] === 2, 'ORDER_EXACT_CAN_PROGRESS_DURING_BILLING_BLOCK_claims');
    $assert((int) $result['completed'] === 1 && (int) $result['deferred'] === 1, 'ORDER_EXACT_CAN_PROGRESS_DURING_BILLING_BLOCK_outcome');
    $assert($orderCalls === 1, 'ORDER_EXACT_CAN_PROGRESS_DURING_BILLING_BLOCK');
    $financeStates = $pdo->query(
        "SELECT state,COUNT(*) jobs,MIN(attempt_count) min_attempt,MAX(attempt_count) max_attempt
         FROM queue_v4_clean_jobs
         WHERE job_type='domain_exact'
           AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='financial_reconciliation'
         GROUP BY state ORDER BY state"
    )->fetchAll(PDO::FETCH_ASSOC);
    $financeByState = [];
    foreach ($financeStates as $row) {
        $financeByState[(string) $row['state']] = $row;
    }
    $assert((int) ($financeByState['waiting']['jobs'] ?? 0) === 100, 'FINANCE_GLOBAL_PARKING');
    $assert((int) ($financeByState['waiting']['min_attempt'] ?? -1) === 8
        && (int) ($financeByState['waiting']['max_attempt'] ?? -1) === 8, 'FINANCE_PARKING_ATTEMPTS_UNCHANGED');
    $assert((int) $pdo->query(
        "SELECT COUNT(*) FROM queue_v4_clean_jobs
         WHERE job_type='domain_exact'
           AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='financial_reconciliation'
           AND state NOT IN ('ready','waiting','running','completed','review','dead')"
    )->fetchColumn() === 0, 'FINANCE_PARKING_STATUS_SAFE');
    $assert((int) $pdo->query(
        "SELECT COUNT(*) FROM queue_v4_clean_jobs
         WHERE job_type='domain_exact'
           AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='financial_reconciliation'
           AND available_at<" . $pdo->quote($nextSafe)
    )->fetchColumn() === 0, 'FINANCE_GLOBAL_PARKING_next_safe');
    $latestFinancialAvailable = (string) $pdo->query(
        "SELECT available_at FROM queue_v4_clean_jobs
         WHERE job_type='domain_exact'
           AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='financial_reconciliation'
         ORDER BY available_at DESC LIMIT 1"
    )->fetchColumn();
    $assert((strtotime($latestFinancialAvailable . ' UTC') ?: 0) >= (strtotime($futureKeep . ' UTC') ?: 0), 'FIFO_PRESERVED');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact' AND state='completed'")->fetchColumn() === 1, 'NON_FINANCE_NOT_PARKED');

    $second = $worker->run('test', 3, 20);
    $assert((int) $second['claimed'] === 0, 'FINANCIAL_POINTERS_CLAIMED_before_next_safe');

    fwrite(
        STDOUT,
        'BILLING_429_EMERGENCY_HOTFIX_MYSQL=PASS'
        . ' checks=' . $checks
        . ' NORMAL_BILLING_INTERVAL_900_PRESERVED=PASS'
        . ' FIRST_REMOTE_429_BLOCKS_12H=PASS'
        . ' SECOND_REMOTE_429_BLOCKS_24H=PASS'
        . ' THIRD_REMOTE_429_BLOCKS_48H=PASS'
        . ' FOURTH_REMOTE_429_BLOCKS_72H=PASS'
        . ' LOCAL_DEFER_DOES_NOT_ESCALATE_429=PASS'
        . ' RETRY_AFTER_LONGER_WINS=PASS'
        . ' EXISTING_LONGER_BLOCK_NOT_REDUCED=PASS'
        . ' RESTART_PRESERVES_BREAKER=PASS'
        . ' AT_MOST_ONE_PROBE_AFTER_BREAKER=PASS'
        . ' SECOND_PROBE_PRETRANSPORT_BLOCKED=PASS'
        . ' FINANCE_GLOBAL_PARKING=PASS'
        . ' FINANCE_PARKING_ATTEMPTS_UNCHANGED=PASS'
        . ' FINANCE_PARKING_STATUS_SAFE=PASS'
        . ' NON_FINANCE_NOT_PARKED=PASS'
        . ' ORDER_EXACT_CAN_PROGRESS_DURING_BILLING_BLOCK=PASS'
        . ' FIFO_PRESERVED=PASS'
        . ' REAL_HTTP=0'
        . PHP_EOL
    );
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    @rmdir($temporary . DIRECTORY_SEPARATOR . 'storage');
    @rmdir($temporary);
}
