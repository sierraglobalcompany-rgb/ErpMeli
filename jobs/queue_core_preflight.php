<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

try {
    \App\Core\Database::useProfile('cli');
    $pdo = \App\Core\Database::connectionFresh();
    $result = (new \App\QueueCore\QueueCorePreflightService($pdo))->check(true);
    $record = in_array('--record', $argv ?? [], true);
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
