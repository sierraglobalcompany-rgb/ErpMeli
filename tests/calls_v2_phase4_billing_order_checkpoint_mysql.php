<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error) . ':' . $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL);
    exit(1);
});

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\Services\ApiExecutionMetadataContext;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;
use App\Services\CronDeadlineContext;
use App\Services\MeliTransportSourcePolicy;
use App\Services\SaleFinancialService;
use App\Services\SaleFinancialStateService;

$assert = static function (bool $condition, string $label, array $context = []): void {
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    echo 'PASS:' . $label . PHP_EOL;
};

$runBillingStep = static function (PDO $pdo, int $sourceId): array {
    $leaseOwner = 'phase4-' . bin2hex(random_bytes(8));
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,
          lease_owner,lease_generation,lease_expires_at,available_at)
         VALUES(9001,9011,'domain_exact',?,?,?,'running',?,1,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE),'2000-01-01')"
    )->execute([
        (string) $sourceId,
        'phase4-fence-' . $sourceId . '-' . bin2hex(random_bytes(6)),
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
        $leaseOwner,
    ]);
    $queueJobId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_attempts
         (job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation,outcome,dispatch_state)
         VALUES(?,9904,9001,9011,?,1,'running','NOT_DISPATCHED')"
    )->execute([$queueJobId, $leaseOwner]);
    $attemptId = (int) $pdo->lastInsertId();

    CronDeadlineContext::start(45, 40, 8, 3);
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try {
        return ApiExecutionMetadataContext::run(
            [
                'source' => MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT,
                'job_type' => 'domain_exact',
                'company_id' => 9001,
                'account_id' => 9011,
                'source_queue_key' => 'financial_reconciliation',
                'source_work_id' => (string) $sourceId,
                'queue_v4_job_id' => $queueJobId,
                'queue_v4_attempt_id' => $attemptId,
                'queue_v4_lease_owner' => $leaseOwner,
                'queue_v4_lease_generation' => 1,
            ],
            static fn (): array => (new SaleFinancialService())->processDomainExactBatch([$sourceId], 9001, 9011, true)
        );
    } finally {
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
};

