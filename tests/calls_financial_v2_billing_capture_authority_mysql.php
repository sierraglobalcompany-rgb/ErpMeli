<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_transport_wire_fixture.php';

use App\Core\Database;
use App\Core\Crypto;
use App\Services\Cap2DomainsWire;
use App\Services\Migrator;
use App\Services\ApiExecutionMetadataContext;
use App\Services\AppSettingsService;
use App\Services\CronDeadlineContext;
use App\Services\SaleFinancialService;
use App\Services\SaleFinancialStateService;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;

function financial_v2_assert(bool $condition, string $message, array $context = []): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $message . ':' . json_encode($context, JSON_THROW_ON_ERROR));
    }
    echo 'PASS:' . $message . PHP_EOL;
}

/** @return array{job:array<string,mixed>,order_id:string} */
function financial_v2_seed_job(PDO $pdo, string $orderId, int $generation = 1, string $status = 'running', bool $expired = false): array
{
    $pdo->prepare(
        "INSERT INTO meli_orders
            (meli_account_id,external_order_id,status,total_amount,paid_amount,currency_id,synced_at)
         VALUES(9011,?,'paid',100,100,'COP',UTC_TIMESTAMP())"
    )->execute([$orderId]);
    $localOrderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO meli_order_items
            (meli_order_id,meli_account_id,external_item_id,title,seller_sku,quantity,unit_price,sale_fee)
         VALUES(?,9011,?,?,?,1,100,10)'
    )->execute([$localOrderId, 'ITEM-' . $orderId, 'Order ' . $orderId, 'SKU-' . $orderId]);
    $saleKey = 'O:' . $orderId;
    $state = (new SaleFinancialStateService())->projectSale(9001, 9011, $saleKey);
    $owner = bin2hex(random_bytes(16));
    $lease = $expired ? 'DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)' : 'DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)';
    $pdo->prepare(
        "INSERT INTO sale_financial_reconciliation_jobs
            (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at,
             attempts,lock_owner,lease_generation,lease_expires_at,heartbeat_at,last_remote_state)
         VALUES(9001,9011,?,?,?,?,'2000-01-01',1,?,?,{$lease},UTC_TIMESTAMP(),'billing_v2_claimed')"
    )->execute([$saleKey, $orderId, $state['input_version'], $status, $owner, $generation]);
    $id = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare(
        'SELECT id,company_id,meli_account_id,sale_key,external_sale_id,input_version,status,
                attempts,lock_owner,lease_generation,lease_expires_at,last_remote_state
         FROM sale_financial_reconciliation_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    );
    $stmt->execute([$id]);
    return ['job' => $stmt->fetch(PDO::FETCH_ASSOC) ?: [], 'order_id' => $orderId];
}

function financial_v2_capture_state(PDO $pdo, int $captureId): string
{
    $stmt = $pdo->prepare('SELECT dispatch_state FROM meli_billing_capture_runs WHERE id=?');
    $stmt->execute([$captureId]);
    return (string) $stmt->fetchColumn();
}

/** @return array{job:array<string,mixed>,order_ids:list<string>,sale_key:string} */
function financial_v2_seed_pack_job(PDO $pdo): array
{
    $packId = (string) random_int(882000000, 882999999);
    $orderIds = [(string) random_int(881100000, 881199999), (string) random_int(881200000, 881299999)];
    foreach ($orderIds as $orderId) {
        $pdo->prepare(
            "INSERT INTO meli_orders
                (meli_account_id,external_order_id,external_pack_id,status,total_amount,paid_amount,currency_id,synced_at)
             VALUES(9011,?,?,'paid',100,100,'COP',UTC_TIMESTAMP())"
        )->execute([$orderId, $packId]);
        $localOrderId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO meli_order_items
                (meli_order_id,meli_account_id,external_item_id,title,seller_sku,quantity,unit_price,sale_fee)
             VALUES(?,9011,?,?,?,1,100,10)'
        )->execute([$localOrderId, 'ITEM-' . $orderId, 'Pack order ' . $orderId, 'SKU-' . $orderId]);
    }
    $saleKey = 'P:' . $packId;
    $state = (new SaleFinancialStateService())->projectSale(9001, 9011, $saleKey);
    $owner = bin2hex(random_bytes(16));
    $pdo->prepare(
        "INSERT INTO sale_financial_reconciliation_jobs
            (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at,
             attempts,lock_owner,lease_generation,lease_expires_at,heartbeat_at,last_remote_state)
         VALUES(9001,9011,?,?,?,'running','2000-01-01',1,?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),UTC_TIMESTAMP(),'billing_v2_claimed')"
    )->execute([$saleKey, $packId, $state['input_version'], $owner]);
    $id = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare(
        'SELECT id,company_id,meli_account_id,sale_key,external_sale_id,input_version,status,
                attempts,lock_owner,lease_generation,lease_expires_at,last_remote_state,priority_tier
         FROM sale_financial_reconciliation_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    );
    $stmt->execute([$id]);
    return ['job' => $stmt->fetch(PDO::FETCH_ASSOC) ?: [], 'order_ids' => $orderIds, 'sale_key' => $saleKey];
}

