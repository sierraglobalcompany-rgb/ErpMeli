<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\CronAdmissionService;
use App\Services\OrderEnrichmentService;

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
$admission = new CronAdmissionService($pdo);
$enrichment = new OrderEnrichmentService();

for ($i = 0; $i < 2; $i++) {
    $target = $healthy[$i];
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
    r0h3_assert(!empty($receipt['accepted']), 'producer_guard_setup_admission_expected', ['receipt' => $receipt]);
}

$beforeQueue = r0h3_open_new_pack_units($pdo);
$beforeSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE resource_type='pack' AND meli_account_id IN (7201,7202)")->fetchColumn();

$producer = new QueueV4CleanProducer($pdo, new QueueV4CleanRepository($pdo));
$result = $producer->produce(300);

$afterQueue = r0h3_open_new_pack_units($pdo);
$afterSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE resource_type='pack' AND meli_account_id IN (7201,7202)")->fetchColumn();

echo json_encode(['producer' => $result, 'before_queue' => $beforeQueue, 'after_queue' => $afterQueue, 'before_sources' => $beforeSources, 'after_sources' => $afterSources], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    $beforeQueue === 2
    && $afterQueue === 2
    && (int) ($result['pack_discovery_created'] ?? -1) === 0
    && $afterSources === $beforeSources,
    'producer_must_not_create_pack_coverage_or_admission_when_two_new_units_occupy_global_slots',
    ['producer' => $result, 'before_queue' => $beforeQueue, 'after_queue' => $afterQueue, 'before_sources' => $beforeSources, 'after_sources' => $afterSources]
);

