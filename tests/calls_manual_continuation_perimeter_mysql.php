<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\Cap2DomainsWire;
use App\Services\ManualCampaignPreviewService;
use App\Services\OrderEnrichmentService;
use App\Services\OrderFinancialRecalcJobService;

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL:$label\n";
        return;
    }
    echo "PASS:$label\n";
};

$harness = cap2_manual_database();
try {
    $pdo = $harness->pdo();
    $repository = new QueueV4CleanRepository($pdo);

    $insertOrder = static function (string $externalOrderId, float $paidAmount = 100.0) use ($pdo): int {
        $pdo->prepare(
            "INSERT INTO meli_orders
             (meli_account_id,external_order_id,status,paid_amount,total_amount,currency_id,date_created,date_closed,synced_at)
             VALUES (9011,?,'paid',?,?,'COP','2026-09-01 00:00:00','2026-09-01 00:01:00',UTC_TIMESTAMP())"
        )->execute([$externalOrderId, $paidAmount, $paidAmount]);
        return (int) $pdo->lastInsertId();
    };
    $insertPointer = static function (string $capability, int $sourceId) use ($pdo): int {
        $pdo->prepare(
            "INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,payload_json)
             VALUES (9001,9011,'domain_exact',?,?,'ready','2000-01-01',?)"
        )->execute([
            (string) $sourceId,
            'calls-manual-continuation-' . $capability . '-' . $sourceId,
            json_encode(['capability' => $capability, 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
        ]);
        return (int) $pdo->lastInsertId();
    };
    $confirmedRow = static function (int $queueId) use ($repository): array {
        foreach ($repository->previewEligible(60, [9011], 9011) as $row) {
            if ((int) ($row['queue_job_id'] ?? 0) !== $queueId) {
                continue;
            }
            $bound = ManualCampaignPreviewService::bindAvailableSourceIdentity($row);
            if ($bound !== null) {
                return $bound;
            }
        }
        throw new RuntimeException('fixture_confirmed_pointer_not_found:' . $queueId);
    };
    $runConfirmed = static function (array $row) use ($pdo, $repository): array {
        QueueV4CleanCycleBudget::start(1, 'manual', microtime(true) + 40);
        try {
            return (new QueueV4CleanWorker($pdo, $repository))->run('manual', 1, 30, [9011], 9011, [$row]);
        } finally {
            QueueV4CleanCycleBudget::clear();
        }
    };

    // Recalculating the displayed local job may discover missing Billing data,
    // but a manual step must not enqueue a successor the user never selected.
    $recalcOrderId = $insertOrder('840001');
    $recalcSourceId = (new OrderFinancialRecalcJobService())->createForOrderIds(
        [$recalcOrderId],
        'repaired',
        'manual',
        null,
        9007,
    );
    $recalcPointerId = $insertPointer('financial_recalc', $recalcSourceId);
    $recalcRow = $confirmedRow($recalcPointerId);
    $billingBefore = (int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs')->fetchColumn();
    $wireBefore = count(Cap2DomainsWire::$calls);
    $recalcResult = $runConfirmed($recalcRow);
    $assert((int) $recalcResult['claimed'] === 1, 'manual_financial_recalc_claims_displayed_pointer');
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs')->fetchColumn() === $billingBefore,
        'manual_financial_recalc_creates_no_unconfirmed_billing_successor',
    );
    $assert(count(Cap2DomainsWire::$calls) === $wireBefore, 'manual_financial_recalc_stays_local');

    // The automatic service contract keeps its historical continuation default.
    $autoOrderId = $insertOrder('840002');
    $autoRecalcId = (new OrderFinancialRecalcJobService())->createForOrderIds(
        [$autoOrderId],
        'repaired',
        'automatic',
        null,
        9007,
    );
    $automaticBillingBefore = (int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs')->fetchColumn();
    (new OrderFinancialRecalcJobService())->processExact($autoRecalcId, 9011, 1);
    $assert(
        (int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs')->fetchColumn() === $automaticBillingBefore + 1,
        'automatic_financial_recalc_default_still_creates_successor',
    );

    // A pack can report child orders not present locally. Only the displayed pack
    // source is authorized: missing children must not become new queue pointers.
    $packOrderId = $insertOrder('840010');
    $packSourceId = (new OrderEnrichmentService())->enqueue(9011, $packOrderId, 'pack', '840099');
    $packPointerId = $insertPointer('order_enrichment_pack', $packSourceId);
    $packRow = $confirmedRow($packPointerId);
    Cap2DomainsWire::$responses['/packs/840099'] = [200, [
        'id' => 840099,
        'status' => 'released',
        'orders' => [['id' => 840010], ['id' => 840011]],
        'shipment' => null,
    ]];
    $orderExactBefore = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact'")->fetchColumn();
    $wireBefore = count(Cap2DomainsWire::$calls);
    $packResult = $runConfirmed($packRow);
    $assert((int) $packResult['claimed'] === 1, 'manual_pack_claims_displayed_pointer');
    $assert(count(Cap2DomainsWire::$calls) === $wireBefore + 1, 'manual_pack_uses_only_displayed_pack_call');
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact'")->fetchColumn() === $orderExactBefore,
        'manual_pack_creates_no_unconfirmed_child_pointer',
    );
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM meli_orders WHERE meli_account_id=9011 AND external_order_id='840011'")->fetchColumn() === 0,
        'manual_pack_does_not_materialize_unconfirmed_child',
    );

    // The same production entry point keeps automatic continuation enabled by
    // default, so the manual restriction does not change scheduler behavior.
    $automaticPackOrderId = $insertOrder('840020');
    $automaticPackSourceId = (new OrderEnrichmentService())->enqueue(9011, $automaticPackOrderId, 'pack', '840199');
    $insertPointer('order_enrichment_pack', $automaticPackSourceId);
    Cap2DomainsWire::$responses['/packs/840199'] = [200, [
        'id' => 840199,
        'status' => 'released',
        'orders' => [['id' => 840020], ['id' => 840021]],
        'shipment' => null,
    ]];
    $automaticOrderExactBefore = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact'")->fetchColumn();
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 40);
    try {
        (new QueueV4CleanWorker($pdo, $repository))->run('scheduler', 1, 30, [9011], 9011);
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
    $assert(
        (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact'")->fetchColumn() === $automaticOrderExactBefore + 1,
        'automatic_pack_default_still_admits_missing_child',
    );

    if ($failures !== []) {
        throw new RuntimeException('FAILURES:' . implode(',', $failures));
    }
    echo "STATUS=PASS CALLS_MANUAL_CONTINUATION_PERIMETER REAL_MELI_HTTP=0\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    $harness->cleanup();
}