/** @return array<string,mixed> */
function financial_v2_crash_snapshot(PDO $pdo, int $jobId, ?int $queueId = null): array
{
    $source = $pdo->prepare(
        'SELECT status,last_remote_state,lease_generation,attempts,lock_owner,lease_expires_at
         FROM sale_financial_reconciliation_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    );
    $source->execute([$jobId]);
    $capture = $pdo->prepare(
        'SELECT dispatch_state,http_status,response_hash,response_body_raw,result_durable_at
         FROM meli_billing_capture_runs
         WHERE company_id=9001 AND meli_account_id=9011 AND financial_job_id=?
         ORDER BY id DESC LIMIT 1'
    );
    $capture->execute([$jobId]);
    $queue = null;
    $attempt = null;
    if ($queueId !== null) {
        $queueStmt = $pdo->prepare(
            'SELECT state,lease_generation FROM queue_v4_clean_jobs
             WHERE id=? AND company_id=9001 AND meli_account_id=9011'
        );
        $queueStmt->execute([$queueId]);
        $queue = $queueStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $attemptStmt = $pdo->prepare(
            'SELECT dispatch_state,physical_http_calls,outcome FROM queue_v4_clean_attempts
             WHERE job_id=? AND company_id=9001 AND meli_account_id=9011 ORDER BY id DESC LIMIT 1'
        );
        $attemptStmt->execute([$queueId]);
        $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $sourceRow = $source->fetch(PDO::FETCH_ASSOC) ?: [];
    $captureRow = $capture->fetch(PDO::FETCH_ASSOC) ?: null;
    if (is_array($captureRow)) {
        $captureRow = [
            'dispatch_state' => $captureRow['dispatch_state'],
            'http_status' => $captureRow['http_status'] === null ? null : (int) $captureRow['http_status'],
            'response_hash' => $captureRow['response_hash'],
            'body_bytes' => $captureRow['response_body_raw'] === null ? null : strlen((string) $captureRow['response_body_raw']),
            'result_durable_at' => $captureRow['result_durable_at'],
        ];
    }
    return [
        'source' => [
            'status' => $sourceRow['status'] ?? null,
            'last_remote_state' => $sourceRow['last_remote_state'] ?? null,
            'generation' => isset($sourceRow['lease_generation']) ? (int) $sourceRow['lease_generation'] : null,
            'attempts' => isset($sourceRow['attempts']) ? (int) $sourceRow['attempts'] : null,
            'has_owner' => !empty($sourceRow['lock_owner']),
            'lease_expires_at' => $sourceRow['lease_expires_at'] ?? null,
        ],
        'capture' => $captureRow,
        'queue' => $queue,
        'queue_attempt' => $attempt,
    ];
}

function financial_v2_crash_ledger(string $case, array $before, array $after, string $recovery, int $httpCount): void
{
    echo 'CRASH_MATRIX:' . json_encode([
        'case' => $case,
        'SQL_BEFORE' => $before,
        'SQL_AFTER' => $after,
        'HTTP_COUNT' => $httpCount,
        'RECOVERY_CLASSIFICATION' => $recovery,
        'FINAL_SOURCE_STATE' => $after['source']['status'] ?? null,
        'FINAL_CAPTURE_STATE' => $after['capture']['dispatch_state'] ?? null,
        'QUEUE_STATE' => $after['queue']['state'] ?? null,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

/** @return int */
function financial_v2_seed_queue_pointer(PDO $pdo, int $jobId, string $key): int
{
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
            (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'domain_exact',?,?,?,'ready','2000-01-01')"
    )->execute([
        (string) $jobId,
        $key . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $jobId], JSON_THROW_ON_ERROR),
    ]);
    return (int) $pdo->lastInsertId();
}

function financial_v2_seed_queue_transport_event(PDO $pdo, int $queueId, string $requestId, string $state = 'PHYSICAL_STARTED', ?int $httpStatus = null): void
{
    $pdo->prepare(
        'INSERT INTO queue_v4_clean_transport_events
            (company_id,meli_account_id,source_kind,work_id,attempt_id,lease_generation,request_id,
             method,endpoint_key,dispatch_state,physical_started_at,response_known_at,http_status)
         VALUES(9001,9011,"queue",?,NULL,1,?,"GET","/billing/integration/group/ML/order/details",?,
                UTC_TIMESTAMP(3),IF(? IS NULL,NULL,UTC_TIMESTAMP(3)),?)'
    )->execute([$queueId, $requestId, $state, $httpStatus, $httpStatus]);
}

$root = rtrim(str_replace('\\', '/', (string) getenv('CALLS_QA_STORAGE_ROOT')), '/');
if ($root === '' || !is_dir($root)) {
    throw new RuntimeException('EXPLICIT_LOCAL_QA_ROOT_REQUIRED');
}
putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_NAME=erp_meli_k1d_test_financial_v2_' . bin2hex(random_bytes(5)));
putenv('CALLS_VERIFY_QA_ROOT=' . $root . '/db-journal');
putenv('APP_KEY=synthetic-financial-v2-only');
putenv('PRIVATE_STORAGE_PATH=' . $root . '/private');
putenv('MELI_API_BASE=https://no-network.invalid');
if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', $root . '/install-' . bin2hex(random_bytes(3)));
}
if (!is_dir(ERP_INSTALLATION_ROOT)) {
    mkdir(ERP_INSTALLATION_ROOT, 0770, true);
}

