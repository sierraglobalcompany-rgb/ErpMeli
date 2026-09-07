<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\Cap2DomainsWire;
use App\Services\SaleFinancialService;
use App\Services\SaleFinancialStateService;

$assert = static function (bool $condition, string $label, array $context = []): void {
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    echo 'PASS:' . $label . PHP_EOL;
};

$harness = cap2_manual_database();
try {
    $pdo = $harness->pdo();
    $settings = new App\Services\AppSettingsService();
    $settings->set('automation.max_api_calls_per_cycle', '1', 'calls-final-post-selection-race');
    $settings->set('api.rhythm.pause_ms', '0', 'calls-final-post-selection-race');
    $settings->set('api.rhythm.burst_size', '100', 'calls-final-post-selection-race');
    App\Services\AppSettingsService::clearCache();

    $seed = static function (int $externalOrderId) use ($pdo): array {
        $pdo->prepare(
            "INSERT INTO meli_orders
             (meli_account_id,external_order_id,status,paid_amount,currency_id,synced_at)
             VALUES (9011,?,'paid',100,'COP',UTC_TIMESTAMP())"
        )->execute([(string) $externalOrderId]);
        $state = (new SaleFinancialStateService())->projectSale(9001, 9011, 'O:' . $externalOrderId);
        $pdo->prepare(
            "INSERT INTO sale_financial_reconciliation_jobs
             (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at)
             VALUES (9001,9011,?,?,?,'pending','2000-01-01')"
        )->execute(['O:' . $externalOrderId, (string) $externalOrderId, $state['input_version']]);
        $sourceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,payload_json)
             VALUES (9001,9011,'domain_exact',?,?,'ready','2000-01-01',?)"
        )->execute([
            (string) $sourceId,
            'calls-final-post-selection-race-' . $sourceId,
            json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
        ]);

        return ['source' => $sourceId, 'queue' => (int) $pdo->lastInsertId(), 'external' => (string) $externalOrderId];
    };

    $snapshot = static function (array $row) use ($pdo): array {
        $source = $pdo->query(
            'SELECT status,next_run_at,attempts,lock_owner,lease_expires_at,lease_generation,heartbeat_at,updated_at
             FROM sale_financial_reconciliation_jobs WHERE id=' . (int) $row['source']
        )->fetch(PDO::FETCH_ASSOC);
        $pointer = $pdo->query(
            'SELECT state,available_at,attempt_count,lease_owner,lease_expires_at,lease_generation,completed_at,last_error_class,updated_at
             FROM queue_v4_clean_jobs WHERE id=' . (int) $row['queue']
        )->fetch(PDO::FETCH_ASSOC);
        return ['source' => $source, 'pointer' => $pointer];
    };

    $cases = [
        'future_neighbor' => static function (PDO $pdo, array $neighbor): void {
            $pdo->prepare("UPDATE sale_financial_reconciliation_jobs SET next_run_at='2099-01-01' WHERE id=?")->execute([$neighbor['source']]);
        },
        'leased_neighbor' => static function (PDO $pdo, array $neighbor): void {
            $pdo->prepare(
                "UPDATE sale_financial_reconciliation_jobs
                 SET lock_owner='post-selection-race',lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE),lease_generation=7
                 WHERE id=?"
            )->execute([$neighbor['source']]);
        },
        'manual_reserved_neighbor' => static function (PDO $pdo, array $neighbor): void {
            $pdo->prepare(
                "INSERT INTO manual_campaigns
                 (campaign_token,created_by_user_id,company_scope_key,scope_key,preset,status,configuration_json,total_items)
                 VALUES (?,9007,9001,'finance','safe','active','{}',1)"
            )->execute([bin2hex(random_bytes(20))]);
            $campaignId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO manual_campaign_operations
                 (manual_campaign_id,queue_key,operation_key,meli_account_id,company_id,item_count,exact_adapter)
                 VALUES (?,'sale_financial_reconciliation','financial_reconciliation',9011,9001,1,1)"
            )->execute([$campaignId]);
            $operationId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO manual_campaign_items
                 (manual_campaign_id,operation_id,queue_key,operation_key,source_id,meli_account_id,company_id,human_label,status,position_no)
                 VALUES (?,?,'sale_financial_reconciliation','financial_reconciliation',?,9011,9001,'Post selection reserved','pending',1)"
            )->execute([$campaignId, $operationId, (string) $neighbor['source']]);
            $itemId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO manual_campaign_reservations
                 (manual_campaign_id,manual_campaign_item_id,queue_key,source_id,company_id,meli_account_id,expires_at)
                 VALUES (?,?,'sale_financial_reconciliation',?,9001,9011,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE))"
            )->execute([$campaignId, $itemId, (string) $neighbor['source']]);
        },
    ];

    foreach ($cases as $case => $makeIneligible) {
        Cap2DomainsWire::$responses['/billing/integration/group/ML/order/details'] = [200, []];
        Cap2DomainsWire::$calls = [];
        Cap2DomainsWire::$onWire = null;
        $primary = $seed(970000 + random_int(1, 10000));
        $neighbor = $seed(980000 + random_int(1, 10000));
        $afterSelectionMutation = null;
        $factoryCalls = 0;

        $repo = new QueueV4CleanRepository($pdo);
        $worker = new QueueV4CleanWorker(
            $pdo,
            $repo,
            financialFactory: static function () use ($pdo, $neighbor, $makeIneligible, $snapshot, &$afterSelectionMutation, &$factoryCalls): SaleFinancialService {
                $factoryCalls++;
                $makeIneligible($pdo, $neighbor);
                $afterSelectionMutation = $snapshot($neighbor);
                return new SaleFinancialService();
            },
        );
        QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
        try {
            $result = $worker->run('test', 1, 40);
        } finally {
            QueueV4CleanCycleBudget::clear();
        }

        $after = $snapshot($neighbor);
        $wireIds = [];
        if (isset(Cap2DomainsWire::$calls[0]['query']['order_ids'])) {
            $wireIds = explode(',', (string) Cap2DomainsWire::$calls[0]['query']['order_ids']);
        }
        $attemptsDelta = (int) $after['source']['attempts'] - (int) $afterSelectionMutation['source']['attempts'];
        $assert($factoryCalls === 1, $case . '_selected_before_mutation', ['factory_calls' => $factoryCalls, 'result' => $result]);
        $assert(!in_array((string) $neighbor['external'], $wireIds, true), $case . '_NEIGHBOR_HTTP=0', ['wire_ids' => $wireIds]);
        $assert($attemptsDelta === 0, $case . '_NEIGHBOR_CLAIM=0', ['attempts_delta' => $attemptsDelta]);
        $assert($after['source'] === $afterSelectionMutation['source'], $case . '_NEIGHBOR_SOURCE_MUTATIONS=0', ['before' => $afterSelectionMutation['source'], 'after' => $after['source']]);
        $assert($after['pointer'] === $afterSelectionMutation['pointer'], $case . '_NEIGHBOR_POINTER_MUTATIONS=0', ['before' => $afterSelectionMutation['pointer'], 'after' => $after['pointer']]);
    }

    foreach ($cases as $case => $makeIneligible) {
        Cap2DomainsWire::$calls = [];
        $primary = $seed(990000 + random_int(1, 10000));
        $neighbor = $seed(995000 + random_int(1, 10000));
        $afterRecoveryReadMutation = null;
        $recoveryReadCount = 0;
        $factoryCalls = 0;

        $repo = new QueueV4CleanRepository(
            $pdo,
            static function () use ($pdo, $neighbor, $makeIneligible, $snapshot, &$afterRecoveryReadMutation, &$recoveryReadCount): void {
                $recoveryReadCount++;
                $makeIneligible($pdo, $neighbor);
                $afterRecoveryReadMutation = $snapshot($neighbor);
            },
        );
        $worker = new QueueV4CleanWorker(
            $pdo,
            $repo,
            financialFactory: static function () use (&$factoryCalls): SaleFinancialService {
                $factoryCalls++;
                throw new RuntimeException('test_financial_recovery_after_selection');
            },
        );
        QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
        try {
            $result = $worker->run('test', 1, 40);
        } finally {
            QueueV4CleanCycleBudget::clear();
        }

        $after = $snapshot($neighbor);
        $attemptsDelta = (int) $after['source']['attempts'] - (int) $afterRecoveryReadMutation['source']['attempts'];
        $assert($factoryCalls === 1, 'recovery_' . $case . '_selected_before_exception', ['factory_calls' => $factoryCalls, 'result' => $result]);
        $assert($recoveryReadCount === 1, 'recovery_' . $case . '_read_before_mutation', ['recovery_reads' => $recoveryReadCount]);
        $assert(Cap2DomainsWire::$calls === [], 'recovery_' . $case . '_NEIGHBOR_HTTP=0', ['calls' => Cap2DomainsWire::$calls]);
        $assert($attemptsDelta === 0, 'recovery_' . $case . '_NEIGHBOR_CLAIM=0', ['attempts_delta' => $attemptsDelta]);
        $assert($after['source'] === $afterRecoveryReadMutation['source'], 'recovery_' . $case . '_NEIGHBOR_SOURCE_MUTATIONS=0', ['before' => $afterRecoveryReadMutation['source'], 'after' => $after['source']]);
        $assert($after['pointer'] === $afterRecoveryReadMutation['pointer'], 'recovery_' . $case . '_NEIGHBOR_POINTER_MUTATIONS=0', ['before' => $afterRecoveryReadMutation['pointer'], 'after' => $after['pointer']]);
    }

    echo "STATUS=PASS CALLS_FINAL_FINANCIAL_POST_SELECTION_RACE MYSQL=REAL REAL_MELI_HTTP=0\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    $harness->cleanup();
}
