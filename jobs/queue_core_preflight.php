<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments=is_array($_SERVER['argv']??null)?array_map('strval',$_SERVER['argv']):[];

foreach ($arguments as $argument) {
    if ($argument === '--record' || str_starts_with($argument, '--record=')) {
        fwrite(STDERR, "LEGACY_TOOL_BLOCKED component=queue_core_preflight remote=false http=0\n");
        exit(2);
    }
}

require dirname(__DIR__) . '/bootstrap.php';

try {
    \App\Core\Database::useProfile('cli');
    $pdo = \App\Core\Database::connectionFresh();
    $result = \App\QueueCore\QueueCoreReadinessOperationLock::with($pdo, static function () use ($pdo, $arguments): array {
        $result = (new \App\QueueCore\QueueCorePreflightService($pdo))->check(true);
        $record = false;
        if ($record) {
            $engine = (new \App\QueueCore\QueueEngineControlService($pdo))->snapshot();
            (new \App\QueueCore\QueueCoreReadinessReceiptService($pdo))->record(
                $engine['generation'],
                'preflight',
                !empty($result['ok']),
                [
                    'connected_accounts' => count($result['accounts'] ?? []),
                    'capabilities' => count($result['capabilities'] ?? []),
                    'missing_tables' => count($result['missing_tables'] ?? []),
                ],
                3600,
            );
            $result['technical_receipt_written'] = true;
        } else {
            $result['technical_receipt_written'] = false;
        }
        return $result;
    });
} catch (\Throwable) {
    $result = [
        'ok' => false,
        'issues' => ['preflight_unavailable'],
        'remote_http_calls' => 0,
        'business_db_writes' => 0,
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 2);
