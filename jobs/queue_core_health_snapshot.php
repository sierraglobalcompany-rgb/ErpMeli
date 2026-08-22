<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueCore\QueueCoreHealthService;

require dirname(__DIR__) . '/bootstrap.php';

if (in_array('--persist', $_SERVER['argv'] ?? [], true)) {
    fwrite(STDERR, "LEGACY_TOOL_BLOCKED component=queue_core_health_snapshot remote=false http=0\n");
    exit(2);
}

try {
    Database::useProfile('cli');
    $service = new QueueCoreHealthService(Database::connectionFresh());
    $persist = false;
    $result = $service->latestPersisted();
    fwrite(STDOUT, json_encode([
        'ok' => $result !== null,
        'mode' => $persist ? 'technical_snapshot_write' : 'read_only',
        'snapshot' => $result,
        'remote_http_calls' => 0,
        'business_data_writes' => 0,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($result !== null ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'status' => 'local_failure',
        'error_class' => strtolower((new ReflectionClass($error))->getShortName()),
        'remote_http_calls' => 0,
        'business_data_writes' => 0,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(2);
}
