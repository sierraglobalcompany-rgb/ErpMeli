<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$admission = new App\Services\CronAdmissionService($pdo);
foreach ([0, 1] as $i) {
    $target = $fixture['healthy'][$i];
    $sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
    $pdo->beginTransaction();
    $receipt = $admission->submit('order_enrichment_pack', (int) $target['company_id'], (int) $target['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
    $pdo->commit();
    r0h3_assert(!empty($receipt['accepted']), 'initial_open_unit_admission_expected', ['receipt' => $receipt]);
}

$beforeSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202)")->fetchColumn();
$beforeQueue = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE company_id=7200")->fetchColumn();
$producer = new App\QueueV4Clean\QueueV4CleanProducer($pdo, new App\QueueV4Clean\QueueV4CleanRepository($pdo));
$method = new ReflectionMethod($producer, 'schedulePackExactDiscovery');
$created = $method->invoke($producer, [
    ['company_id' => 7200, 'meli_account_id' => 7201],
    ['company_id' => 7200, 'meli_account_id' => 7202],
]);
$afterSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202)")->fetchColumn();
$afterQueue = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE company_id=7200")->fetchColumn();

echo json_encode([
    'created' => $created,
    'before_sources' => $beforeSources,
    'after_sources' => $afterSources,
    'before_queue' => $beforeQueue,
    'after_queue' => $afterQueue,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert($created === 0, 'producer_must_not_create_when_occupancy_exhausted', ['created' => $created]);
r0h3_assert($afterSources === $beforeSources && $afterQueue === $beforeQueue, 'denied_coverage_must_not_leave_orphan_sources_or_queue', [
    'before_sources' => $beforeSources,
    'after_sources' => $afterSources,
    'before_queue' => $beforeQueue,
    'after_queue' => $afterQueue,
]);
