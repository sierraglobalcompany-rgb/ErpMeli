<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

use App\Services\MeliApiClient;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label);
    }
    echo 'PASS:' . $label . PHP_EOL;
};

$invalidValues = [
    ['771101', '771102'],
    '771101,771102',
    '',
    ' 771101 ',
    'not-an-order-id',
];

foreach ($invalidValues as $invalidValue) {
    try {
        (new MeliApiClient(9011))->get('/billing/integration/group/ML/order/details', ['order_ids' => $invalidValue]);
        $error = null;
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $assert(
        $error instanceof RuntimeException
            && $error->getMessage() === 'Billing order_ids debe contener un único ID válido.',
        'billing_client_rejects_invalid_cardinality_before_transport'
    );
}

echo "STATUS=PASS CALLS_V2_PHASE3_BILLING_CLIENT_GUARD\n";