if ((string) getenv('CALLS_V2_PHASE4_REUSE_DB') === '1') {
    putenv('APP_ENV=test');
    putenv('ML_WRITE_ENABLED=false');
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_PORT=33079');
    putenv('DB_USER=root');
    putenv('DB_PASS=');
    putenv('APP_KEY=cap2-disposable-test-only-not-a-real-secret');
    putenv('MELI_API_BASE=https://cap2-wire.invalid');
    putenv('PRIVATE_STORAGE_PATH=D:/Codex/tmp/erp-meli/cap2-20260905/qa/manual-private');
    if (!defined('ERP_INSTALLATION_ROOT')) {
        define('ERP_INSTALLATION_ROOT', 'D:/Codex/tmp/erp-meli/cap2-20260905/qa/manual-install-phase4-reuse');
    }
    if (!is_dir((string) getenv('PRIVATE_STORAGE_PATH'))) {
        mkdir((string) getenv('PRIVATE_STORAGE_PATH'), 0777, true);
    }
    if (!is_dir(ERP_INSTALLATION_ROOT)) {
        mkdir(ERP_INSTALLATION_ROOT, 0777, true);
    }
    if (!is_dir(ERP_INSTALLATION_ROOT . '/storage')) {
        mkdir(ERP_INSTALLATION_ROOT . '/storage', 0777, true);
    }
    file_put_contents(ERP_INSTALLATION_ROOT . '/storage/session-generation', str_repeat('1', 32));
    $harness = K1dSafeTestDatabase::connectExistingFromEnvironment();
    $bootstrapPdo = $harness->pdo();
    $bootstrapPdo->exec("INSERT IGNORE INTO companies(id,name,status) VALUES(9001,'CAP2 manual',1),(9002,'Other scope',1)");
    $bootstrapPdo->exec("INSERT IGNORE INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'CAP2 account',99011,'conectado'),(9012,9002,'Other account',99012,'conectado')");
    $bootstrapPdo->exec("INSERT IGNORE INTO users(id,name,email,password_hash,role,status) VALUES(9007,'CAP2 user','manual@example.invalid','unused','admin',1),(9008,'Other user','other@example.invalid','unused','admin',1)");
    $bootstrapPdo->exec('INSERT IGNORE INTO user_company_access(user_id,company_id) VALUES(9007,9001),(9008,9002)');
    $bootstrapPdo->prepare('DELETE FROM meli_tokens WHERE meli_account_id=9011')->execute();
    $bootstrapPdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([App\Core\Crypto::encrypt('test-access'),App\Core\Crypto::encrypt('test-refresh')]);
    $bootstrapPdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $bootstrapPdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    App\Core\Session::put('user',['id'=>9007,'role'=>'admin','session_generation'=>(new App\Services\SessionGenerationService())->current()]);
} else {
    $harness = cap2_manual_database();
}
try {
    $pdo = $harness->pdo();
    (new AppSettingsService())->set('automation.max_api_calls_per_cycle', '1', 'phase4');
    (new AppSettingsService())->set('api.rhythm.pause_ms', '0', 'phase4');
    (new AppSettingsService())->set('api.rhythm.burst_size', '100', 'phase4');
    AppSettingsService::clearCache();

    $suffix = (string) random_int(100, 999);
    $packId = '774' . $suffix;
    $externalOrders = ['774' . $suffix . '1', '774' . $suffix . '2', '774' . $suffix . '3', '774' . $suffix . '4', '774' . $suffix . '5'];
    $pdo->prepare(
        "INSERT INTO meli_packs(meli_account_id,external_pack_id,status,integrity_status,expected_orders_count,linked_orders_count,expected_orders_json,synced_at,verified_at)
         VALUES(9011,?,'paid','complete',5,5,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
    )->execute([$packId, json_encode($externalOrders, JSON_THROW_ON_ERROR)]);
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
        'phase4-pack-' . $sourceId,
        json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
    ]);

    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$responses['/billing/integration/group/ML/order/details'] = [200, []];
    Cap2DomainsWire::$onWire = static function (): void {
        $last = Cap2DomainsWire::$calls[array_key_last(Cap2DomainsWire::$calls)] ?? [];
        $orderId = (string) (($last['query']['order_ids'] ?? '') ?: '0');
        Cap2DomainsWire::$status = 200;
        Cap2DomainsWire::$raw = json_encode([
            [
                'order_id' => $orderId,
                'detail_id' => 'fee-' . $orderId,
                'detail_type' => 'SALE_FEE',
                'description' => 'Cargo por venta ' . $orderId,
                'amount' => 10,
                'date_created' => '2026-09-07T00:00:00Z',
            ],
        ], JSON_THROW_ON_ERROR);
    };

    $observed = [];
    for ($i = 0; $i < 5; $i++) {
        $pdo->exec("UPDATE api_rhythm_states SET calls_in_block=0,next_allowed_at=NULL,block_pause_until=NULL WHERE scope_key='global'");
        $pdo->exec("DELETE FROM api_remote_permits WHERE endpoint_key='billing_orders'");
        $pdo->exec("DELETE FROM api_request_logs WHERE endpoint_path='/billing/integration/group/ML/order/details'");
        $result = $runBillingStep($pdo, $sourceId);
        $last = Cap2DomainsWire::$calls[array_key_last(Cap2DomainsWire::$calls)] ?? [];
        $observed[] = (string) ($last['query']['order_ids'] ?? '');
        $status = (string) $pdo->query('SELECT status FROM sale_financial_reconciliation_jobs WHERE id=' . $sourceId)->fetchColumn();
        if ($i < 4) {
            $assert($status === 'awaiting_remote' || $status === 'retry', 'pack_not_complete_before_all_order_checkpoints', [
                'cycle' => $i + 1,
                'status' => $status,
                'result' => $result,
            ]);
            $pdo->exec("UPDATE sale_financial_reconciliation_jobs SET next_run_at='2000-01-01' WHERE id=" . $sourceId);
            $pdo->exec("UPDATE queue_v4_clean_jobs SET state='waiting',available_at='2000-01-01',lease_owner=NULL,lease_expires_at=NULL WHERE resource_id='" . $sourceId . "'");
        }
    }

    $assert($observed === $externalOrders, 'pack_order_checkpoints_follow_local_order_fifo_without_repeats', [
        'observed' => $observed,
        'expected' => $externalOrders,
    ]);
    $checkpointRows = (int) $pdo->query(
        "SELECT COUNT(*) FROM sale_financial_evidence
         WHERE company_id=9001 AND meli_account_id=9011 AND sale_key='P:{$packId}'
           AND evidence_type='billing_capture'
           AND evidence_json LIKE '%\"format\":\"billing_order_v2\"%'"
    )->fetchColumn();
    $assert($checkpointRows === 5, 'five_exact_billing_order_v2_checkpoints_persisted', ['rows' => $checkpointRows]);
    $captureRows = $pdo->query(
        "SELECT requested_order_ids_json,response_hash
         FROM meli_billing_capture_runs
         WHERE company_id=9001 AND meli_account_id=9011 AND sale_key='P:{$packId}'
         ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $captureHashesOk = count($captureRows) === 5;
    foreach ($captureRows as $row) {
        $requested = json_decode((string) $row['requested_order_ids_json'], true);
        $orderId = is_array($requested) ? (string) ($requested[0] ?? '') : '';
        $expectedBody = [[
            'order_id' => $orderId,
            'detail_id' => 'fee-' . $orderId,
            'detail_type' => 'SALE_FEE',
            'description' => 'Cargo por venta ' . $orderId,
            'amount' => 10,
            'date_created' => '2026-09-07T00:00:00Z',
        ]];
        $expectedHash = hash('sha256', json_encode($expectedBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $captureHashesOk = $captureHashesOk && is_array($requested) && count($requested) === 1
            && in_array($orderId, $externalOrders, true)
            && hash_equals($expectedHash, (string) ($row['response_hash'] ?? ''));
    }
    $assert($captureHashesOk, 'physical_capture_hashes_remain_exact_after_final_aggregation', ['captures' => $captureRows]);
    $finalStatus = (string) $pdo->query("SELECT official_status FROM sale_financial_state WHERE company_id=9001 AND meli_account_id=9011 AND sale_key='P:{$packId}'")->fetchColumn();
    $assert($finalStatus === 'complete', 'pack_publishes_once_after_all_checkpoints', ['official_status' => $finalStatus]);
    echo "STATUS=PASS CALLS_V2_PHASE4_BILLING_ORDER_CHECKPOINT MYSQL=REAL REAL_MELI_HTTP=0\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'PHASE4_FAIL=' . get_class($error) . ':' . $error->getMessage() . PHP_EOL);
    throw $error;
} finally {
    Cap2DomainsWire::$onWire = null;
    QueueV4CleanCycleBudget::clear();
    CronDeadlineContext::clear();
    $harness->cleanup();
}
