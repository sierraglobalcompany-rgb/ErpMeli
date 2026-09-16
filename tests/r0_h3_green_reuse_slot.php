<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
$admission = new App\Services\CronAdmissionService($pdo);
$admitted = [];

for ($i = 0; $i < 2; $i++) {
    $target = $healthy[$i];
    $sourceId = (new App\Services\OrderEnrichmentService())->enqueue(
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
    r0h3_assert(!empty($receipt['accepted']), 'setup_admission_expected', ['receipt' => $receipt]);
    $admitted[] = ['source_id' => $sourceId, 'queue_id' => (int) $receipt['job_id'], 'target' => $target];
}

$pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='complete', completed_at=UTC_TIMESTAMP(3), failure_class=NULL, lock_token=NULL, locked_at=NULL WHERE id=?")->execute([$admitted[0]['source_id']]);
$pdo->prepare("UPDATE queue_v4_clean_jobs SET state='completed', completed_at=UTC_TIMESTAMP(3), lease_owner=NULL, lease_expires_at=NULL WHERE id=?")->execute([$admitted[0]['queue_id']]);

$third = $healthy[2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue(
    (int) $third['meli_account_id'],
    (int) $third['order_id'],
    'pack',
    (string) $third['external_pack_id'],
    10
);
$pdo->beginTransaction();
$receipt = $admission->submit(
    'order_enrichment_pack',
    (int) $third['company_id'],
    (int) $third['meli_account_id'],
    $thirdSource,
    'source:' . $thirdSource,
    ['pack_id' => (string) $third['external_pack_id']]
);
$pdo->commit();

$count = (int) $pdo->query(
    "SELECT COUNT(DISTINCT q.company_id, q.meli_account_id, q.resource_id)
     FROM queue_v4_clean_jobs q
     JOIN order_resource_enrichment_jobs j
       ON j.id=CAST(q.resource_id AS UNSIGNED) AND j.meli_account_id=q.meli_account_id
     WHERE q.job_type='domain_exact'
       AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
       AND q.company_id=7200
       AND NOT (q.state='completed' AND j.status='complete')"
)->fetchColumn();

echo json_encode(['third_receipt' => $receipt, 'state_only_open_units' => $count], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    empty($receipt['accepted'])
    && ($receipt['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED',
    'state_only_closure_must_not_free_slot_without_closure_provenance',
    ['receipt' => $receipt, 'count' => $count]
);
