<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueCore\QueueCoreHealthService;

require dirname(__DIR__) . '/bootstrap.php';

try {
    Database::useProfile('cli');
    $service = new QueueCoreHealthService(Database::connectionFresh());
    $persist = in_array('--persist', $_SERVER['argv'] ?? [], true);
    $result = $persist ? $service->persistSnapshot() : $service->latestPersisted();
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
