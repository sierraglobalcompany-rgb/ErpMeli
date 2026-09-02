<?php

declare(strict_types=1);

require_once __DIR__ . '/k1b_bootstrap.php';

use App\Work\WorkContractVersion;
use App\Work\WorkEnvelope;

$valid = new WorkEnvelope(
    WorkContractVersion::CURRENT,
    1,
    2,
    'order_exact',
    '200000000001',
    'order:200000000001',
    ['order_id' => '200000000001'],
);
k1b_assert($valid->contractVersion === 1, 'contract_version_is_1');
k1b_assert($valid->payloadJson() === '{"order_id":"200000000001"}', 'payload_json_stable');

$cases = [
    'invalid_tenant' => fn () => new WorkEnvelope(1, 0, 2, 'order_exact', '1', 'k', []),
    'empty_idempotency' => fn () => new WorkEnvelope(1, 1, 2, 'order_exact', '1', '', []),
    'unsupported_type' => fn () => new WorkEnvelope(1, 1, 2, 'queue_v5', '1', 'k', []),
    'oversized_payload' => fn () => new WorkEnvelope(1, 1, 2, 'order_exact', '1', 'k', ['x' => str_repeat('a', 4097)]),
    'invalid_version' => fn () => new WorkEnvelope(2, 1, 2, 'order_exact', '1', 'k', []),
];

foreach ($cases as $name => $factory) {
    $rejected = false;

    try {
        $factory();
    } catch (RuntimeException) {
        $rejected = true;
    }

    k1b_assert($rejected, $name . '_rejected');
}

echo "K1B_WORK_ENVELOPE_CONTRACT=PASS\n";
