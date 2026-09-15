<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
$past = gmdate('Y-m-d H:i:s', time() - 7200);

$existing = $healthy[1];
$existingSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $existing['meli_account_id'], (int) $existing['order_id'], 'pack', (string) $existing['external_pack_id'], 10);
$pdo->prepare('UPDATE order_resource_enrichment_jobs SET next_run_at=? WHERE id=?')->execute([$past, $existingSource]);

$beforeSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();
$producer = new App\QueueV4Clean\QueueV4CleanProducer($pdo, new App\QueueV4Clean\QueueV4CleanRepository($pdo));
$method = new ReflectionMethod($producer, 'schedulePackExactDiscovery');
$created = (int) $method->invoke($producer, [
    ['company_id' => 7200, 'meli_account_id' => 7201],
    ['company_id' => 7200, 'meli_account_id' => 7202],
]);
$afterSources = (int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202) AND resource_type='pack'")->fetchColumn();
$admittedSources = array_map('intval', $pdo->query(
    "SELECT CAST(resource_id AS UNSIGNED) FROM queue_v4_clean_jobs
     WHERE company_id=7200 AND job_type='domain_exact'
       AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='order_enrichment_pack'
     ORDER BY CAST(resource_id AS UNSIGNED)"
)->fetchAll(PDO::FETCH_COLUMN));
$orphanSources = array_map('intval', $pdo->query(
    "SELECT j.id FROM order_resource_enrichment_jobs j
     LEFT JOIN queue_v4_clean_jobs q
       ON q.company_id=7200
      AND q.meli_account_id=j.meli_account_id
      AND q.job_type='domain_exact'
      AND q.resource_id=CAST(j.id AS CHAR)
      AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
     WHERE j.meli_account_id IN (7201,7202) AND j.resource_type='pack' AND q.id IS NULL
     ORDER BY j.id"
)->fetchAll(PDO::FETCH_COLUMN));

echo json_encode(['created' => $created, 'before_sources' => $beforeSources, 'after_sources' => $afterSources, 'existing_source' => $existingSource, 'admitted_sources' => $admittedSources, 'orphan_sources' => $orphanSources], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert($created === 2, 'producer_may_fill_two_available_global_slots', ['created' => $created]);
r0h3_assert($afterSources === $beforeSources + 1, 'producer_must_create_only_the_source_it_admits_after_existing_candidate', ['before' => $beforeSources, 'after' => $afterSources]);
r0h3_assert(in_array($existingSource, $admittedSources, true), 'producer_must_admit_existing_candidate_before_creating_more_coverage', ['expected' => $existingSource, 'actual' => $admittedSources]);
r0h3_assert($orphanSources === [], 'producer_must_not_leave_pack_source_coverage_without_pointer', ['orphans' => $orphanSources]);
