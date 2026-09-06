<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\Cap2DomainsWire;
use App\Services\ManualCampaignPreviewService;
use App\Services\SaleFinancialStateService;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL:$label");
    }
    echo "PASS:$label\n";
};

$harness = cap2_manual_database();
try {
    $pdo = $harness->pdo();
    $sources = [];
    $queueIds = [];
    foreach ([830001, 830002] as $externalOrderId) {
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
        $sources[] = $sourceId;
        $pdo->prepare(
            "INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,payload_json)
             VALUES (9001,9011,'domain_exact',?,?,'ready','2000-01-01',?)"
        )->execute([
            (string) $sourceId,
            'calls-manual-financial-' . $sourceId,
            json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId]),
        ]);
        $queueIds[] = (int) $pdo->lastInsertId();
    }

    $repository = new QueueV4CleanRepository($pdo);
    $rows = array_values(array_filter(array_map(
        static fn(array $row):?array => ManualCampaignPreviewService::bindAvailableSourceIdentity($row),
        $repository->previewEligible(60, [9011], 9011)
    )));
    $assert(count($rows) === 2, 'two_contiguous_financial_pointers_previewed');
    $confirmed = [$rows[0]];
    Cap2DomainsWire::$responses['/billing/integration/group/ML/order/details'] = [200, []];
    QueueV4CleanCycleBudget::start(1, 'manual', microtime(true) + 40);
    try {
        $result = (new QueueV4CleanWorker($pdo, $repository))->run('manual', 1, 30, [9011], 9011, $confirmed);
    } finally {
        QueueV4CleanCycleBudget::clear();
    }

    $sourceStatus = $pdo->prepare('SELECT status FROM sale_financial_reconciliation_jobs WHERE id=?');
    $sourceStatus->execute([$sources[1]]);
    $pointerState = $pdo->prepare('SELECT state FROM queue_v4_clean_jobs WHERE id=?');
    $pointerState->execute([$queueIds[1]]);
    $assert((string) $sourceStatus->fetchColumn() === 'pending', 'unconfirmed_financial_source_untouched');
    $assert((string) $pointerState->fetchColumn() === 'ready', 'unconfirmed_financial_pointer_not_aligned');
    $assert((int) $result['selected_count'] === 1 && (int) $result['claimed'] === 1, 'receipt_never_counts_financial_neighbor');
    $assert(count(Cap2DomainsWire::$calls) <= 1, 'manual_financial_uses_at_most_confirmed_http_budget');

    echo "STATUS=PASS CALLS_MANUAL_FINANCIAL_SELECTION REAL_MELI_HTTP=0\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    $harness->cleanup();
}
