<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

use App\Work\Adapters\QueueV4CanonicalWorkStore;
use App\Work\WorkContractVersion;
use App\Work\WorkEnvelope;

function r0h3_admit_two_open_units(PDO $pdo, array $healthy): void
{
    $admission = new App\Services\CronAdmissionService($pdo);
    for ($i = 0; $i < 2; $i++) {
        $target = $healthy[$i];
        $sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
        $pdo->beginTransaction();
        $receipt = $admission->submit('order_enrichment_pack', (int) $target['company_id'], (int) $target['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
        $pdo->commit();
        r0h3_assert(!empty($receipt['accepted']), 'canonical_setup_admission_expected', ['receipt' => $receipt]);
    }
}

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
r0h3_admit_two_open_units($pdo, $healthy);

$third = $healthy[2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10);
$store = new QueueV4CanonicalWorkStore($pdo);
$receipt = $store->admit(new WorkEnvelope(
    WorkContractVersion::CURRENT,
    (int) $third['company_id'],
    (int) $third['meli_account_id'],
    'order_enrichment_pack',
    (string) $thirdSource,
    'source:' . $thirdSource,
    ['pack_id' => (string) $third['external_pack_id']],
));

$count = (int) $pdo->query(
    "SELECT COUNT(DISTINCT q.company_id, q.meli_account_id, q.resource_id)
     FROM queue_v4_clean_jobs q
     WHERE q.job_type='domain_exact'
       AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
       AND q.company_id=7200"
)->fetchColumn();

echo json_encode(['workstore_order_enrichment_pack' => $receipt, 'new_units' => $count], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(!$receipt->accepted && $receipt->reason === 'R0_OCCUPANCY_EXHAUSTED' && $count === 2, 'canonical_workstore_pack_entrypoint_must_not_bypass_h3', ['receipt' => $receipt, 'count' => $count]);

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
r0h3_admit_two_open_units($pdo, $healthy);
$third = $healthy[2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10);
$receipt = (new QueueV4CanonicalWorkStore($pdo))->admit(new WorkEnvelope(
    WorkContractVersion::CURRENT,
    (int) $third['company_id'],
    (int) $third['meli_account_id'],
    'domain_exact',
    (string) $thirdSource,
    'domain:order_enrichment_pack:source:' . $thirdSource,
    ['capability' => 'order_enrichment_pack', 'source_id' => $thirdSource, 'payload' => ['pack_id' => (string) $third['external_pack_id']]],
));

echo json_encode(['workstore_domain_exact_pack' => $receipt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(!$receipt->accepted && $receipt->reason === 'R0_OCCUPANCY_EXHAUSTED', 'canonical_domain_exact_pack_representation_must_not_bypass_h3', ['receipt' => $receipt]);

$fixture = r0h3_seed($pdo);
$target = $fixture['healthy'][0];
$sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
$pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='complete', completed_at=UTC_TIMESTAMP(3) WHERE id=?")->execute([$sourceId]);
$receipt = (new QueueV4CanonicalWorkStore($pdo))->admit(new WorkEnvelope(
    WorkContractVersion::CURRENT,
    (int) $target['company_id'],
    (int) $target['meli_account_id'],
    'order_enrichment_pack',
    (string) $sourceId,
    'source:' . $sourceId,
    ['pack_id' => (string) $target['external_pack_id']],
));

echo json_encode(['workstore_completed_source' => $receipt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(!$receipt->accepted && $receipt->reason === 'R0_SOURCE_NOT_ELIGIBLE', 'canonical_workstore_must_not_admit_completed_source_without_existing_pointer', ['receipt' => $receipt]);
