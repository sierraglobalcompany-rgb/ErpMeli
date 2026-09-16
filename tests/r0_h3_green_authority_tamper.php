<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];

$pdo->exec("UPDATE queue_v4_clean_jobs SET last_error_class='tampered-r0-h3-test' WHERE id=(SELECT JSON_UNQUOTE(JSON_EXTRACT(setting_value,'$.historical[0].queue_id')) FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority')");

$target = $healthy[0];
$sourceId = (new App\Services\OrderEnrichmentService())->enqueue(
    (int) $target['meli_account_id'],
    (int) $target['order_id'],
    'pack',
    (string) $target['external_pack_id'],
    10
);

$pdo->beginTransaction();
$receipt = (new App\Services\CronAdmissionService($pdo))->submit(
    'order_enrichment_pack',
    (int) $target['company_id'],
    (int) $target['meli_account_id'],
    $sourceId,
    'source:' . $sourceId,
    ['pack_id' => (string) $target['external_pack_id']]
);
$pdo->commit();

echo json_encode(['receipt' => $receipt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    empty($receipt['accepted']) && ($receipt['reason'] ?? '') === 'R0_H3_AUTHORITY_CHANGED',
    'h3_exception_must_fail_closed_if_historical_identity_changes',
    ['receipt' => $receipt]
);

