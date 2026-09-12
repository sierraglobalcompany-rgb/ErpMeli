<?php
declare(strict_types=1);

// Direct finish cases are real-PDO CAS integration, NOT launcher integration.
// Legacy-named cases exercise the real processExact -> processSelected catch
// under a supported V4 repository claim/transport identity, NOT retired cron.
// Only final cURL is intercepted; no product service or transport double.
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_true_wire_fixture.php';
require __DIR__ . '/calls_true_seed_fixture.php';

use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiRhythmDeferredException;
use App\Services\AppSettingsService;
use App\Services\CallsTrueWire;
use App\Services\MeliApiException;
use App\Services\RemoteResultUncertainException;
use App\Services\SaleFinancialService;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;

function finishSafetySnapshot(PDO $pdo): array
{
    $snapshot = [];
    foreach (['sale_financial_reconciliation_jobs', 'sale_financial_state', 'sale_financial_evidence',
        'meli_sale_financials', 'meli_billing_capture_runs', 'queue_v4_clean_jobs', 'queue_v4_clean_attempts'] as $table) {
        $snapshot[$table] = $pdo->query('SELECT * FROM ' . $table
            . ' WHERE company_id=9001 AND meli_account_id=9011 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach (['meli_sale_financial_history', 'meli_sale_financial_lines', 'meli_sale_financial_allocations'] as $table) {
        $snapshot[$table] = $pdo->query('SELECT h.* FROM ' . $table . ' h
            JOIN meli_sale_financials f ON f.id=h.meli_sale_financial_id
            WHERE f.company_id=9001 AND f.meli_account_id=9011 ORDER BY h.id')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $snapshot;
}

function finishSafetySource(PDO $pdo, int $id): array
{
    $q = $pdo->prepare('SELECT * FROM sale_financial_reconciliation_jobs
        WHERE id=? AND company_id=9001 AND meli_account_id=9011');
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    true_seed_assert(is_array($row), 'SOURCE_EXISTS');
    return $row;
}

function finishSafetyDirect(PDO $pdo, array $source, string $case): array
{
    $q = $pdo->prepare("UPDATE sale_financial_reconciliation_jobs SET status='running',
        lock_owner='synthetic-finish-owner',lease_generation=7,attempts=3,
        lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),heartbeat_at=UTC_TIMESTAMP(),
        safe_message='before finish',remote_pending_since='2026-01-01 00:00:00',
        retry_until='2099-01-01 00:00:00'
        WHERE id=? AND company_id=9001 AND meli_account_id=9011");
    $q->execute([$source['source']]);
    if ($case === 'valid_retry_delay') {
        $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET remote_pending_since=NULL,retry_until=NULL
            WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([$source['source']]);
    }
    $job = finishSafetySource($pdo, $source['source']);
    // Alter the caller's scope only: a bad company/account must not close this row.
    if ($case === 'wrong_company') $job['company_id'] = 9002;
    if ($case === 'wrong_account') $job['meli_account_id'] = 9012;
    if ($case === 'wrong_owner') $job['lock_owner'] = 'synthetic-stale-owner';
    if ($case === 'wrong_generation') $job['lease_generation'] = 6;
    $changes = [
        'wrong_status' => "status='pending'",
        'expired_lease' => "lease_expires_at='2000-01-01 00:00:00'",
        'null_lease' => 'lease_expires_at=NULL',
    ];
    if (isset($changes[$case])) {
        $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET ' . $changes[$case]
            . ' WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([$source['source']]);
    }
    $before = finishSafetySnapshot($pdo);
    $beforeSource = finishSafetySource($pdo, $source['source']);
    $finish = new ReflectionMethod(SaleFinancialService::class, 'finish');
    $service = new SaleFinancialService();
    $status = $case === 'valid_defer' ? 'awaiting_remote' : ($case === 'valid_retry_delay' ? 'retry' : 'complete');
    $minimum = $case === 'valid_defer' ? '2090-01-01 00:00:00' : null;
    $error = null;
    $started = (string) $pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
    try {
        $finish->invoke($service, $job, $status, 'synthetic finish result', $minimum);
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $after = finishSafetySnapshot($pdo);
    $row = finishSafetySource($pdo, $source['source']);
    $valid = in_array($case, ['valid_complete', 'valid_defer', 'valid_retry_delay'], true);
    true_seed_assert(CallsTrueWire::$entries === [] && CallsTrueWire::$violations === [], 'DIRECT_CAS_ZERO_WIRE');
    if (!$valid) {
        true_seed_assert($after === $before, 'REJECTED_FINISH_ZERO_WRITES');
        true_seed_assert($error instanceof RuntimeException && !$error instanceof PDOException, 'REJECTED_FINISH_SIGNALS_CAS_LOSS');
        return ['kind' => 'direct_real_pdo_cas_not_launcher', 'zero_writes' => true, 'rejected' => true];
    }
    true_seed_assert($error === null, 'VALID_FINISH_ACCEPTED');
    true_seed_assert($row['status'] === $status && $row['safe_message'] === 'synthetic finish result', 'VALID_STATUS_AND_MESSAGE');
    true_seed_assert($row['lock_owner'] === null && $row['lease_expires_at'] === null && $row['heartbeat_at'] === null, 'VALID_FINISH_CLEARS_LEASE');
    true_seed_assert($row['attempts'] === $beforeSource['attempts'] && $row['lease_generation'] === $beforeSource['lease_generation']
        && $row['input_version'] === $beforeSource['input_version'], 'VALID_FINISH_PRESERVES_ATTEMPTS_GENERATION_INPUT');
    if ($case !== 'valid_retry_delay') {
        true_seed_assert($row['remote_pending_since'] === $beforeSource['remote_pending_since']
            && $row['retry_until'] === $beforeSource['retry_until'], 'VALID_FINISH_PRESERVES_EXISTING_RETRY_HORIZON');
    }
    if ($case === 'valid_complete') {
        true_seed_assert($row['completed_at'] !== null && $row['next_run_at'] === $beforeSource['next_run_at'], 'COMPLETE_STAMPS_COMPLETION_PRESERVES_NEXT');
    } elseif ($case === 'valid_defer') {
        true_seed_assert($row['completed_at'] === $beforeSource['completed_at']
            && str_starts_with($row['next_run_at'], $minimum), 'DEFER_MINIMUM_NEXT_AND_NO_FALSE_COMPLETION');
    } else {
        $ended = (string) $pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        $next = strtotime($row['next_run_at'] . ' UTC');
        true_seed_assert($next >= strtotime($started . ' UTC') + 900
            && $next <= strtotime($ended . ' UTC') + 900, 'RETRY_THIRD_ATTEMPT_DELAY_15_MINUTES');
        true_seed_assert($row['completed_at'] === null && $row['remote_pending_since'] !== null
            && $row['retry_until'] !== null
            && strtotime($row['retry_until'] . ' UTC') - strtotime($row['remote_pending_since'] . ' UTC') === 30 * 86400,
            'RETRY_INITIALIZES_30_DAY_HORIZON_WITHOUT_COMPLETION');
    }
    unset($before['sale_financial_reconciliation_jobs'], $after['sale_financial_reconciliation_jobs']);
    true_seed_assert($after === $before, 'VALID_FINISH_NO_ADJACENT_BUSINESS_WRITES');
    $duplicateBefore = finishSafetySnapshot($pdo);
    $duplicateError = null;
    try {
        $finish->invoke($service, $job, $status, 'duplicate must not write', $minimum);
    } catch (Throwable $caught) {
        $duplicateError = $caught;
    }
    true_seed_assert($duplicateError instanceof RuntimeException && !$duplicateError instanceof PDOException
        && finishSafetySnapshot($pdo) === $duplicateBefore, 'DUPLICATE_REJECTED_WITH_ZERO_WRITES');
    return ['kind' => 'direct_real_pdo_cas_not_launcher', 'lease_cleared' => true, 'duplicate_rejected' => true];
}

function finishSafetyLegacy(PDO $pdo, array $source, string $case): array
{
    $validOwner = str_starts_with($case, 'valid_owner_');
    $responseKind = substr($case, $validOwner ? 12 : 7);
    $repo = new QueueV4CleanRepository($pdo);
    $owner = 'synthetic-finish-v4-owner';
    $run = $repo->beginRun('test', $owner);
    $pointer = $repo->claim($run, $owner, 120, [9011], 9011);
    true_seed_assert(is_array($pointer) && (int) $pointer['id'] === $source['queue'][0], 'REAL_V4_EXACT_POINTER_CLAIMED');
    // Mirrors QueueV4CleanWorker's Billing metadata using the actual claimed row.
    $metadata = ['source' => 'queue_v4_clean_domain_exact', 'job_type' => 'domain_exact',
        'company_id' => 9001, 'account_id' => 9011, 'source_queue_key' => 'financial_reconciliation',
        'source_work_id' => (string) $source['source'], 'bulk' => false,
        'queue_v4_job_id' => (int) $pointer['id'], 'queue_v4_attempt_id' => (int) $pointer['attempt_id'],
        'queue_v4_lease_owner' => $pointer['lease_owner'], 'queue_v4_lease_generation' => (int) $pointer['lease_generation']];
    $atSeam = null;
    CallsTrueWire::$respond = static function (array $entry) use ($pdo, $source, $responseKind, $validOwner, &$atSeam): array {
        true_seed_assert(count(CallsTrueWire::$entries) === 1, 'LEGACY_ONE_PHYSICAL_DISPATCH');
        $response = true_seed_response($entry, $source, $responseKind === 'uncertain' ? 'timeout' : $responseKind);
        if (!$validOwner) {
            $q = $pdo->prepare("UPDATE sale_financial_reconciliation_jobs
                SET lock_owner='synthetic-replacement-owner',lease_generation=lease_generation+1
                WHERE id=? AND company_id=9001 AND meli_account_id=9011 AND status='running'");
            $q->execute([$source['source']]);
            true_seed_assert($q->rowCount() === 1, 'LEGACY_LEASE_INVALIDATED_AT_FINAL_CURL');
        }
        $atSeam = finishSafetySnapshot($pdo);
        return $response;
    };
    $error = null;
    $summary = null;
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try {
        $summary = ApiExecutionMetadataContext::run($metadata,
            static fn(): array => (new SaleFinancialService())->processExact($source['source']));
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
    $diagnostic = ['caught_type' => $error === null ? null : get_class($error),
        'caught_file' => $error === null ? null : basename($error->getFile()), 'caught_line' => $error?->getLine(),
        'summary' => $summary === null ? null : array_intersect_key($summary, array_flip(['processed', 'completed', 'errors', 'deferred', 'stop_reason']))];
    true_seed_assert(count(CallsTrueWire::$entries) === 1 && CallsTrueWire::$violations === [], 'LEGACY_REAL_ENTRY_REACHED_ONLY_EXPECTED_WIRE', $diagnostic);
    $after = finishSafetySnapshot($pdo);
    // The real transport must write its known/uncertain response evidence after
    // the seam. Only that attempt evidence is excluded from business immutability.
    unset($atSeam['queue_v4_clean_attempts'], $after['queue_v4_clean_attempts']);
    $attempt = $pdo->prepare('SELECT physical_http_calls,dispatch_state,http_status FROM queue_v4_clean_attempts
        WHERE id=? AND company_id=9001 AND meli_account_id=9011');
    $attempt->execute([$pointer['attempt_id']]);
    $attemptRow = $attempt->fetch(PDO::FETCH_ASSOC);
    true_seed_assert((int) $attemptRow['physical_http_calls'] === 1, 'REAL_V4_ATTEMPT_MATCHES_ONE_WIRE');
    if ($responseKind !== 'uncertain') {
        true_seed_assert($attemptRow['dispatch_state'] === 'RESPONSE_KNOWN'
            && (int) $attemptRow['http_status'] === (int) $responseKind, 'REAL_V4_KNOWN_RESPONSE_DURABLE');
    }
    if ($validOwner) {
        $row = finishSafetySource($pdo, $source['source']);
        true_seed_assert($row['status'] === ($responseKind === 'uncertain' ? 'review' : 'retry')
            && $row['lock_owner'] === null && $row['lease_expires_at'] === null && $row['heartbeat_at'] === null,
            'VALID_OWNER_ORIGINAL_FAILURE_HAS_DURABLE_DISPOSITION', $diagnostic);
        if ($responseKind === '429') {
            true_seed_assert($error instanceof ApiRhythmDeferredException && $summary === null, 'VALID_OWNER_429_PRESERVES_DOMAIN_DEFER');
        } else {
            true_seed_assert($error === null && $summary['stop_reason'] === ($responseKind === 'uncertain' ? 'action_required' : 'http_5xx'),
                'VALID_OWNER_FAILURE_RETAINS_EXISTING_SUMMARY', $diagnostic);
        }
        unset($atSeam['sale_financial_reconciliation_jobs'], $after['sale_financial_reconciliation_jobs']);
        true_seed_assert($atSeam === $after, 'VALID_OWNER_DISPOSITION_NO_ADJACENT_BUSINESS_WRITES');
        return ['kind' => 'real_processExact_entry_supported_v4_claim_final_curl_seam', 'valid_owner_control' => true,
            'summary' => $diagnostic['summary'], 'original_exception_type' => $diagnostic['caught_type']];
    }
    true_seed_assert($atSeam !== null && $after === $atSeam, 'LEGACY_SECONDARY_FINISH_ZERO_BUSINESS_WRITES');
    true_seed_assert($summary === null, 'LEGACY_CLOSE_FAILURE_NOT_SUCCESS_SUMMARY');
    $expected = $responseKind === 'uncertain'
        ? $error instanceof RemoteResultUncertainException
        : ($responseKind === '429' ? $error instanceof ApiRhythmDeferredException
            : $error instanceof MeliApiException && $error->httpStatus === (int) $responseKind);
    true_seed_assert($expected, 'LEGACY_ORIGINAL_TYPED_EXCEPTION_SURVIVES_SECONDARY_CLOSE', ['actual_type' => $error === null ? null : get_class($error)]);
    return ['kind' => 'real_processExact_entry_supported_v4_claim_final_curl_seam', 'original_exception_type' => get_class($error),
        'http_status' => $responseKind === 'uncertain' ? null : (int) $responseKind, 'secondary_finish_zero_writes' => true];
}

true_seed_assert(PHP_SAPI === 'cli', 'CLI_ONLY');
$options = getopt('', ['template:', 'case:']);
$cases = ['wrong_company', 'wrong_account', 'wrong_status', 'wrong_owner', 'wrong_generation',
    'expired_lease', 'null_lease', 'valid_complete', 'valid_defer', 'valid_retry_delay', 'legacy_429', 'legacy_500', 'legacy_uncertain',
    'valid_owner_429', 'valid_owner_500', 'valid_owner_uncertain'];
$requested = (string) ($options['case'] ?? 'all');
true_seed_assert($requested === 'all' || in_array($requested, $cases, true), 'KNOWN_FINISH_CASE');
$root = rtrim(str_replace('\\', '/', (string) (getenv('CALLS_R1_QA_ROOT') ?: '')), '/');
true_seed_assert((str_starts_with($root, 'D:/Codex/') || str_starts_with($root, 'C:/codex/capacity-save-kiss/')) && !in_array('..', explode('/', $root), true), 'EXPLICIT_LOCAL_QA_ROOT_REQUIRED');
$dir = $root . '/finish-safety-' . $requested . '-' . bin2hex(random_bytes(5));
true_seed_assert(mkdir($dir, 0770, true), 'FINISH_DIAGNOSTIC_DIRECTORY');
if (!defined('ERP_INSTALLATION_ROOT')) define('ERP_INSTALLATION_ROOT', $dir . '/install');
true_seed_assert(is_dir(ERP_INSTALLATION_ROOT) || mkdir(ERP_INSTALLATION_ROOT, 0770, true), 'FINISH_INSTALL_DIRECTORY');
$exit = 0;
foreach ($requested === 'all' ? $cases : [$requested] as $case) {
    $database = 'erp_meli_k1d_test_finish_' . bin2hex(random_bytes(8));
    foreach (['APP_ENV' => 'test', 'ML_WRITE_ENABLED' => 'false', 'DB_NAME' => $database,
        'APP_KEY' => 'synthetic-finish-safety-only', 'PRIVATE_STORAGE_PATH' => $dir . '/private',
        'MELI_API_BASE' => 'https://calls-wire.invalid'] as $name => $value) putenv($name . '=' . $value);
    K1dSafeTestDatabase::assertGuard('test', 'false', (string) getenv('DB_HOST'), $database);
    $ledger = ['owner' => 'calls_local_finish_safety_mysql.php', 'case' => $case, 'pid' => getmypid(),
        'db_name' => $database, 'db_host' => (string) getenv('DB_HOST'), 'db_port' => (string) (getenv('DB_PORT') ?: '3306'),
        'schema_target' => 301, 'state' => 'OWNERSHIP_RECORDED_BEFORE_CREATE', 'real_meli_http' => 0];
    true_seed_assert(file_put_contents($dir . '/' . $case . '-ownership.json', json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) !== false,
        'PRECREATE_OWNERSHIP_DURABLE');
    echo 'OWNERSHIP=' . $dir . '/' . $case . '-ownership.json' . PHP_EOL;
    $harness = null;
    CallsTrueWire::$entries = CallsTrueWire::$violations = CallsTrueWire::$options = CallsTrueWire::$response = [];
    CallsTrueWire::$ledger = $dir . '/' . $case . '-wire.jsonl';
    CallsTrueWire::$respond = static function (array $entry): array { throw new RuntimeException('DIRECT_CAS_MUST_NOT_REACH_WIRE'); };
    try {
        $harness = K1dSafeTestDatabase::createFromEnvironment();
        $pdo = $harness->pdo();
        App\Services\SchemaInspectorService::clearCache();
        $ledger['database_version'] = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (isset($options['template'])) {
            $template = realpath((string) $options['template']);
            true_seed_assert($template !== false && (str_starts_with(str_replace('\\', '/', $template), 'D:/Codex/') || str_starts_with(str_replace('\\', '/', $template), 'C:/codex/capacity-save-kiss/')), 'LOCAL_SCHEMA_TEMPLATE_REQUIRED');
            foreach (json_decode((string) file_get_contents($template), true, 512, JSON_THROW_ON_ERROR) as $sql) $pdo->exec($sql);
        } else {
            (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
        }
        AppSettingsService::clearCache();
        true_seed_scope($pdo, 1);
        $source = true_seed_source($pdo, 971, true, 1);
        $ledger['assertions'] = str_starts_with($case, 'legacy_') || str_starts_with($case, 'valid_owner_')
            ? finishSafetyLegacy($pdo, $source, $case) : finishSafetyDirect($pdo, $source, $case);
        $ledger['state'] = 'PASS';
    } catch (Throwable $error) {
        $exit = 1;
        $ledger['state'] = 'FAIL';
        $ledger['failure'] = ['type' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()];
        if (str_starts_with($error->getMessage(), 'INVARIANT:')) $ledger['failure']['invariant'] = $error->getMessage();
    } finally {
        $ledger['wire_count'] = count(CallsTrueWire::$entries);
        $ledger['wire_violations'] = CallsTrueWire::$violations;
        // Never serialize source rows, lease owners, token values or arbitrary errors.
        file_put_contents($dir . '/' . $case . '-result.json', json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
        echo $case . '=' . $ledger['state'] . ' RESULT=' . $dir . '/' . $case . '-result.json' . PHP_EOL;
        if ($harness !== null) $harness->cleanup();
    }
}
exit($exit);
