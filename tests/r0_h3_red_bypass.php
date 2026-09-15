<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];

$admission = new App\Services\CronAdmissionService($pdo);
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
    r0h3_assert(!empty($receipt['accepted']), 'setup_first_two_admissions_expected', ['receipt' => $receipt]);
}

$third = $healthy[2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue(
    (int) $third['meli_account_id'],
    (int) $third['order_id'],
    'pack',
    (string) $third['external_pack_id'],
    10
);
$pdo->prepare("UPDATE order_resource_enrichment_jobs SET failure_class='remote_result_uncertain_safe_get',status='retry' WHERE id=?")->execute([$thirdSource]);

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
     WHERE q.job_type='domain_exact'
       AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
       AND q.company_id=7200"
)->fetchColumn();

echo json_encode(['third_receipt' => $receipt, 'new_queue_units' => $count], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    empty($receipt['accepted'])
    && ($receipt['reason'] ?? '') === 'R0_SOURCE_UNCERTAIN_HELD'
    && $count === 2,
    'uncertain_pack_source_must_not_be_admitted_or_retried',
    ['receipt' => $receipt, 'count' => $count]
);
