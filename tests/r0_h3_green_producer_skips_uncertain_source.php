<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\OrderEnrichmentService;

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
$stale = $healthy[0];
$staleSource = (new OrderEnrichmentService())->enqueue((int) $stale['meli_account_id'], (int) $stale['order_id'], 'pack', (string) $stale['external_pack_id'], 10);
$pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='retry', failure_class='remote_result_uncertain_safe_get', reached_remote=1, next_run_at='2000-01-01' WHERE id=?")->execute([$staleSource]);

$beforeSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE resource_type='pack' AND meli_account_id IN (7201,7202)")->fetchColumn();
$result = (new QueueV4CleanProducer($pdo, new QueueV4CleanRepository($pdo)))->produce(300);
$afterSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE resource_type='pack' AND meli_account_id IN (7201,7202)")->fetchColumn();
$open = r0h3_open_new_pack_units($pdo);

echo json_encode(['producer' => $result, 'before_sources' => $beforeSources, 'after_sources' => $afterSources, 'open_units' => $open], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    (int) ($result['pack_discovery_created'] ?? 0) === 2
    && $afterSources === $beforeSources + 2
    && $open === 2,
    'producer_must_skip_uncertain_source_and_fill_two_healthy_slots_without_orphan_growth',
    ['producer' => $result, 'before_sources' => $beforeSources, 'after_sources' => $afterSources, 'open' => $open]
);
