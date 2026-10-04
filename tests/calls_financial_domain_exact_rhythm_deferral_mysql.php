<?php
declare(strict_types=1);

namespace App\Services {
    // Synthetic clocks advance safe deadlines without altering claimed business rows.
    function time(): int { return \time() + (int) CallsWireOptions::$clockOffset; }
}
namespace App\QueueV4Clean {
    function time(): int { return \App\Services\time(); }
    function microtime(bool $float = false): float|string { return \App\Services\microtime($float); }
}
namespace {
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_transport_wire_fixture.php';

use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiRhythmDeferredException;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;
use App\Services\CallsWireOptions;
use App\Services\CronDeadlineContext;
use App\Services\SaleFinancialService;
use App\Services\SaleFinancialStateService;

// Synthetic seed adapted from calls_final_billing_checkpoint_continuation_mysql;
// shared accepted fixtures and their runner remain unchanged.
function financial_rhythm_seed_scope(PDO $pdo, int $budget, int $billingIntervalSeconds): void
{
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Calls billing continuation',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Calls billing account',99011,'conectado')");
    $pdo->prepare(
        'INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at)
         VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))'
    )->execute([Crypto::encrypt('test-access'), Crypto::encrypt('test-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");

    $settings = new AppSettingsService();
    foreach ([
        'automation.max_api_calls_per_cycle' => (string) $budget,
        'automation.api_calls_ceiling' => '55',
        'api.rhythm.pause_ms' => '0',
        'api.rhythm.burst_size' => '100',
        'api.rhythm.minimum_interval_ms' => '0',
        'api.rhythm.current_adaptive_limit' => '100',
        'api.rhythm.billing_min_interval_seconds' => (string) $billingIntervalSeconds,
        'api.rhythm.shared_429_jitter_seconds' => '0',
        'sales_financial.commercial_pipeline_enabled' => '1',
    ] as $key => $value) {
        $settings->set($key, $value, 'calls-final-billing-continuation');
    }
    AppSettingsService::clearCache();
}

/** @return array{source_id:int,queue_id:int,pack_id:string,orders:list<string>} */
function financial_rhythm_seed_pack(PDO $pdo, int $orderCount): array
{
    $packId = '991' . random_int(100, 999);
    $externalOrders = [];
    for ($i = 1; $i <= $orderCount; $i++) {
        $externalOrders[] = $packId . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
    }
    $pdo->prepare(
        "INSERT INTO meli_packs(meli_account_id,external_pack_id,status,integrity_status,expected_orders_count,linked_orders_count,expected_orders_json,synced_at,verified_at)
         VALUES(9011,?,'paid','complete',?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    )->execute([$packId, $orderCount, $orderCount, json_encode($externalOrders, JSON_THROW_ON_ERROR)]);

    $orderInsert = $pdo->prepare(
        "INSERT INTO meli_orders
            (meli_account_id,external_order_id,external_pack_id,status,total_amount,paid_amount,currency_id,synced_at)
         VALUES(9011,?,?, 'paid',100,100,'COP',UTC_TIMESTAMP())"
    );
    $itemInsert = $pdo->prepare(
        "INSERT INTO meli_order_items
            (meli_order_id,meli_account_id,external_item_id,title,seller_sku,quantity,unit_price,sale_fee)
         VALUES(?,9011,?,?,?,1,100,10)"
    );
    foreach ($externalOrders as $externalOrder) {
        $orderInsert->execute([$externalOrder, $packId]);
        $orderId = (int) $pdo->lastInsertId();
        $itemInsert->execute([$orderId, 'ITEM-' . $externalOrder, 'Order ' . $externalOrder, 'SKU-' . $externalOrder]);
    }

    $state = (new SaleFinancialStateService())->projectSale(9001, 9011, 'P:' . $packId);
    $pdo->prepare(
        "INSERT INTO sale_financial_reconciliation_jobs
         (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at)
         VALUES(9001,9011,?,?,?,'pending','2000-01-01')"
    )->execute(['P:' . $packId, $packId, $state['input_version']]);
    $sourceId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'domain_exact',?,? ,?,'ready','2000-01-01')"
    )->execute([
        (string) $sourceId,
        'billing-continuation-' . $sourceId . '-' . bin2hex(random_bytes(4)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
    ]);

    return [
        'source_id' => $sourceId,
        'queue_id' => (int) $pdo->lastInsertId(),
        'pack_id' => $packId,
        'orders' => $externalOrders,
    ];
}

function financial_rhythm_configure_wire(): void
{
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$responses = ['/billing/integration/group/ML/order/details' => [200, []]];
    Cap2DomainsWire::$onWire = static function (): void {
        $last = Cap2DomainsWire::$calls[array_key_last(Cap2DomainsWire::$calls)] ?? [];
        $orderId = (string) (($last['query']['order_ids'] ?? '') ?: '0');
        Cap2DomainsWire::$status = 200;
        Cap2DomainsWire::$raw = json_encode([[
            'order_id' => $orderId,
            'detail_id' => 'fee-' . $orderId,
            'detail_type' => 'SALE_FEE',
            'description' => 'Cargo por venta ' . $orderId,
            'amount' => 10,
            'date_created' => '2026-09-08T00:00:00Z',
        ]], JSON_THROW_ON_ERROR);
    };
}


function financial_rhythm_assert(bool $ok, string $name, array $context = []): void
{
    if (!$ok) throw new RuntimeException('FAIL:' . $name . ':' . json_encode($context, JSON_THROW_ON_ERROR));
    echo 'PASS:' . $name . PHP_EOL;
}
function financial_rhythm_worker(PDO $pdo, ?callable $factory = null): array
{
    CronDeadlineContext::start(45, 40, 8, 3);
    QueueV4CleanCycleBudget::start(1, 'automatic', \App\Services\microtime(true) + 45);
    try {
        return (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo), null, null, null, null, $factory))->run('test', 1, 40);
    } finally {
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
}
$options = getopt('', ['case:', 'claim-existing:']);
$cases = ['pretransport', 'global', 'billing', 'interval', 'known429', 'cas_owner', 'cas_generation',
    'cas_company', 'cas_account', 'cas_status', 'valid_lease', 'cleanup_loss', 'pending', 'retry', 'awaiting_remote'];
if (!isset($options['case']) && !isset($options['claim-existing'])) {
    // Each case owns a fresh PDO/database and process-local policy caches.
    $exit = 0;
    foreach ($cases as $childCase) {
        $child = proc_open([PHP_BINARY, __FILE__, '--case=' . $childCase],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($child)) { $exit = 1; continue; }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        echo $stdout;
        fwrite(STDERR, $stderr);
        if (proc_close($child) !== 0) $exit = 1;
    }
    exit($exit);
}
if (isset($options['claim-existing'])) {
    // Separate PHP process, same owned disposable DB; never adopt a product DB.
    $existing = K1dSafeTestDatabase::connectExistingFromEnvironment();
    $pdo = $existing->pdo();
    $claim = new ReflectionMethod(SaleFinancialService::class, 'claimSpecificBillingJob');
    financial_rhythm_assert($claim->invoke(new SaleFinancialService(), (int) $options['claim-existing'], 9001, 9011) === null,
        'separate_process_cannot_claim_valid_running_lease');
    exit(0);
}
$case = (string) ($options['case'] ?? 'pretransport');
financial_rhythm_assert(in_array($case, [...$cases, 'budget_sibling'], true), 'known_case');
$root = (string) getenv('CALLS_QA_STORAGE_ROOT');
financial_rhythm_assert($root !== '' && is_dir($root), 'explicit_owned_test_root');
putenv('DB_NAME=erp_meli_k1d_test_finrhythm_' . bin2hex(random_bytes(5)));
putenv('APP_KEY=synthetic-financial-rhythm-only');
putenv('MELI_API_BASE=https://no-network.invalid');
putenv('PRIVATE_STORAGE_PATH=' . $root . '/private');
define('ERP_INSTALLATION_ROOT', $root . '/install-' . $case . '-' . bin2hex(random_bytes(3)));
mkdir(ERP_INSTALLATION_ROOT, 0770, true);
$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
    financial_rhythm_seed_scope($pdo, 1, 1);
    $f = financial_rhythm_seed_pack($pdo, 2);
    financial_rhythm_configure_wire();
    $repo = new QueueV4CleanRepository($pdo);
    $source = static function () use ($pdo, $f): array {
        $q = $pdo->prepare('SELECT * FROM sale_financial_reconciliation_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011');
        $q->execute([$f['source_id']]); return $q->fetch(PDO::FETCH_ASSOC);
    };
    $pointer = static function () use ($pdo, $f): array {
        $q = $pdo->prepare('SELECT state,available_at,attempt_count,last_error_class FROM queue_v4_clean_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011');
        $q->execute([$f['queue_id']]); return $q->fetch(PDO::FETCH_ASSOC);
    };
    $claim = new ReflectionMethod(SaleFinancialService::class, 'claimSpecificBillingJob');
    $claimNow = static fn() => $claim->invoke(new SaleFinancialService(), $f['source_id'], 9001, 9011);
    $safe = gmdate('Y-m-d H:i:s', \time() + 600);
    $advance = static function (int $at) use ($pdo): void {
        CallsWireOptions::$clockOffset = $at - \time();
        $pdo->exec('SET timestamp=' . $at);
    };
    $before = $source();

    if (str_starts_with($case, 'cas_') || $case === 'valid_lease') {
        $job = $claimNow();
        financial_rhythm_assert(is_array($job), 'real_source_claim');
        financial_rhythm_assert($claimNow() === null, 'valid_running_lease_not_reclaimed');
        if ($case !== 'valid_lease') {
            $field = substr($case, 4);
            if ($field === 'owner') $job['lock_owner'] = 'wrong-owner';
            if ($field === 'generation') $job['lease_generation']++;
            if ($field === 'company') $job['company_id'] = 9002;
            if ($field === 'account') $job['meli_account_id'] = 9012;
            if ($field === 'status') $pdo->prepare("UPDATE sale_financial_reconciliation_jobs SET status='pending' WHERE id=? AND company_id=9001 AND meli_account_id=9011")->execute([$f['source_id']]);
            $snapshot = $source();
            $caught = null;
            try {
                (new ReflectionMethod(SaleFinancialService::class, 'deferWithoutAttemptPenalty'))->invoke(new SaleFinancialService(), $job, 'synthetic', $safe);
            } catch (Throwable $e) { $caught = $e; }
            financial_rhythm_assert($caught instanceof RuntimeException && !$caught instanceof PDOException, 'fence_rejects_wrong_' . $field);
            financial_rhythm_assert($source() === $snapshot, 'fence_rejection_zero_source_writes');
        } else {
            $snapshot = $source();
            $process = proc_open([PHP_BINARY, __FILE__, '--claim-existing=' . $f['source_id']],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            financial_rhythm_assert(is_resource($process), 'separate_claim_process_started');
            fclose($pipes[0]);
            $childOut = stream_get_contents($pipes[1]);
            $childErr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            financial_rhythm_assert(proc_close($process) === 0 && $childErr === '', 'separate_process_lease_fence', ['stdout' => $childOut]);
            financial_rhythm_worker($pdo);
            financial_rhythm_assert($source() === $snapshot && Cap2DomainsWire::$calls === [], 'other_process_running_owner_preserved');
        }
    } elseif (in_array($case, ['pending', 'retry', 'awaiting_remote'], true)) {
        $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET status=? WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([$case, $f['source_id']]);
        financial_rhythm_worker($pdo);
        financial_rhythm_assert(count(Cap2DomainsWire::$calls) === 1, 'healthy_single_order_physical_checkpoint');
        financial_rhythm_assert($source()['status'] === 'retry' && $source()['lock_owner'] === null, 'healthy_checkpoint_releases_source');
        financial_rhythm_assert((string) Cap2DomainsWire::$calls[0]['query']['order_ids'] === $f['orders'][0], 'healthy_billing_one_fifo_order');
    } elseif ($case === 'cleanup_loss' || $case === 'budget_sibling') {
        $original = new ApiRhythmDeferredException('Synthetic deferral', $safe, 'rhythm_block_pause');
        if ($case === 'budget_sibling') QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
        $service = new SaleFinancialService(static function (int $account) use ($pdo, $f, &$original, $case) {
            if ($case === 'budget_sibling') {
                QueueV4CleanCycleBudget::reserve(str_repeat('a', 40), 'queue_v4_clean_domain_exact');
                try { QueueV4CleanCycleBudget::reserve(str_repeat('b', 40), 'queue_v4_clean_domain_exact'); }
                catch (ApiBudgetExhaustedException $e) { $original = $e; throw $e; }
                throw new RuntimeException('EXPECTED_REAL_BUDGET_EXHAUSTION');
            }
            if ($case === 'cleanup_loss') {
                $pdo->prepare("UPDATE sale_financial_reconciliation_jobs SET lock_owner='replacement-owner',lease_generation=lease_generation+1 WHERE id=? AND company_id=9001 AND meli_account_id=9011 AND status='running'")->execute([$f['source_id']]);
            }
            throw $original;
        });
        $caught = null;
        try { $service->processDomainExactBatch([$f['source_id']], 9001, 9011); }
        catch (Throwable $e) { $caught = $e; }
        financial_rhythm_assert($caught === $original, 'original_typed_exception_preserved');
        financial_rhythm_assert($source()['status'] === 'running' && Cap2DomainsWire::$calls === [], 'uncleaned_source_zero_http');
        if ($case === 'cleanup_loss') financial_rhythm_assert($source()['lock_owner'] === 'replacement-owner', 'replacement_owner_not_closed');
        else {
            echo "SIBLING_DEFECT_FOUND=YES POSTCLAIM_BUDGET_SOURCE_RUNNING_NOT_FIXED\n";
            // Explicit opt-in RED: this sibling is documented, NOT fixed here.
            financial_rhythm_assert($source()['status'] === 'retry', 'sibling_budget_requires_source_disposition', ['actual' => $source()['status']]);
        }
    } else {
        $factory = null;
        if ($case === 'pretransport') {
            $factory = static fn() => new SaleFinancialService(static function (int $account) use ($safe) {
                throw new ApiRhythmDeferredException('Synthetic pretransport rhythm deferral', $safe, 'rhythm_block_pause');
            });
        } elseif ($case === 'global' || $case === 'interval') {
            $column = $case === 'global' ? 'block_pause_until' : 'next_allowed_at';
            $pdo->prepare("INSERT INTO api_rhythm_states(scope_key,generation,calls_in_block,{$column},updated_at) VALUES('global',1,0,?,UTC_TIMESTAMP(3))")->execute([$safe]);
        } elseif ($case === 'billing') {
            $pdo->prepare('INSERT INTO api_rhythm_penalties(scope_key,reduced_limit_per_minute,blocked_until,reduced_until,reason,updated_at) VALUES(?,1,?,?,"synthetic",UTC_TIMESTAMP(3))')->execute(['endpoint:shared:' . hash('sha256', 'billing_orders'), $safe, $safe]);
        } else {
            Cap2DomainsWire::$onWire = null;
            Cap2DomainsWire::$responses = ['/billing/integration/group/ML/order/details' => [429, ['error' => 'rate_limited', 'message' => 'synthetic']]];
        }
        financial_rhythm_worker($pdo, $factory);
        $after = $source();
        $ptr = $pointer();
        $expectedCalls = $case === 'known429' ? 1 : 0;
        financial_rhythm_assert($after['status'] === 'retry', 'source_after_deferral_retry', ['actual' => $after['status']]);
        financial_rhythm_assert($after['lock_owner'] === null && $after['lease_expires_at'] === null && $after['heartbeat_at'] === null, 'source_lease_fully_cleared');
        financial_rhythm_assert((int) $after['attempts'] === (int) $before['attempts'], 'source_attempt_penalty_refunded');
        financial_rhythm_assert($ptr['state'] === 'waiting' && (int) $ptr['attempt_count'] === 0, 'worker_pointer_defer_preserved');
        $scope = $case === 'pretransport' ? 'rhythm_block_pause' : ($case === 'known429' ? 'remote_429_global_pause' : 'billing_429_backoff');
        financial_rhythm_assert($ptr['last_error_class'] === 'rate_limit_deferred:' . $scope, 'worker_original_classification_preserved');
        financial_rhythm_assert(strtotime($after['next_run_at'] . ' UTC') === strtotime($ptr['available_at'] . ' UTC'), 'source_and_pointer_exact_safe_time');
        if ($case === 'pretransport') financial_rhythm_assert($after['next_run_at'] === $safe, 'literal_next_safe_at_preserved');
        $attempts = $pdo->prepare('SELECT id,dispatch_state,physical_http_calls,http_status,physical_started_at,response_known_at FROM queue_v4_clean_attempts WHERE job_id=? AND company_id=9001 AND meli_account_id=9011 ORDER BY id');
        $attempts->execute([$f['queue_id']]); $firstAttempts = $attempts->fetchAll(PDO::FETCH_ASSOC);
        financial_rhythm_assert(count($firstAttempts) === 1 && (int) $firstAttempts[0]['physical_http_calls'] === $expectedCalls
            && $firstAttempts[0]['dispatch_state'] === ($expectedCalls ? 'RESPONSE_KNOWN' : 'NOT_DISPATCHED'), 'durable_physical_counter_and_certainty');
        $journal = $pdo->prepare('SELECT * FROM queue_v4_clean_transport_events WHERE source_kind="queue" AND work_id=? AND company_id=9001 AND meli_account_id=9011 ORDER BY id');
        $journal->execute([$f['queue_id']]); $firstJournal = $journal->fetchAll(PDO::FETCH_ASSOC);
        financial_rhythm_assert(count($firstJournal) === $expectedCalls && count(Cap2DomainsWire::$calls) === $expectedCalls, 'journal_matches_physical_dispatches');
        if ($expectedCalls) financial_rhythm_assert($firstJournal[0]['dispatch_state'] === 'RESPONSE_KNOWN' && (int) $firstJournal[0]['http_status'] === 429, 'known_429_durable_journal');
        financial_rhythm_assert($claimNow() === null && $repo->releaseDueWaiting([9011], 9011) === 0, 'before_safe_no_claim_or_release');
        financial_rhythm_worker($pdo);
        financial_rhythm_assert(count(Cap2DomainsWire::$calls) === $expectedCalls, 'before_safe_duplicate_http_zero');
        $advance(strtotime($ptr['available_at'] . ' UTC') + 3);
        financial_rhythm_assert($repo->releaseDueWaiting([9011], 9011) === 1, 'after_safe_real_wakeup_releases');
        financial_rhythm_configure_wire();
        financial_rhythm_worker($pdo);
        financial_rhythm_assert(count(Cap2DomainsWire::$calls) === 1 && (string) Cap2DomainsWire::$calls[0]['query']['order_ids'] === $f['orders'][0], 'after_safe_normal_claim_and_checkpoint');
        financial_rhythm_assert((int) $source()['lease_generation'] > (int) $after['lease_generation'] && $source()['lock_owner'] === null, 'after_safe_source_consumed_without_orphan');
        $journal->execute([$f['queue_id']]);
        $afterJournal = $journal->fetchAll(PDO::FETCH_ASSOC);
        financial_rhythm_assert(array_slice($afterJournal, 0, count($firstJournal)) === $firstJournal, 'first_transport_journal_unchanged');
        $attempts->execute([$f['queue_id']]);
        financial_rhythm_assert($attempts->fetch(PDO::FETCH_ASSOC) === $firstAttempts[0], 'first_physical_attempt_unchanged');
    }
    echo 'STATUS=PASS CASE=' . $case . ' MYSQL=REAL REAL_MELI_HTTP=0 REAL_OAUTH=0' . PHP_EOL;
} finally {
    Cap2DomainsWire::$onWire = null;
    QueueV4CleanCycleBudget::clear();
    CronDeadlineContext::clear();
    $harness->cleanup();
}
}
