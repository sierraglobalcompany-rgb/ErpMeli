<?php

declare(strict_types=1);

use App\Services\ApiRhythmDeferredException;
use App\Services\ApiRhythmPolicyService;

putenv('APP_ENV=local');
putenv('ML_WRITE_ENABLED=false');
require dirname(__DIR__) . '/vendor/autoload.php';

$accountId = max(1, (int) ($argv[1] ?? 0));
$barrier = (string) ($argv[2] ?? '');
$deadline = microtime(true) + 5;
while ($barrier !== '' && !is_file($barrier) && microtime(true) < $deadline) {
    usleep(1000);
}

$result = ['dispatched' => false, 'scope' => null];
try {
    $service = new ApiRhythmPolicyService();
    $permit = $service->reserve($accountId, 'GET', '/orders/concurrent', ['job_type' => 'orders_sync']);
    if ($service->dispatched($permit)) {
        $result['dispatched'] = true;
        usleep(350000);
        $service->finalizeKnownResult($permit, 200);
    }
} catch (ApiRhythmDeferredException $error) {
    $result['scope'] = $error->blockingScope;
}

echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
