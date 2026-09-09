<?php
declare(strict_types=1);

// Run serially against an explicitly configured local disposable MySQL/MariaDB.
// No transport mock or HTTP handler: this suite exercises persisted release decisions.
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\Migrator;

function r1_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label);
    }
    echo 'PASS:' . $label . PHP_EOL;
}

/** Fixture-only inserts; identifiers come exclusively from the fixed arrays below. */
function r1_insert(PDO $pdo, string $table, array $row): int
{
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES ('
        . implode(',', array_fill(0, count($row), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));
    return (int) $pdo->lastInsertId();
}

/** Closed current-generation waiting evidence, optionally made inconsistent by one test. */
function r1_waiting(PDO $pdo, string $label, ?int $status = 500, array $job = [], array $attempt = [], array $event = [], bool $withEvent = true): array
{
    $at = '2000-01-01 00:00:00.000';
    $cause = $status === null ? 'pre_transport_deferred' : 'meliapiexception';
    $q = array_replace([
        'company_id' => 9001, 'meli_account_id' => 9011, 'job_type' => 'order_exact',
        'resource_id' => '80000001', 'idempotency_key' => $label, 'payload_json' => '{}',
        'state' => 'waiting', 'attempt_count' => 1, 'max_attempts' => 3,
        'available_at' => $at, 'lease_owner' => null, 'lease_expires_at' => null,
        'lease_generation' => 2, 'last_error_class' => $cause,
    ], $job);
    $id = r1_insert($pdo, 'queue_v4_clean_jobs', $q);
    $a = array_replace([
        'job_id' => $id, 'run_id' => 1, 'company_id' => $q['company_id'], 'meli_account_id' => $q['meli_account_id'],
        'lease_owner' => 'r1-' . $id, 'lease_generation' => 2, 'outcome' => 'waiting',
        'error_class' => $cause, 'started_at' => $at, 'finished_at' => $at, 'source_closed_at' => $at,
        'dispatch_state' => $status === null ? 'NOT_DISPATCHED' : 'RESPONSE_KNOWN',
        'transport_method' => $status === null ? null : 'GET', 'endpoint_key' => $status === null ? null : 'orders.detail',
        'physical_http_calls' => $status === null ? 0 : 1, 'physical_started_at' => $status === null ? null : $at,
        'http_status' => $status, 'response_known_at' => $status === null ? null : $at,
    ], $attempt);
    $attemptId = r1_insert($pdo, 'queue_v4_clean_attempts', $a);
    $eventId = null;
    if ($withEvent && $status !== null) {
        $eventId = r1_insert($pdo, 'queue_v4_clean_transport_events', array_replace([
            'company_id' => $q['company_id'], 'meli_account_id' => $q['meli_account_id'],
            'source_kind' => 'queue', 'work_id' => $id, 'attempt_id' => $attemptId, 'lease_generation' => 2,
            'request_id' => hash('sha256', $label), 'method' => 'GET', 'endpoint_key' => 'orders.detail',
            'dispatch_state' => 'RESPONSE_KNOWN', 'physical_started_at' => $at, 'response_known_at' => $at, 'http_status' => $status,
        ], $event));
    }
    return ['id' => $id, 'attempt_id' => $attemptId, 'event_id' => $eventId];
}

function r1_snapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach (['queue_v4_clean_jobs', 'queue_v4_clean_attempts', 'queue_v4_clean_transport_events'] as $table) {
        $snapshot[$table] = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $snapshot;
}

/** Compare real rows, not SQL strings; receipts/history must remain immutable. */
function r1_release(PDO $pdo, QueueV4CleanRepository $repo, string $label, array $expected, ?array $authorized = null, ?int $selected = null): void
{
    $before = r1_snapshot($pdo);
    $count = $repo->releaseDueRetryableDirectWaiting($authorized, $selected);
    $after = r1_snapshot($pdo);
    r1_assert($count === count($expected), $label . ':count:observed=' . $count . ':expected=' . count($expected));
    foreach (['queue_v4_clean_attempts', 'queue_v4_clean_transport_events'] as $table) {
        r1_assert($before[$table] === $after[$table], $label . ':immutable:' . $table);
    }
    $changed = [];
    foreach ($before['queue_v4_clean_jobs'] as $index => $old) {
        $new = $after['queue_v4_clean_jobs'][$index];
        if (in_array((int) $old['id'], $expected, true)) {
            r1_assert($new['state'] === 'ready', $label . ':ready:' . $old['id']);
            $changed[] = (int) $old['id'];
            foreach (['state', 'updated_at'] as $column) {
                unset($old[$column], $new[$column]);
            }
        }
        r1_assert($old === $new, $label . ':preserved:' . $old['id']);
    }
    sort($changed);
    sort($expected);
    r1_assert($changed === $expected, $label . ':exact_changed_ids');
}

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI_ONLY');
}
$root = rtrim((string) (getenv('CALLS_R1_QA_ROOT') ?: sys_get_temp_dir() . '/calls-r1'), '/\\');
$dir = $root . '/safety-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(5));
if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
    throw new RuntimeException('OWNERSHIP_DIRECTORY_FAILED');
}
$dbName = 'erp_meli_k1d_test_r1_safety_' . bin2hex(random_bytes(8));
foreach (['APP_ENV' => 'test', 'ML_WRITE_ENABLED' => 'false', 'DB_NAME' => $dbName,
    'PRIVATE_STORAGE_PATH' => $dir . '/private', 'APP_KEY' => 'synthetic-r1-safety-only'] as $key => $value) {
    putenv($key . '=' . $value);
}
K1dSafeTestDatabase::assertGuard('test', 'false', (string) getenv('DB_HOST'), $dbName);
if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', $dir . '/install');
}
mkdir(ERP_INSTALLATION_ROOT, 0770, true);
$ledger = ['owner' => 'calls_r1_reentry_safety_mysql.php', 'pid' => getmypid(), 'db_name' => $dbName,
    'db_host' => (string) getenv('DB_HOST'), 'db_port' => (string) (getenv('DB_PORT') ?: '3306'),
    'state' => 'OWNERSHIP_RECORDED_BEFORE_CREATE', 'schema_target' => 301, 'real_meli_http' => 0];