$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    (new Migrator($pdo, __DIR__ . '/../database/migrations'))->run(302);

    $generationType = $pdo->query(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="sale_financial_reconciliation_jobs"
           AND COLUMN_NAME="lease_generation"'
    )->fetchColumn();
    financial_v2_assert(strtolower((string) $generationType) === 'int(10) unsigned', 'financial_generation_sql_type_verified', [
        'column_type' => $generationType,
    ]);

    $requiredColumns = [
        'financial_job_id',
        'financial_claim_generation',
        'external_order_id',
        'billing_resource_key',
        'request_id',
        'dispatch_state',
        'dispatch_committed_at',
        'result_durable_at',
        'aborted_at',
        'response_body_raw',
        'unresolved_guard',
    ];
    $column = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="meli_billing_capture_runs" AND COLUMN_NAME=?'
    );
    $missing = [];
    foreach ($requiredColumns as $name) {
        $column->execute([$name]);
        if ((int) $column->fetchColumn() !== 1) {
            $missing[] = $name;
        }
    }
    financial_v2_assert($missing === [], 'R17_R18_V2_CAPTURE_SCHEMA_EXISTS', ['missing' => $missing]);
    financial_v2_assert(
        class_exists(App\Services\BillingCaptureTransportAuthority::class),
        'R17_BILLING_AUTHORITY_EXISTS'
    );
    Database::setConnection($pdo);

    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Financial V2 fixture',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'V2 account',99011,'conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([Crypto::encrypt('synthetic-access'), Crypto::encrypt('synthetic-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $settings = new AppSettingsService();
    foreach ([
        'automation.max_api_calls_per_cycle' => '1',
        'automation.api_calls_ceiling' => '55',
        'api.rhythm.pause_ms' => '0',
        'api.rhythm.burst_size' => '100',
        'api.rhythm.minimum_interval_ms' => '0',
        'api.rhythm.current_adaptive_limit' => '100',
        'api.rhythm.billing_min_interval_seconds' => '1',
        'api.rhythm.shared_429_jitter_seconds' => '0',
        'oauth.token_expiry_skew_seconds' => '120',
        'sales_financial.commercial_pipeline_enabled' => '1',
    ] as $key => $value) {
        $settings->set($key, $value, 'financial-v2-disposable-fixture');
    }
    AppSettingsService::clearCache();
    $authority = new App\Services\BillingCaptureTransportAuthority();

    // R17: stale financial provenance is denied at the final pre-cURL fence.
    $stale = financial_v2_seed_job($pdo, '881000001');
    $reserved = $authority->reserve($stale['job'], $stale['order_id']);
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lease_generation=lease_generation+1 WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([(int) $stale['job']['id']]);
    $staleDenied = false;
    try {
        $authority->commitDispatch($reserved);
    } catch (RuntimeException) {
        $staleDenied = true;
    }
    financial_v2_assert($staleDenied && financial_v2_capture_state($pdo, (int) $reserved['capture_run_id']) === 'reserved', 'R17_STALE_FINANCIAL_HTTP_ZERO');

    // R18: final input-version validation rejects a V1 capture after local input changes.
    $v1 = financial_v2_seed_job($pdo, '881000002');
    $v1Capture = $authority->reserve($v1['job'], $v1['order_id']);
    $pdo->prepare('UPDATE meli_order_items SET quantity=2 WHERE meli_account_id=9011 AND external_item_id=?')->execute(['ITEM-' . $v1['order_id']]);
    $versionDenied = false;
    try {
        $authority->commitDispatch($v1Capture);
    } catch (RuntimeException) {
        $versionDenied = true;
    }
    financial_v2_assert($versionDenied && financial_v2_capture_state($pdo, (int) $v1Capture['capture_run_id']) === 'reserved', 'R18_STALE_INPUT_VERSION_HTTP_ZERO');
    $authority->abortReserved($v1Capture);

    // R18/T2: V1 passes preparation, input advances and V2 claims; V1 is
    // rejected at the final fence and V2 cannot create a second unresolved flight.
    $race = financial_v2_seed_job($pdo, '881000014');
    $raceV1 = $authority->reserve($race['job'], $race['order_id']);
    $pdo->prepare('UPDATE meli_order_items SET quantity=3 WHERE meli_account_id=9011 AND external_item_id=?')
        ->execute(['ITEM-' . $race['order_id']]);
    $raceV2State = (new SaleFinancialStateService())->projectSale(
        9001,
        9011,
        (string) $race['job']['sale_key']
    );
    $raceV2Owner = bin2hex(random_bytes(16));
    $pdo->prepare(
        'UPDATE sale_financial_reconciliation_jobs
         SET input_version=?,lease_generation=lease_generation+1,lock_owner=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)
         WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    )->execute([(string) $raceV2State['input_version'], $raceV2Owner, (int) $race['job']['id']]);
    $raceV2Job = $race['job'];
    $raceV2Job['input_version'] = (string) $raceV2State['input_version'];
    $raceV2Job['lease_generation'] = (int) $race['job']['lease_generation'] + 1;
    $raceV2Job['lock_owner'] = $raceV2Owner;
    $raceV2Denied = false;
    try {
        $authority->reserve($raceV2Job, $race['order_id']);
    } catch (RuntimeException $error) {
        $raceV2Denied = $error->getMessage() === 'billing_v2_unresolved_capture_conflict';
    }
    $raceV1Denied = false;
    try {
        $authority->commitDispatch($raceV1);
    } catch (RuntimeException) {
        $raceV1Denied = true;
    }
    $raceUnresolvedCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM meli_billing_capture_runs
         WHERE company_id=9001 AND meli_account_id=9011 AND billing_resource_key='
            . $pdo->quote((string) $raceV1['billing_resource_key']) . ' AND unresolved_guard=1'
    )->fetchColumn();
    financial_v2_assert(
        $raceV2Denied
            && $raceV1Denied
            && $raceUnresolvedCount === 1
            && financial_v2_capture_state($pdo, (int) $raceV1['capture_run_id']) === 'reserved',
        'R18_T2_VERSION_ADVANCE_BLOCKS_STALE_V1_AND_SECOND_UNRESOLVED_FLIGHT'
    );
    $authority->abortReserved($raceV1);

    // R26: unresolved exclusion crosses Financial generation/attempt identity.
    $cross = financial_v2_seed_job($pdo, '881000003');
    $captureA = $authority->reserve($cross['job'], $cross['order_id']);
    $authority->commitDispatch($captureA);
    $crossDenied = false;
    try {
        $authority->reserve($cross['job'], $cross['order_id']);
    } catch (RuntimeException) {
        $crossDenied = true;
    }
    financial_v2_assert($crossDenied && financial_v2_capture_state($pdo, (int) $captureA['capture_run_id']) === 'dispatch_committed', 'R26_SECOND_UNRESOLVED_CAPTURE_DENIED');

    // R19/R23/T9: physical result is durable after Financial lease loss, raw bytes survive exactly.
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lease_generation=lease_generation+1,status="retry",lock_owner=NULL,lease_expires_at=NULL WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([(int) $cross['job']['id']]);
    $raw = '{"exact":true,"format":"synthetic"}';
    $authority->persistKnownResponse($captureA, 200, $raw);
    $readback = $authority->loadCapture((int) $captureA['capture_run_id'], 9001, 9011);
    financial_v2_assert(
        ($readback['dispatch_state'] ?? '') === 'result_durable'
            && (string) ($readback['response_body_raw'] ?? '') === $raw
            && hash_equals(hash('sha256', $raw), (string) ($readback['response_hash'] ?? '')),
        'R19_R23_RESULT_DURABLE_WITHOUT_CURRENT_LEASE_AND_RAW_HASH_EXACT'
    );

    // T9: response durability is byte-exact for success, empty/malformed bodies,
    // and known 429/5xx responses; known x-content-missing semantics survive too.
    $rawCases = [
        [200, '{"valid":true}', []],
        [200, '', []],
        [200, '{malformed', []],
        [429, '{"error":"rate_limited"}', ['x-content-missing' => 'sale_fee, shipping']],
        [503, "\x00\xffserver-error", []],
    ];
    $rawRoundTrips = true;
    foreach ($rawCases as $index => [$rawStatus, $rawBytes, $headers]) {
        $rawJob = financial_v2_seed_job($pdo, (string) (881010000 + $index));
        $rawCapture = $authority->reserve($rawJob['job'], $rawJob['order_id']);
        $authority->commitDispatch($rawCapture);
        $authority->persistKnownResponse($rawCapture, $rawStatus, $rawBytes, $headers);
        $storedRaw = $authority->loadCapture((int) $rawCapture['capture_run_id'], 9001, 9011);
        $storedMissingFields = json_decode((string) ($storedRaw['missing_fields_json'] ?? '[]'), true);
        $expectedMissingFields = isset($headers['x-content-missing'])
            ? ['sale_fee', 'shipping']
            : [];
        $rawRoundTrips = $rawRoundTrips
            && (int) ($storedRaw['http_status'] ?? 0) === $rawStatus
            && (string) ($storedRaw['response_body_raw'] ?? '') === $rawBytes
            && hash_equals(hash('sha256', $rawBytes), (string) ($storedRaw['response_hash'] ?? ''))
            && $storedMissingFields === $expectedMissingFields;
    }
    financial_v2_assert($rawRoundTrips, 'T9_RAW_BODY_HASH_STATUS_AND_SAFE_MISSING_HEADER_ROUNDTRIP');

    // A durable rate-limit/server error is authoritative transport evidence,
    // but it is not a Billing payload to replay as an offline reconciliation.
    // Once the normal retry policy makes the source due, a fresh capture may
    // be reserved while this original response remains byte-exact in the ledger.
    $durableHttpFailuresRemainEvidence = true;
    foreach ([429 => '{"error":"rate_limited"}', 503 => "\x00\xffserver-error"] as $status => $body) {
        $failureJob = financial_v2_seed_job($pdo, (string) (881020000 + $status));
        $failureCapture = $authority->reserve($failureJob['job'], $failureJob['order_id']);
        $authority->commitDispatch($failureCapture);
        $authority->persistKnownResponse($failureCapture, $status, $body);
        $failureLookup = [
            'company_id' => 9001,
            'meli_account_id' => 9011,
            'sale_key' => (string) $failureJob['job']['sale_key'],
            'input_version' => (string) $failureJob['job']['input_version'],
            'external_order_id' => $failureJob['order_id'],
            'billing_resource_key' => hash('sha256', 'billing_order:v1:' . $failureJob['order_id']),
        ];
        $offlineBillingResult = $authority->findUnconsumedDurableResult($failureLookup);
        $preservedFailure = $authority->loadCapture((int) $failureCapture['capture_run_id'], 9001, 9011);
        $nextCapture = null;
        if ($offlineBillingResult === null) {
            $nextCapture = $authority->reserve($failureJob['job'], $failureJob['order_id']);
            $authority->abortReserved($nextCapture);
        }
        $durableHttpFailuresRemainEvidence = $durableHttpFailuresRemainEvidence
            && $offlineBillingResult === null
            && ($preservedFailure['dispatch_state'] ?? '') === 'result_durable'
            && (int) ($preservedFailure['http_status'] ?? 0) === $status
            && (string) ($preservedFailure['response_body_raw'] ?? '') === $body
            && hash_equals(hash('sha256', $body), (string) ($preservedFailure['response_hash'] ?? ''))
            && is_array($nextCapture)
            && financial_v2_capture_state($pdo, (int) $nextCapture['capture_run_id']) === 'aborted_pretransport';
    }
    financial_v2_assert(
        $durableHttpFailuresRemainEvidence,
        'T9_DURABLE_429_5XX_REMAIN_RAW_EVIDENCE_BUT_ARE_NOT_REPLAYED_AS_BILLING_DATA'
    );

    // R25: a resolved response releases only the physical guard, permitting a later capture.
    $recaptureJob = financial_v2_seed_job($pdo, '881000004');
    $capture1 = $authority->reserve($recaptureJob['job'], $recaptureJob['order_id']);
    $authority->commitDispatch($capture1);
    $authority->persistKnownResponse($capture1, 200, '[]');
    $capture2 = $authority->reserve($recaptureJob['job'], $recaptureJob['order_id']);
    financial_v2_assert(
        financial_v2_capture_state($pdo, (int) $capture1['capture_run_id']) === 'result_durable'
            && financial_v2_capture_state($pdo, (int) $capture2['capture_run_id']) === 'reserved',
        'R25_RESULT_DURABLE_RELEASES_GUARD_FOR_LATER_POLICY_APPROVED_CAPTURE'
    );
    $authority->abortReserved($capture2);

    // R24: unresolved exclusion is per remote order, not the whole pack sale.
    $pack = financial_v2_seed_pack_job($pdo);
    $packCaptureIds = [];
    foreach ($pack['order_ids'] as $packOrderId) {
        $packCapture = $authority->reserve($pack['job'], $packOrderId);
        $authority->commitDispatch($packCapture);
        $authority->persistKnownResponse($packCapture, 200, '[]');
        $packCaptureIds[] = (int) $packCapture['capture_run_id'];
    }
    $packCaptures = $pdo->prepare(
        'SELECT external_order_id,dispatch_state FROM meli_billing_capture_runs
         WHERE company_id=9001 AND meli_account_id=9011 AND financial_job_id=? ORDER BY external_order_id'
    );
    $packCaptures->execute([(int) $pack['job']['id']]);
    $packRows = $packCaptures->fetchAll(PDO::FETCH_ASSOC);
    financial_v2_assert(
        count($packRows) === 2
            && count(array_unique(array_column($packRows, 'external_order_id'))) === 2
            && count(array_filter($packRows, static fn (array $row): bool => $row['dispatch_state'] === 'result_durable')) === 2
            && count(array_unique($packCaptureIds)) === 2,
        'R24_MULTIORDER_CAPTURE_GUARD_IS_PER_EXTERNAL_ORDER'
    );

    // R27: durable result/checkpoint provenance remains version-specific.
    $versions = financial_v2_seed_job($pdo, '881000012');
    $v1Capture = $authority->reserve($versions['job'], $versions['order_id']);
    $authority->commitDispatch($v1Capture);
    $v1Raw = '[{"order_id":"' . $versions['order_id'] . '","detail_id":"v1-fee","detail_type":"SALE_FEE","amount":10}]';
    $authority->persistKnownResponse($v1Capture, 200, $v1Raw);
    $v1EvidenceJson = json_encode([
        'format' => 'billing_order_v2',
        'capture_id' => (int) $v1Capture['capture_run_id'],
        'order_id' => $versions['order_id'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $pdo->prepare(
        'INSERT INTO sale_financial_evidence
            (company_id,meli_account_id,sale_key,input_version,evidence_type,evidence_status,source_id,
             payload_hash,evidence_json,captured_at)
         VALUES(9001,9011,?,?,"billing_capture","complete",?,?,?,UTC_TIMESTAMP())'
    )->execute([
        (string) $versions['job']['sale_key'],
        (string) $versions['job']['input_version'],
        (int) $v1Capture['capture_run_id'],
        hash('sha256', $v1EvidenceJson),
        $v1EvidenceJson,
    ]);
    $pdo->prepare('UPDATE meli_order_items SET quantity=2 WHERE meli_account_id=9011 AND external_item_id=?')
        ->execute(['ITEM-' . $versions['order_id']]);
    $v2State = (new SaleFinancialStateService())->projectSale(
        9001,
        9011,
        (string) $versions['job']['sale_key']
    );
    $pdo->prepare(
        'UPDATE sale_financial_reconciliation_jobs SET input_version=?
         WHERE id=? AND company_id=9001 AND meli_account_id=9011 AND status="running"'
    )->execute([(string) $v2State['input_version'], (int) $versions['job']['id']]);
    $v2Lookup = [
        'company_id' => 9001,
        'meli_account_id' => 9011,
        'sale_key' => (string) $versions['job']['sale_key'],
        'input_version' => (string) $v2State['input_version'],
        'external_order_id' => $versions['order_id'],
        'billing_resource_key' => hash('sha256', 'billing_order:v1:' . $versions['order_id']),
    ];
    $v2Reusable = $authority->findUnconsumedDurableResult($v2Lookup);
    $v2Job = $versions['job'];
    $v2Job['input_version'] = (string) $v2State['input_version'];
    $v2Capture = $authority->reserve($v2Job, $versions['order_id']);
    financial_v2_assert(
        $v2Reusable === null
            && (int) $v1Capture['capture_run_id'] !== (int) $v2Capture['capture_run_id']
            && (string) $v1Capture['input_version'] !== (string) $v2Capture['input_version'],
        'R27_V1_DURABLE_EVIDENCE_NOT_REUSED_FOR_V2'
    );
    $authority->abortReserved($v2Capture);

    // R21/C1-C8: certainty-aware recovery only touches future V2-marked exact scope.
    $none = financial_v2_seed_job($pdo, '881000005', 1, 'running', true);
    $noneAction = $authority->recoverExpiredRunning((int) $none['job']['id'], 9001, 9011);
    financial_v2_assert($noneAction === 'retry_not_dispatched', 'R21_NO_CAPTURE_RECOVERS_WITHOUT_HTTP');

    $reservedExpired = financial_v2_seed_job($pdo, '881000006', 1, 'running', false);
    $reservedExpiredCapture = $authority->reserve($reservedExpired['job'], $reservedExpired['order_id']);
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([(int) $reservedExpired['job']['id']]);
    $reservedAction = $authority->recoverExpiredRunning((int) $reservedExpired['job']['id'], 9001, 9011);
    financial_v2_assert($reservedAction === 'retry_not_dispatched' && financial_v2_capture_state($pdo, (int) $reservedExpiredCapture['capture_run_id']) === 'aborted_pretransport', 'R21_RESERVED_ABORTS_AND_RETRIES_WITHOUT_HTTP');

    $committed = financial_v2_seed_job($pdo, '881000007', 1, 'running', false);
    $committedCapture = $authority->reserve($committed['job'], $committed['order_id']);
    $authority->commitDispatch($committedCapture);
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([(int) $committed['job']['id']]);
    $reviewAction = $authority->recoverExpiredRunning((int) $committed['job']['id'], 9001, 9011);
    financial_v2_assert($reviewAction === 'review' && financial_v2_capture_state($pdo, (int) $committedCapture['capture_run_id']) === 'dispatch_committed', 'R21_DISPATCH_COMMITTED_REVIEWS_WITH_ZERO_HTTP');

    $durable = financial_v2_seed_job($pdo, '881000008', 1, 'running', false);
    $durableCapture = $authority->reserve($durable['job'], $durable['order_id']);
    $authority->commitDispatch($durableCapture);
    $authority->persistKnownResponse($durableCapture, 200, '{"stored":true}');
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([(int) $durable['job']['id']]);
    $offlineAction = $authority->recoverExpiredRunning((int) $durable['job']['id'], 9001, 9011);
    financial_v2_assert($offlineAction === 'offline_reconcile' && financial_v2_capture_state($pdo, (int) $durableCapture['capture_run_id']) === 'result_durable', 'R21_RESULT_DURABLE_RECOVERS_OFFLINE');

    // R23/C8: the actual Financial service consumes a durable result offline,
    // preserving the safe missing-content header without another HTTP call.
    $offlineServiceJob = financial_v2_seed_job($pdo, '881000013', 1, 'running', false);
    $offlineServiceCapture = $authority->reserve($offlineServiceJob['job'], $offlineServiceJob['order_id']);
    $authority->commitDispatch($offlineServiceCapture);
    $offlineBody = json_encode([[
        'order_id' => $offlineServiceJob['order_id'],
        'detail_id' => 'offline-fee-' . $offlineServiceJob['order_id'],
        'detail_type' => 'SALE_FEE',
        'description' => 'Synthetic durable fee',
        'amount' => 10,
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $authority->persistKnownResponse(
        $offlineServiceCapture,
        200,
        $offlineBody,
        ['x-content-missing' => 'sale_fee']
    );
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=? AND company_id=9001 AND meli_account_id=9011')
        ->execute([(int) $offlineServiceJob['job']['id']]);
    $c8QueueId = financial_v2_seed_queue_pointer($pdo, (int) $offlineServiceJob['job']['id'], 'financial-v2-c8');
    $pdo->prepare('UPDATE queue_v4_clean_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=?')
        ->execute([$c8QueueId]);
    financial_v2_seed_queue_transport_event(
        $pdo,
        $c8QueueId,
        (string) $offlineServiceCapture['request_id']
    );
    Cap2DomainsWire::$calls = [];
    $c8Before = financial_v2_crash_snapshot($pdo, (int) $offlineServiceJob['job']['id'], $c8QueueId);
    $c8Recovery = $authority->recoverExpiredRunning((int) $offlineServiceJob['job']['id'], 9001, 9011);
    $c8AfterRecovery = financial_v2_crash_snapshot($pdo, (int) $offlineServiceJob['job']['id'], $c8QueueId);
    financial_v2_assert(
        $c8Recovery === 'offline_reconcile'
            && ($c8AfterRecovery['capture']['dispatch_state'] ?? '') === 'result_durable'
            && ($c8AfterRecovery['queue']['state'] ?? '') === 'ready',
        'C8_DURABLE_RESULT_BEFORE_QUEUE_KNOWN_OFFLINE_CLASSIFICATION'
    );
    financial_v2_crash_ledger('C8', $c8Before, $c8AfterRecovery, $c8Recovery, count(Cap2DomainsWire::$calls));
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$onWire = static function (): void {
        throw new RuntimeException('unexpected_http_during_offline_reconcile');
    };
    try {
        $offlineResult = (new SaleFinancialService())->processDomainExactBatch(
            [(int) $offlineServiceJob['job']['id']],
            9001,
            9011,
            false
        );
    } finally {
        Cap2DomainsWire::$onWire = null;
    }
    $offlineCaptureAfter = $authority->loadCapture((int) $offlineServiceCapture['capture_run_id'], 9001, 9011) ?: [];
    $offlineSourceStmt = $pdo->prepare(
        'SELECT status FROM sale_financial_reconciliation_jobs
         WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    );
    $offlineSourceStmt->execute([(int) $offlineServiceJob['job']['id']]);
    $offlineSourceStatus = (string) $offlineSourceStmt->fetchColumn();
    $offlineEvidenceStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM sale_financial_evidence
         WHERE company_id=9001 AND meli_account_id=9011 AND sale_key=? AND input_version=?
           AND evidence_type="billing_capture" AND source_id=?'
    );
    $offlineEvidenceStmt->execute([
        (string) $offlineServiceJob['job']['sale_key'],
        (string) $offlineServiceJob['job']['input_version'],
        (int) $offlineServiceCapture['capture_run_id'],
    ]);
    $offlineMissing = json_decode((string) ($offlineCaptureAfter['missing_fields_json'] ?? '[]'), true);
    $c8AfterOffline = financial_v2_crash_snapshot($pdo, (int) $offlineServiceJob['job']['id'], $c8QueueId);
    financial_v2_assert(
        Cap2DomainsWire::$calls === []
            && ($offlineResult['summary']['processed'] ?? -1) === 1
            && in_array($offlineSourceStatus, ['awaiting_remote', 'partial'], true)
            && ($offlineCaptureAfter['dispatch_state'] ?? '') === 'result_durable'
            && (string) ($offlineCaptureAfter['response_body_raw'] ?? '') === $offlineBody
            && $offlineMissing === ['sale_fee']
            && (int) $offlineEvidenceStmt->fetchColumn() === 1,
        'R23_C8_ACTUAL_OFFLINE_RECONCILIATION_ZERO_HTTP_AND_HEADER_SEMANTICS'
    );
    financial_v2_crash_ledger('C8_OFFLINE_RECONCILE', $c8AfterRecovery, $c8AfterOffline, 'offline_reconcile_completed_without_http', count(Cap2DomainsWire::$calls));

    $legacy = financial_v2_seed_job($pdo, '881000009', 1, 'running', true);
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET last_remote_state=NULL WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([(int) $legacy['job']['id']]);
    $legacyAction = $authority->recoverExpiredRunning((int) $legacy['job']['id'], 9001, 9011);
    financial_v2_assert($legacyAction === 'ambiguous_no_action', 'R21_LEGACY_SOURCE_REMAINS_UNTOUCHED');

    // R17/R20: the real cURL adapter commits Financial dispatch immediately
    // before the fake wire and makes Billing result durable before returning.
    $wireJob = financial_v2_seed_job($pdo, '881000010', 1, 'pending');
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([(int) $wireJob['job']['id']]);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'domain_exact',?,?,?,'ready','2000-01-01')"
    )->execute([
        (string) $wireJob['job']['id'],
        'financial-v2-' . $wireJob['job']['id'] . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => (int) $wireJob['job']['id']], JSON_THROW_ON_ERROR),
    ]);
    $queueId = (int) $pdo->lastInsertId();
    $wireStateAtTransport = '';
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$status = 0;
    Cap2DomainsWire::$curlError = '';
    Cap2DomainsWire::$responses['/billing/integration/group/ML/order/details'] = [200, []];
    Cap2DomainsWire::$onWire = static function () use ($pdo, &$wireStateAtTransport): void {
        $metadata = ApiExecutionMetadataContext::current();
        $captureId = (int) ($metadata['capture_run_id'] ?? 0);
        $wireStateAtTransport = $captureId > 0 ? financial_v2_capture_state($pdo, $captureId) : 'MISSING_CAPTURE_ID';
        Cap2DomainsWire::$status = 200;
        $orderId = (string) ($metadata['external_order_id'] ?? '0');
        Cap2DomainsWire::$raw = json_encode([[
            'order_id' => $orderId,
            'detail_id' => 'fee-' . $orderId,
            'detail_type' => 'SALE_FEE',
            'description' => 'Synthetic billing line',
            'amount' => 10,
            'date_created' => '2026-10-05T00:00:00Z',
        ]], JSON_THROW_ON_ERROR);
    };
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try {
        CronDeadlineContext::start(45, 40, 8, 3);
        (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))->run('test', 1, 40);
    } finally {
        Cap2DomainsWire::$onWire = null;
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
    $captureStmt = $pdo->prepare(
        'SELECT id,request_id,dispatch_state,http_status,response_body_raw,response_hash,external_order_id
         FROM meli_billing_capture_runs WHERE company_id=9001 AND meli_account_id=9011
           AND financial_job_id=? AND dispatch_state IS NOT NULL ORDER BY id DESC LIMIT 1'
    );
    $captureStmt->execute([(int) $wireJob['job']['id']]);
    $wireStored = $captureStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $transportEventStmt = $pdo->prepare(
        'SELECT request_id,dispatch_state,http_status FROM queue_v4_clean_transport_events
         WHERE company_id=9001 AND meli_account_id=9011 AND request_id=?'
    );
    $transportEventStmt->execute([(string) ($wireStored['request_id'] ?? '')]);
    $transportEvent = $transportEventStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $queueAttempt = $pdo->prepare('SELECT dispatch_state,http_status,physical_http_calls FROM queue_v4_clean_attempts WHERE job_id=? AND company_id=9001 AND meli_account_id=9011 ORDER BY id DESC LIMIT 1');
    $queueAttempt->execute([$queueId]);
    $wireAttempt = $queueAttempt->fetch(PDO::FETCH_ASSOC) ?: [];
    $wireOrderId = (string) $wireJob['order_id'];
    $wireBody = (string) ($wireStored['response_body_raw'] ?? '');
    financial_v2_assert(
        $wireStateAtTransport === 'dispatch_committed'
            && ($wireStored['dispatch_state'] ?? '') === 'result_durable'
            && (int) ($wireStored['http_status'] ?? 0) === 200
            && hash_equals((string) ($wireStored['response_hash'] ?? ''), hash('sha256', $wireBody))
            && (string) ($wireStored['external_order_id'] ?? '') === $wireOrderId
            && (string) ($transportEvent['request_id'] ?? '') === (string) ($wireStored['request_id'] ?? '')
            && ($transportEvent['dispatch_state'] ?? '') === 'RESPONSE_KNOWN'
            && (int) ($transportEvent['http_status'] ?? 0) === 200
            && ($wireAttempt['dispatch_state'] ?? '') === 'RESPONSE_KNOWN'
            && (int) ($wireAttempt['physical_http_calls'] ?? 0) === 1,
        'R20_REAL_CURL_BOUNDARY_DURABLE_BEFORE_RETURN'
    );

    // This following focused crash-boundary case must reach the synthetic
    // wire independently of the global pacing permit consumed by R20.
    $pdo->exec("UPDATE api_rhythm_states SET calls_in_block=0,block_started_at=NULL,next_allowed_at='2000-01-01',block_pause_until=NULL WHERE scope_key='global'");
    $pdo->exec("UPDATE api_remote_permits SET status='expired',expires_at='2000-01-01',dispatched_at=NULL,completed_at=NULL,updated_at=UTC_TIMESTAMP(3)");

    // T7: the fake wire returns a successful response, then a test-only
    // interruption fires at curl_error(), the first adapter call after
    // curl_exec() returns and immediately before persistKnownResponse().
    // This exercises the real Queue worker, MeliApiClient, and cURL adapter
    // without adding a product failpoint or making a real network request.
    $t7Job = financial_v2_seed_job($pdo, '881000050', 1, 'pending');
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([(int) $t7Job['job']['id']]);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'domain_exact',?,?,?,'ready','2000-01-01')"
    )->execute([
        (string) $t7Job['job']['id'],
        'financial-v2-t7-' . $t7Job['job']['id'] . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => (int) $t7Job['job']['id']], JSON_THROW_ON_ERROR),
    ]);
    $t7QueueId = (int) $pdo->lastInsertId();
    $t7AfterWire = false;
    $t7ResponseBytesAtInterruption = '';
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$status = 0;
    Cap2DomainsWire::$curlError = '';
    Cap2DomainsWire::$responses['/billing/integration/group/ML/order/details'] = [200, []];
    Cap2DomainsWire::$onWire = static function () use (&$t7AfterWire): void {
        Cap2DomainsWire::$status = 200;
        Cap2DomainsWire::$raw = json_encode([[
            'order_id' => '881000050',
            'detail_id' => 'fee-881000050',
            'detail_type' => 'SALE_FEE',
            'description' => 'Synthetic T7 billing line',
            'amount' => 10,
            'date_created' => '2026-10-05T00:00:00Z',
        ]], JSON_THROW_ON_ERROR);
    };
    Cap2DomainsWire::$afterResponseBeforeDurable = static function () use (&$t7AfterWire, &$t7ResponseBytesAtInterruption): void {
        $t7AfterWire = Cap2DomainsWire::$status === 200 && Cap2DomainsWire::$raw !== '';
        $t7ResponseBytesAtInterruption = Cap2DomainsWire::$raw;
        throw new App\Services\RemoteResultUncertainException(
            (string) (ApiExecutionMetadataContext::current()['transport_request_id'] ?? ''),
            0,
            new RuntimeException('synthetic_T7_interruption_after_wire_before_billing_durability')
        );
    };
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try {
        CronDeadlineContext::start(45, 40, 8, 3);
        try {
            (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))->run('test', 1, 40);
        } catch (Throwable) {
            // The injected exception models process interruption at T7.
        }
    } finally {
        Cap2DomainsWire::$onWire = null;
        Cap2DomainsWire::$afterResponseBeforeDurable = null;
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
    $t7Snapshot = financial_v2_crash_snapshot($pdo, (int) $t7Job['job']['id'], $t7QueueId);
    $t7Capture = $t7Snapshot['capture'] ?? [];
    $t7QueueAttempt = $t7Snapshot['queue_attempt'] ?? [];
    $t7EventStmt = $pdo->prepare(
        'SELECT dispatch_state,http_status FROM queue_v4_clean_transport_events
         WHERE company_id=9001 AND meli_account_id=9011 AND source_kind="queue" AND work_id=? ORDER BY id DESC LIMIT 1'
    );
    $t7EventStmt->execute([$t7QueueId]);
    $t7Event = $t7EventStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $t7CapturesStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM meli_billing_capture_runs
         WHERE company_id=9001 AND meli_account_id=9011 AND financial_job_id=?'
    );
    $t7CapturesStmt->execute([(int) $t7Job['job']['id']]);
    $t7CaptureCount = (int) $t7CapturesStmt->fetchColumn();
    financial_v2_assert(
        $t7AfterWire
            && str_contains($t7ResponseBytesAtInterruption, 'fee-881000050')
            && Cap2DomainsWire::$afterResponseBeforeDurable === null
            && count(Cap2DomainsWire::$calls) === 1
            && ($t7Capture['dispatch_state'] ?? '') === 'dispatch_committed'
            && ($t7Capture['http_status'] ?? null) === null
            && ($t7Capture['response_hash'] ?? null) === null
            && ($t7Capture['body_bytes'] ?? null) === null
            && ($t7Capture['result_durable_at'] ?? null) === null
            && ($t7Snapshot['source']['status'] ?? '') === 'review'
            && ($t7Snapshot['queue']['state'] ?? '') === 'review'
            && ($t7QueueAttempt['dispatch_state'] ?? '') === 'PHYSICAL_STARTED'
            && (int) ($t7QueueAttempt['physical_http_calls'] ?? 0) === 1
            && ($t7Event['dispatch_state'] ?? '') === 'PHYSICAL_STARTED'
            && ($t7Event['http_status'] ?? null) === null
            && $t7CaptureCount === 1,
        'T7_FAKE_HTTP_RETURN_THEN_INTERRUPTION_BEFORE_BILLING_DURABILITY_REVIEW_NO_RETRY',
        [
            'snapshot' => $t7Snapshot,
            'event' => $t7Event,
            'after_wire' => $t7AfterWire,
            'response_bytes' => strlen($t7ResponseBytesAtInterruption),
            'response_has_expected_line' => str_contains($t7ResponseBytesAtInterruption, 'fee-881000050'),
            'hook_cleared' => Cap2DomainsWire::$afterResponseBeforeDurable === null,
            'wire_calls' => count(Cap2DomainsWire::$calls),
            'capture_count' => $t7CaptureCount,
        ]
    );

    $staleWireJob = financial_v2_seed_job($pdo, '881000011', 1, 'pending');
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([(int) $staleWireJob['job']['id']]);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'domain_exact',?,?,?,'ready','2000-01-01')"
    )->execute([
        (string) $staleWireJob['job']['id'],
        'financial-v2-stale-' . $staleWireJob['job']['id'] . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => (int) $staleWireJob['job']['id']], JSON_THROW_ON_ERROR),
    ]);
    $staleQueueId = (int) $pdo->lastInsertId();
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$onWire = null;
    App\Services\CallsWireOptions::$onFinalOptions = static function () use ($pdo, $staleWireJob): void {
        $pdo->prepare(
            'UPDATE sale_financial_reconciliation_jobs
             SET lock_owner=?,lease_generation=lease_generation+1
             WHERE id=? AND company_id=9001 AND meli_account_id=9011 AND status="running"'
        )->execute([bin2hex(random_bytes(16)), (int) $staleWireJob['job']['id']]);
    };
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try {
        CronDeadlineContext::start(45, 40, 8, 3);
        try {
            (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))->run('test', 1, 40);
        } catch (Throwable) {
            // The fixture deliberately invalidates the claim between the
            // Queue fence and Billing's last pre-cURL compare-and-swap.
        }
    } finally {
        App\Services\CallsWireOptions::$onFinalOptions = null;
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
    $staleCaptureStmt = $pdo->prepare(
        'SELECT id,request_id,dispatch_state FROM meli_billing_capture_runs
         WHERE company_id=9001 AND meli_account_id=9011 AND financial_job_id=?
         ORDER BY id DESC LIMIT 1'
    );
    $staleCaptureStmt->execute([(int) $staleWireJob['job']['id']]);
    $staleCapture = $staleCaptureStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $staleAttemptStmt = $pdo->prepare(
        'SELECT dispatch_state,physical_http_calls FROM queue_v4_clean_attempts
         WHERE job_id=? AND company_id=9001 AND meli_account_id=9011 ORDER BY id DESC LIMIT 1'
    );
    $staleAttemptStmt->execute([$staleQueueId]);
    $staleAttempt = $staleAttemptStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $staleEventStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM queue_v4_clean_transport_events
         WHERE company_id=9001 AND meli_account_id=9011 AND request_id=?'
    );
    $staleEventStmt->execute([(string) ($staleCapture['request_id'] ?? '')]);
    financial_v2_assert(
        Cap2DomainsWire::$calls === []
            && ($staleCapture['dispatch_state'] ?? '') === 'aborted_pretransport'
            && ($staleAttempt['dispatch_state'] ?? '') === 'NOT_DISPATCHED'
            && (int) ($staleAttempt['physical_http_calls'] ?? -1) === 0
            && (int) $staleEventStmt->fetchColumn() === 0,
        'R17_STALE_GENERATION_DENIED_AT_REAL_CURL_BOUNDARY_HTTP_ZERO'
    );

    // C0-C5/C7: deterministic durable-state crash ledger. C5/C6/C7
    // intentionally converge to the same durable dispatch state: after
    // permission commits, an absent Billing result is not proof of zero effect.
    Cap2DomainsWire::$calls = [];
    $c0 = financial_v2_seed_job($pdo, '881000030', 1, 'pending', false);
    $pdo->prepare(
        'UPDATE sale_financial_reconciliation_jobs
         SET lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL,last_remote_state=NULL
         WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    )->execute([(int) $c0['job']['id']]);
    $c0Before = financial_v2_crash_snapshot($pdo, (int) $c0['job']['id']);
    $c0After = financial_v2_crash_snapshot($pdo, (int) $c0['job']['id']);
    financial_v2_assert(
        ($c0After['source']['status'] ?? '') === 'pending' && $c0After['capture'] === null,
        'C0_BEFORE_CLAIM_NO_MUTATION'
    );
    financial_v2_crash_ledger('C0', $c0Before, $c0After, 'normal_claim_after_crash', count(Cap2DomainsWire::$calls));

    foreach (['C1', 'C2'] as $case) {
        $job = financial_v2_seed_job($pdo, $case === 'C1' ? '881000031' : '881000032', 1, 'running', true);
        if ($case === 'C2') {
            $validatedVersion = (new SaleFinancialStateService())->currentInputVersionForSale(
                9001,
                9011,
                (string) $job['job']['sale_key']
            );
            financial_v2_assert(hash_equals((string) $job['job']['input_version'], $validatedVersion), 'C2_INPUT_VALIDATION_PRECONDITION');
        }
        $before = financial_v2_crash_snapshot($pdo, (int) $job['job']['id']);
        $action = $authority->recoverExpiredRunning((int) $job['job']['id'], 9001, 9011);
        $after = financial_v2_crash_snapshot($pdo, (int) $job['job']['id']);
        financial_v2_assert($action === 'retry_not_dispatched' && ($after['source']['status'] ?? '') === 'retry', $case . '_EXPIRED_CLAIM_NO_CAPTURE_RETRY');
        financial_v2_crash_ledger($case, $before, $after, $action, count(Cap2DomainsWire::$calls));
    }

    foreach (['C3', 'C4'] as $case) {
        $job = financial_v2_seed_job($pdo, $case === 'C3' ? '881000033' : '881000034');
        $capture = $authority->reserve($job['job'], $job['order_id']);
        $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=? AND company_id=9001 AND meli_account_id=9011')
            ->execute([(int) $job['job']['id']]);
        $before = financial_v2_crash_snapshot($pdo, (int) $job['job']['id']);
        $action = $authority->recoverExpiredRunning((int) $job['job']['id'], 9001, 9011);
        $after = financial_v2_crash_snapshot($pdo, (int) $job['job']['id']);
        financial_v2_assert(
            $action === 'retry_not_dispatched'
                && ($after['capture']['dispatch_state'] ?? '') === 'aborted_pretransport'
                && ($after['source']['status'] ?? '') === 'retry',
            $case . '_RESERVED_CAPTURE_ABORT_AND_RETRY'
        );
        financial_v2_crash_ledger($case, $before, $after, $action, count(Cap2DomainsWire::$calls));
    }

    foreach (['C5', 'C7'] as $case) {
        $job = financial_v2_seed_job($pdo, $case === 'C5' ? '881000035' : '881000037');
        $capture = $authority->reserve($job['job'], $job['order_id']);
        $authority->commitDispatch($capture);
        // C5/C7 model the adjacent process-death boundaries.
        $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=? AND company_id=9001 AND meli_account_id=9011')
            ->execute([(int) $job['job']['id']]);
        // For C7 a response exists only in the synthetic caller's memory and is
        // deliberately not handed to persistKnownResponse(). The durable DB is
        // therefore intentionally indistinguishable from C5/C6.
        $before = financial_v2_crash_snapshot($pdo, (int) $job['job']['id']);
        $action = $authority->recoverExpiredRunning((int) $job['job']['id'], 9001, 9011);
        $after = financial_v2_crash_snapshot($pdo, (int) $job['job']['id']);
        $expectedAction = 'review';
        financial_v2_assert(
            $action === $expectedAction
                && ($after['capture']['dispatch_state'] ?? '') === 'dispatch_committed'
                && ($after['source']['status'] ?? '') === 'review',
            $case . '_DISPATCH_COMMITTED_NEVER_AUTO_RETRIED'
        );
        financial_v2_crash_ledger($case, $before, $after, $action, count(Cap2DomainsWire::$calls));
    }

    // C6: durable-equivalent crash model for a timeout while curl is in flight.
    // Once dispatch_committed is durable, C5/C6/C7 intentionally have the same
    // recoverable database state; no timeout outcome is inferred from Queue.
    $c6Job = financial_v2_seed_job($pdo, '881000036');
    $c6Capture = $authority->reserve($c6Job['job'], $c6Job['order_id']);
    $authority->commitDispatch($c6Capture);
    $pdo->prepare(
        'UPDATE sale_financial_reconciliation_jobs
         SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)
         WHERE id=? AND company_id=9001 AND meli_account_id=9011'
    )->execute([(int) $c6Job['job']['id']]);
    $c6Before = financial_v2_crash_snapshot($pdo, (int) $c6Job['job']['id']);
    Cap2DomainsWire::$calls = [];
    $c6Action = $authority->recoverExpiredRunning((int) $c6Job['job']['id'], 9001, 9011);
    $c6After = financial_v2_crash_snapshot($pdo, (int) $c6Job['job']['id']);
    financial_v2_assert(
        $c6Action === 'review'
            && ($c6After['capture']['dispatch_state'] ?? '') === 'dispatch_committed'
            && ($c6After['source']['status'] ?? '') === 'review'
            && count(Cap2DomainsWire::$calls) === 0,
        'C6_INFLIGHT_TIMEOUT_DURABLE_EQUIVALENT_REVIEW_WITHOUT_REPLAY',
        ['wire_calls' => count(Cap2DomainsWire::$calls), 'action' => $c6Action, 'after' => $c6After]
    );
    financial_v2_crash_ledger('C6', $c6Before, $c6After, $c6Action, count(Cap2DomainsWire::$calls));

    // C9: a durable per-order checkpoint is published offline, then C10
    // observes the finalized Financial state without changing the capture.
    $c9Job = financial_v2_seed_job($pdo, '881000040', 1, 'running', false);
    $c9Capture = $authority->reserve($c9Job['job'], $c9Job['order_id']);
    $authority->commitDispatch($c9Capture);
    $c9Body = json_encode([[
        'order_id' => $c9Job['order_id'],
        'detail_id' => 'c9-fee-' . $c9Job['order_id'],
        'detail_type' => 'SALE_FEE',
        'description' => 'Synthetic C9 official fee',
        'amount' => 10,
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $authority->persistKnownResponse($c9Capture, 200, $c9Body);
    $c9Lines = (new App\Services\SaleBillingParser())->parse(json_decode($c9Body, true, 512, JSON_THROW_ON_ERROR), [$c9Job['order_id']]);
    foreach ($c9Lines as &$c9Line) {
        $c9Line['capture_id'] = (int) $c9Capture['capture_run_id'];
    }
    unset($c9Line);
    $c9Metadata = [
        'status' => 200,
        'headers' => [],
        'request_id' => (string) $c9Capture['request_id'],
        'response_item_count' => count($c9Lines),
    ];
    $pdo->beginTransaction();
    try {
        (new SaleFinancialStateService())->recordBillingOrderCheckpoint(
            $pdo,
            $c9Job['job'],
            (int) $c9Capture['capture_run_id'],
            $c9Job['order_id'],
            $c9Lines,
            'reconciled',
            'Synthetic durable C9 checkpoint',
            hash('sha256', $c9Body),
            200,
            'complete',
            $c9Metadata
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=? AND company_id=9001 AND meli_account_id=9011')
        ->execute([(int) $c9Job['job']['id']]);
    Cap2DomainsWire::$calls = [];
    $c9Before = financial_v2_crash_snapshot($pdo, (int) $c9Job['job']['id']);
    $c9Recovery = $authority->recoverExpiredRunning((int) $c9Job['job']['id'], 9001, 9011);
    $c9AfterRecovery = financial_v2_crash_snapshot($pdo, (int) $c9Job['job']['id']);
    $c9Process = (new SaleFinancialService())->processDomainExactBatch(
        [(int) $c9Job['job']['id']],
        9001,
        9011,
        false
    );
    $c9After = financial_v2_crash_snapshot($pdo, (int) $c9Job['job']['id']);
    $c9EvidenceStmt = $pdo->prepare(
        'SELECT evidence_status,evidence_json FROM sale_financial_evidence
         WHERE company_id=9001 AND meli_account_id=9011 AND sale_key=? AND input_version=?
           AND evidence_type="billing_capture" AND source_id=?'
    );
    $c9EvidenceStmt->execute([
        (string) $c9Job['job']['sale_key'],
        (string) $c9Job['job']['input_version'],
        (int) $c9Capture['capture_run_id'],
    ]);
    $c9CheckpointCount = 0;
    $c9AggregateCount = 0;
    foreach ($c9EvidenceStmt->fetchAll(PDO::FETCH_ASSOC) as $c9EvidenceRow) {
        $c9Payload = json_decode((string) $c9EvidenceRow['evidence_json'], true);
        if (!is_array($c9Payload) || (string) $c9EvidenceRow['evidence_status'] !== 'reconciled') {
            continue;
        }
        if ((string) ($c9Payload['format'] ?? '') === 'billing_order_v2') {
            $c9CheckpointCount++;
        } elseif (is_array($c9Payload['totals'] ?? null)) {
            $c9AggregateCount++;
        }
    }
    financial_v2_assert(
        $c9Recovery === 'offline_reconcile'
            && ($c9AfterRecovery['source']['status'] ?? '') === 'retry'
            && ($c9After['source']['status'] ?? '') === 'complete'
            && ($c9After['capture']['dispatch_state'] ?? '') === 'result_durable'
            && $c9CheckpointCount === 1
            && $c9AggregateCount === 1
            && ($c9Process['summary']['completed'] ?? 0) === 1
            && Cap2DomainsWire::$calls === [],
        'C9_CHECKPOINT_EVIDENCE_PUBLISHED_OFFLINE_AND_SOURCE_FINALIZED',
        [
            'recovery' => $c9Recovery,
            'after_recovery' => $c9AfterRecovery,
            'after' => $c9After,
            'process' => $c9Process,
            'checkpoint_evidence_count' => $c9CheckpointCount,
            'aggregate_evidence_count' => $c9AggregateCount,
            'wire_calls' => Cap2DomainsWire::$calls,
        ]
    );
    financial_v2_crash_ledger('C9', $c9Before, $c9After, 'offline_checkpoint_publication_zero_http', count(Cap2DomainsWire::$calls));

    $c10Before = financial_v2_crash_snapshot($pdo, (int) $c9Job['job']['id']);
    $c10Action = $authority->recoverExpiredRunning((int) $c9Job['job']['id'], 9001, 9011);
    $c10After = financial_v2_crash_snapshot($pdo, (int) $c9Job['job']['id']);
    financial_v2_assert(
        $c10Action === 'not_expired_or_not_v2'
            && $c10Before === $c10After
            && Cap2DomainsWire::$calls === [],
        'C10_FINAL_FINANCIAL_STATE_IS_NOT_REOPENED'
    );
    financial_v2_crash_ledger('C10', $c10Before, $c10After, $c10Action, count(Cap2DomainsWire::$calls));

    // C11: the real Queue V4 worker finalizes a ready pointer for already-
    // durable Financial work without calling the fake wire again.
    $pdo->exec(
        "UPDATE queue_v4_clean_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY)
         WHERE company_id=9001 AND meli_account_id=9011 AND state IN ('ready','waiting')"
    );
    $c11QueueId = financial_v2_seed_queue_pointer($pdo, (int) $c9Job['job']['id'], 'financial-v2-c11');
    $pdo->prepare('UPDATE queue_v4_clean_jobs SET available_at="2000-01-01" WHERE id=?')
        ->execute([$c11QueueId]);
    $c11Before = financial_v2_crash_snapshot($pdo, (int) $c9Job['job']['id'], $c11QueueId);
    Cap2DomainsWire::$calls = [];
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try {
        CronDeadlineContext::start(45, 40, 8, 3);
        (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))->run('test', 1, 40);
    } finally {
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
    $c11After = financial_v2_crash_snapshot($pdo, (int) $c9Job['job']['id'], $c11QueueId);
    financial_v2_assert(
        Cap2DomainsWire::$calls === []
            && ($c11After['source']['status'] ?? '') === 'complete'
            && in_array(($c11After['queue']['state'] ?? ''), ['completed', 'done'], true),
        'C11_QUEUE_POINTER_BOOKKEEPING_FINISHES_WITHOUT_DOMAIN_REEXECUTION'
    );
    financial_v2_crash_ledger('C11', $c11Before, $c11After, 'queue_bookkeeping_only_zero_http', count(Cap2DomainsWire::$calls));

    echo "STATUS=PASS FINANCIAL_V2_BILLING_CAPTURE_AUTHORITY MYSQL=DISPOSABLE REAL_MELI_HTTP=0 REAL_OAUTH=0" . PHP_EOL;
} finally {
    $harness->cleanup();
}
