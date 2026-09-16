<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
$admission = new App\Services\CronAdmissionService($pdo);
$admitted = [];

for ($i = 0; $i < 2; $i++) {
    $target = $healthy[$i];
    $sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
    $pdo->beginTransaction();
    $receipt = $admission->submit('order_enrichment_pack', (int) $target['company_id'], (int) $target['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
    $pdo->commit();
    r0h3_assert(!empty($receipt['accepted']), 'setup_admission_expected', ['receipt' => $receipt]);
    $admitted[] = ['source_id' => $sourceId, 'queue_id' => (int) $receipt['job_id'], 'target' => $target];
}

$pdo->prepare("UPDATE queue_v4_clean_jobs SET payload_json=? WHERE id=?")->execute([
    json_encode(['capability' => 'financial_reconciliation', 'source_id' => $admitted[0]['source_id'], 'payload' => ['pack_id' => (string) $admitted[0]['target']['external_pack_id']]], JSON_UNESCAPED_SLASHES),
    $admitted[0]['queue_id'],
]);

$third = $healthy[2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10);
$pdo->beginTransaction();
$thirdReceipt = $admission->submit('order_enrichment_pack', (int) $third['company_id'], (int) $third['meli_account_id'], $thirdSource, 'source:' . $thirdSource, ['pack_id' => (string) $third['external_pack_id']]);
$pdo->commit();

echo json_encode(['third_receipt' => $thirdReceipt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    empty($thirdReceipt['accepted']) && ($thirdReceipt['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED',
    'capability_discordant_pack_pointer_must_fail_closed',
    ['receipt' => $thirdReceipt]
);

