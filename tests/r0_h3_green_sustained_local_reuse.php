<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\CronAdmissionService;
use App\Services\OrderEnrichmentService;

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
$admission = new CronAdmissionService($pdo);
$enrichment = new OrderEnrichmentService();
$repository = new QueueV4CleanRepository($pdo);
$worker = new QueueV4CleanWorker($pdo, $repository);

$maxOpen = 0;
$completedSources = [];
for ($offset = 0; $offset < 10; $offset += 2) {
    for ($i = $offset; $i < min($offset + 2, 10); $i++) {
        $target = $healthy[$i];
        $externalOrderId = (string) (920000 + ($i + 1));
        $pdo->prepare(
            'UPDATE meli_packs
                SET expected_orders_count=1,linked_orders_count=1,expected_orders_json=?,integrity_status="complete",verified_at=UTC_TIMESTAMP(3)
              WHERE id=? AND meli_account_id=?'
        )->execute([json_encode([$externalOrderId], JSON_UNESCAPED_SLASHES), (int) $target['pack_id'], (int) $target['meli_account_id']]);

        $sourceId = $enrichment->enqueue(
            (int) $target['meli_account_id'],
            (int) $target['order_id'],
            'pack',
            (string) $target['external_pack_id'],
            10
        );
        $pdo->beginTransaction();
        $receipt = $admission->submit(
            'order_enrichment_pack',
            (int) $target['company_id'],
            (int) $target['meli_account_id'],
            $sourceId,
            'source:' . $sourceId,
            ['pack_id' => (string) $target['external_pack_id']]
        );
        $pdo->commit();
        r0h3_assert(!empty($receipt['accepted']) && empty($receipt['deduplicated']), 'sustained_admission_expected', ['i' => $i, 'receipt' => $receipt]);
        $completedSources[] = $sourceId;
    }

    $openBefore = r0h3_open_new_pack_units($pdo);
    $maxOpen = max($maxOpen, $openBefore);
    r0h3_assert($openBefore <= 2, 'occupancy_must_not_exceed_two_before_worker', ['open' => $openBefore, 'offset' => $offset]);

    QueueV4CleanCycleBudget::clear();
    QueueV4CleanCycleBudget::start(2, 'automatic', microtime(true) + 45.0);
    try {
        $run = $worker->run('test', 2, 45);
    } finally {
        $budget = QueueV4CleanCycleBudget::snapshot();
        QueueV4CleanCycleBudget::clear();
    }

    $openAfter = r0h3_open_new_pack_units($pdo);
    r0h3_assert($openAfter === 0, 'worker_real_local_closure_must_free_slots', ['run' => $run, 'budget' => $budget, 'open_after' => $openAfter, 'offset' => $offset]);
    r0h3_assert((int) ($run['completed'] ?? 0) >= 2, 'worker_must_complete_admitted_local_sources', ['run' => $run, 'offset' => $offset]);
    r0h3_assert(($budget['physical_http_calls'] ?? null) === 0, 'local_pack_closure_must_not_emit_http', ['budget' => $budget, 'offset' => $offset]);
}

$transportEvents = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_transport_events')->fetchColumn();
$statement = $pdo->prepare(
    "SELECT COUNT(*) FROM order_resource_enrichment_jobs
     WHERE id IN (" . implode(',', array_fill(0, count($completedSources), '?')) . ")
       AND status='complete' AND completed_at IS NOT NULL AND failure_class IS NULL"
);
$statement->execute($completedSources);
$completedCount = (int) $statement->fetchColumn();

echo json_encode(['completed_sources' => $completedCount, 'max_open_units' => $maxOpen, 'transport_events' => $transportEvents], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert($completedCount === 10 && $maxOpen === 2 && $transportEvents === 0, 'sustained_local_success_must_reuse_slots_without_http', [
    'completed_sources' => $completedCount,
    'max_open_units' => $maxOpen,
    'transport_events' => $transportEvents,
]);
