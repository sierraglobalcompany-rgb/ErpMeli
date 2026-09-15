<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$raw = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority'")->fetchColumn();
$authority = json_decode((string) $raw, true, 64, JSON_THROW_ON_ERROR);
foreach ($authority['historical'] as &$entry) {
    unset($entry['attempts_sha256']);
}
unset($entry);
$pdo->prepare("UPDATE app_settings SET setting_value=? WHERE setting_key='queue_v4.pack_discovery_h3_authority'")
    ->execute([json_encode($authority, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);

$target = $fixture['healthy'][0];
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

echo json_encode(['legacy_authority_receipt' => $receipt], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    empty($receipt['accepted']) && ($receipt['reason'] ?? '') === 'R0_H3_AUTHORITY_INCOMPLETE',
    'legacy_h3_authority_without_attempts_hash_must_fail_closed',
    ['receipt' => $receipt]
);

