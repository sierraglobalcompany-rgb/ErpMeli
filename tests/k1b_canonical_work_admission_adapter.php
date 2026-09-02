<?php

declare(strict_types=1);

require_once __DIR__ . '/k1b_bootstrap.php';

use App\Work\Adapters\QueueV4CanonicalWorkStore;
use App\Work\WorkAdmissionReceipt;
use App\Work\WorkContractVersion;
use App\Work\WorkEnvelope;

$order = QueueV4CanonicalWorkStore::physicalShapeFor(new WorkEnvelope(
    WorkContractVersion::CURRENT,
    10,
    20,
    'order_exact',
    '200000000001',
    'order:200000000001',
    ['order_id' => '200000000001'],
));
k1b_assert($order['job_type'] === 'order_exact', 'order_exact_maps_to_queue_v4_job_type');
k1b_assert($order['resource_id'] === '200000000001', 'order_exact_preserves_resource_id');
k1b_assert($order['state'] === 'ready', 'order_exact_ready_by_default');

$finance = QueueV4CanonicalWorkStore::physicalShapeFor(new WorkEnvelope(
    WorkContractVersion::CURRENT,
    10,
    20,
    'financial_reconciliation',
    '123',
    'sale-finance:123',
    ['source' => 'test'],
    new DateTimeImmutable('+10 minutes'),
));
$payload = json_decode((string) $finance['payload_json'], true, 8, JSON_THROW_ON_ERROR);
k1b_assert($finance['job_type'] === 'domain_exact', 'financial_reconciliation_maps_to_domain_exact');
k1b_assert($payload['capability'] === 'financial_reconciliation', 'domain_exact_preserves_capability');
k1b_assert((int) $payload['source_id'] === 123, 'domain_exact_preserves_source_id');
k1b_assert(str_starts_with((string) $finance['idempotency_key'], 'domain:financial_reconciliation:'), 'domain_exact_has_domain_idempotency_prefix');
k1b_assert($finance['state'] === 'waiting', 'future_domain_work_enters_waiting');

$receipt = new WorkAdmissionReceipt(true, 77, true, 'ALREADY_QUEUED', 'financial_reconciliation', QueueV4CanonicalWorkStore::ADAPTER);
$legacy = $receipt->toLegacyCronAdmissionReceipt();
k1b_assert($legacy['accepted'] === true && $legacy['job_id'] === 77 && $legacy['deduplicated'] === true, 'dedupe_returns_legacy_compatible_receipt');

$rejected = false;

try {
    QueueV4CanonicalWorkStore::physicalShapeFor(new WorkEnvelope(
        WorkContractVersion::CURRENT,
        10,
        20,
        'domain_exact',
        '123',
        'domain:missing-capability',
        [],
    ));
} catch (RuntimeException) {
    $rejected = true;
}

k1b_assert($rejected, 'unsupported_domain_exact_fail_closed');

echo "K1B_CANONICAL_WORK_ADMISSION_ADAPTER=PASS\n";