$ownership = $dir . '/ownership.json';
if (file_put_contents($ownership, json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
    throw new RuntimeException('OWNERSHIP_WRITE_FAILED');
}
echo 'OWNERSHIP=' . $ownership . PHP_EOL;
$harness = null;
$exit = 0;
try {
    $harness = K1dSafeTestDatabase::createFromEnvironment();
    $pdo = $harness->pdo();
    $ledger['database_version'] = $pdo->query('SELECT VERSION()')->fetchColumn();
    (new Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'R1 synthetic A',1),(9002,'R1 synthetic B',1)");
    foreach ([[9011,9001], [9012,9001], [9021,9002]] as [$account, $company]) {
        $pdo->prepare("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(?,?,'R1 synthetic',?,'conectado')")
            ->execute([$account, $company, (string) (990000 + $account)]);
    }
    $pdo->exec("INSERT INTO queue_v4_clean_runs(id,launcher,worker_ref,status) VALUES(1,'test','r1-safety','completed')");
    $repo = new QueueV4CleanRepository($pdo);
    // This is deliberately the RED assertion on the unpatched base.
    r1_assert(method_exists($repo, 'releaseDueRetryableDirectWaiting'), 'release_api_exists');
    $case = static function (callable $body) use ($pdo): void {
        $pdo->beginTransaction();
        try { $body(); } finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
    };
    $case(static function () use ($pdo, $repo): void {
        $ids = [];
        foreach ([429, 500, 503, 599] as $status) {
            foreach (['order_exact', 'fresh_orders_discovery'] as $type) {
                $ids[] = r1_waiting($pdo, 'known-' . $status . '-' . $type, $status, ['job_type' => $type])['id'];
            }
        }
        $ids[] = r1_waiting($pdo, 'expired-complete-lease', 500, ['lease_owner' => 'expired', 'lease_expires_at' => '2000-01-01'], ['lease_owner' => 'expired'])['id'];
        r1_release($pdo, $repo, 'known_retryable', $ids);
        r1_release($pdo, $repo, 'idempotent_second_release', []);
    });
    $causes = [
        'oauth_refresh_required', 'pre_transport_deferred', 'pre_transport_lease_expired',
        'capacity_deferred:budget', 'capacity_deferred:cron_deadline', 'capacity_deferred:manual_burst', 'capacity_deferred:budget_infrastructure',
        'manual_pause:app', 'manual_pause:account', 'rate_limit_deferred:rhythm', 'rate_limit_deferred:rhythm_permit_busy',
        'rate_limit_deferred:rhythm_interval', 'rate_limit_deferred:rhythm_block_pause', 'rate_limit_deferred:rhythm_shared_orders_search_window',
        'rate_limit_deferred:rhythm_global_window', 'rate_limit_deferred:rhythm_penalty_state_unavailable', 'rate_limit_deferred:retry_after',
        'rate_limit_deferred:rhythm_endpoint_shared_reduced', 'rate_limit_deferred:rhythm_account_reduced',
        'rate_limit_deferred:billing_endpoint_interval', 'rate_limit_deferred:billing_429_backoff',
        'rate_limit_deferred:rhythm_authority_unavailable', 'rate_limit_deferred:rhythm_fence_stale',
    ];
    $case(static function () use ($pdo, $repo, $causes): void {
        $ids = [];
        foreach ($causes as $i => $cause) {
            $ids[] = r1_waiting($pdo, 'zero-' . $i, null, ['last_error_class' => $cause, 'attempt_count' => 0], ['error_class' => $cause])['id'];
        }
        r1_release($pdo, $repo, 'finite_zero_dispatch_causes', $ids);
    });
    $negative = [
        '401' => [401], '403' => [403], '400' => [400], '200' => [200],
        'future' => [500, ['available_at' => '2099-01-01']],
        'exhausted' => [500, ['attempt_count' => 3]],
        'live_lease' => [500, ['lease_owner' => 'live', 'lease_expires_at' => '2099-01-01']],
        'owner_without_expiry' => [500, ['lease_owner' => 'malformed']],
        'expiry_without_owner' => [500, ['lease_expires_at' => '2000-01-01']],
        'empty_owner' => [500, ['lease_owner' => '', 'lease_expires_at' => '2000-01-01']],
        'expired_foreign_owner' => [500, ['lease_owner' => 'foreign', 'lease_expires_at' => '2000-01-01']],
        'zero_generation' => [500, ['lease_generation' => 0], ['lease_generation' => 0], ['lease_generation' => 0]],
        'old_attempt' => [500, [], ['lease_generation' => 1], ['lease_generation' => 1]],
        'old_event' => [500, [], [], ['lease_generation' => 1]],
        'missing_event' => [500, [], [], [], false],
        'event_other_attempt' => [500, [], [], ['attempt_id' => 999999]],
        'event_other_work' => [500, [], [], ['work_id' => 999999]],
        'event_other_source' => [500, [], [], ['source_kind' => 'oauth']],
        'event_other_account' => [500, [], [], ['meli_account_id' => 9012]],
        'event_other_company' => [500, [], [], ['company_id' => 9002, 'meli_account_id' => 9021]],
        'attempt_other_account' => [500, [], ['meli_account_id' => 9012]],
        'attempt_other_company' => [500, [], ['company_id' => 9002]],
        'invalid_job_ownership' => [null, ['company_id' => 9002]],
        'event_status_mismatch' => [500, [], [], ['http_status' => 503]],
        'event_auth_status' => [500, [], [], ['http_status' => 401]],
        'event_post' => [500, [], [], ['method' => 'POST']],
        'attempt_post' => [500, [], ['transport_method' => 'POST']],
        'attempt_unfinished' => [500, [], ['finished_at' => null]],
        'source_unclosed' => [500, [], ['source_closed_at' => null]],
        'attempt_review' => [500, [], ['outcome' => 'review']],
        'attempt_running' => [500, [], ['outcome' => 'running']],
        'attempt_uncertain' => [500, [], ['dispatch_state' => 'PHYSICAL_STARTED', 'response_known_at' => null]],
        'event_uncertain' => [500, [], [], ['dispatch_state' => 'PHYSICAL_STARTED', 'response_known_at' => null, 'http_status' => null]],
        'known_without_physical' => [500, [], ['physical_http_calls' => 0]],
        'known_without_started' => [500, [], ['physical_started_at' => null]],
        'known_without_response' => [500, [], ['response_known_at' => null]],
        'domain' => [500, ['job_type' => 'domain_exact', 'payload_json' => '{"capability":"financial_reconciliation","source_id":1}']],
        'review_job' => [500, ['state' => 'review']],
        'zero_physical_count' => [null, [], ['physical_http_calls' => 1]],
        'zero_physical_timestamp' => [null, [], ['physical_started_at' => '2000-01-01']],
        'zero_response_timestamp' => [null, [], ['response_known_at' => '2000-01-01']],
        'zero_http_status' => [null, [], ['http_status' => 500]],
        'zero_cause_mismatch' => [null, [], ['error_class' => 'oauth_refresh_required']],
        'zero_review_attempt' => [null, [], ['outcome' => 'review']],
    ];
    foreach (['runtimeexception', 'meliapiexception', '', 'arbitrary', 'remote_result_uncertain_safe_get_recovered',
        'rate_limit_deferred:rhythm_unrecognized', 'manual_pause:other', 'capacity_deferred:other',
        'rate_limit_deferred:remote_429_global_pause', 'rate_limit_deferred:remote_backoff'] as $i => $cause) {
        $negative['unapproved_cause_' . $i] = [null, ['last_error_class' => $cause], ['error_class' => $cause]];
    }
    foreach ($negative as $label => $args) {
        $case(static function () use ($pdo, $repo, $label, $args): void {
            r1_waiting($pdo, (string) $label, ...$args);
            r1_release($pdo, $repo, 'reject_' . $label, []);
        });
    }
    foreach ([null, 500] as $status) {
        foreach ([1, 2] as $generation) {
            $case(static function () use ($pdo, $repo, $status, $generation): void {
                $row = r1_waiting($pdo, 'unresolved', $status);
                r1_insert($pdo, 'queue_v4_clean_transport_events', [
                    'company_id' => 9001, 'meli_account_id' => 9011, 'source_kind' => 'queue', 'work_id' => $row['id'],
                    'attempt_id' => $row['attempt_id'], 'lease_generation' => $generation, 'request_id' => 'unresolved-extra',
                    'method' => 'GET', 'endpoint_key' => 'orders.detail', 'dispatch_state' => 'PHYSICAL_STARTED',
                    'physical_started_at' => '2000-01-01', 'response_known_at' => null, 'http_status' => null,
                ]);
                r1_release($pdo, $repo, 'unresolved_guard_' . ($status ?? 'zero') . '_' . $generation, []);
            });
        }
    }
    foreach ([null, 500] as $status) {
        $case(static function () use ($pdo, $repo, $status): void {
            $row = r1_waiting($pdo, 'contradictory-closed-physical', $status);
            r1_insert($pdo, 'queue_v4_clean_transport_events', [
                'company_id' => 9001, 'meli_account_id' => 9011, 'source_kind' => 'queue', 'work_id' => $row['id'],
                'attempt_id' => $row['attempt_id'], 'lease_generation' => 2, 'request_id' => 'contradictory-extra',
                'method' => 'GET', 'endpoint_key' => 'orders.detail', 'dispatch_state' => 'RESPONSE_KNOWN',
                'physical_started_at' => '2000-01-01', 'response_known_at' => '2000-01-01', 'http_status' => 401,
            ]);
            r1_release($pdo, $repo, 'contradictory_closed_event_' . ($status ?? 'zero'), []);
        });
    }
    $case(static function () use ($pdo, $repo): void {
        $row = r1_waiting($pdo, 'missing-attempt', null);
        $pdo->prepare('DELETE FROM queue_v4_clean_attempts WHERE id=? AND company_id=9001 AND meli_account_id=9011')
            ->execute([$row['attempt_id']]);
        r1_release($pdo, $repo, 'missing_attempt', []);
    });
    $scopes = [
        'null_all' => [null, null, [9011, 9012, 9021]], 'empty' => [[], null, []],
        'empty_selected' => [[], 9011, []], 'intersection' => [[9011, 9012], 9012, [9012]],
        'outside_authorized' => [[9011], 9021, []], 'selected_only' => [null, 9021, [9021]],
        'authorized_only' => [[9011, 9021], null, [9011, 9021]], 'normalize' => [[0, -1, '9011', 9011], 0, [9011]],
    ];
    foreach ($scopes as $label => [$authorized, $selected, $accounts]) {
        $case(static function () use ($pdo, $repo, $label, $authorized, $selected, $accounts): void {
            $ids = [];
            foreach ([[9011, 9001], [9012, 9001], [9021, 9002]] as [$account, $company]) {
                $id = r1_waiting($pdo, 'scope-' . $account, 500, ['company_id' => $company, 'meli_account_id' => $account])['id'];
                if (in_array($account, $accounts, true)) { $ids[] = $id; }
            }
            r1_release($pdo, $repo, 'scope_' . $label, $ids, $authorized, $selected);
        });
    }
    $case(static function () use ($pdo, $repo): void {
        $fifo = [];
        for ($i = 0; $i < 205; $i++) {
            // Reverse due-date order defeats accidental ID-only release; ties test ID ordering.
            $available = sprintf('2000-01-%02d 00:00:00.000', 20 - intdiv($i, 11));
            $id = r1_waiting($pdo, 'fifo-' . $i, 500, ['available_at' => $available])['id'];
            $fifo[] = ['id' => $id, 'available_at' => $available];
        }
        usort($fifo, static fn (array $a, array $b): int => [$a['available_at'], $a['id']] <=> [$b['available_at'], $b['id']]);
        r1_release($pdo, $repo, 'fifo_first_200', array_column(array_slice($fifo, 0, 200), 'id'));
        r1_release($pdo, $repo, 'fifo_remaining_5', array_column(array_slice($fifo, 200), 'id'));
        r1_release($pdo, $repo, 'fifo_drained', []);
    });
    $ledger['state'] = 'PASS';
    echo "STATUS=PASS CALLS_R1_REENTRY_SAFETY MYSQL=REAL SCHEMA=301 REAL_MELI_HTTP=0\n";
} catch (Throwable $error) {
    $exit = 1;
    $ledger['state'] = 'FAIL';
    // Never dump SQL, connection strings, environment, or exception messages that may contain credentials.
    $ledger['failure_type'] = get_class($error);
    $ledger['failure_line'] = $error->getLine();
    fwrite(STDERR, 'FAIL_TYPE=' . get_class($error) . ' LINE=' . $error->getLine() . PHP_EOL);
    if (str_starts_with($error->getMessage(), 'FAIL:')) { fwrite(STDERR, $error->getMessage() . PHP_EOL); }
} finally {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    if ($harness !== null) { $harness->cleanup(); $ledger['database_dropped'] = true; }
    file_put_contents($dir . '/result.json', json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    echo 'RESULT=' . $dir . '/result.json' . PHP_EOL;
}
exit($exit);
