<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$option = static function (array $arguments, string $name): ?string {
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }
    return null;
};

try {
    \App\Core\Database::useProfile('cli');
    $pdo = \App\Core\Database::connectionFresh();
    $service = new \App\QueueCore\QueueCoreConvergenceService($pdo);
    $result = $service->compare(
        (int) ($option($argv ?? [], 'company') ?? 0),
        (int) ($option($argv ?? [], 'account') ?? 0),
        (string) ($option($argv ?? [], 'from') ?? ''),
        (string) ($option($argv ?? [], 'to') ?? ''),
        (int) ($option($argv ?? [], 'limit') ?? 500),
    );
    if (($option($argv ?? [], 'record') ?? '0') === '1') {
        $engine = (new \App\QueueCore\QueueEngineControlService($pdo))->snapshot();
        (new \App\QueueCore\QueueCoreReadinessReceiptService($pdo))->record(
            $engine['generation'],
            'convergence',
            !empty($result['ok']),
            [
                'remote_count' => (int) $result['remote_identity_count'],
                'local_count' => (int) $result['local_identity_count'],
                'unresolved_count' => (int) $result['unresolved_exact_count'],
                'page_count' => (int) $result['authoritative_page_count'],
                'empty_window' => !empty($result['authoritative_empty_window']) ? 1 : 0,
            ],
            3600,
            (int) $result['company_id'],
            (int) $result['meli_account_id'],
        );
        $result['technical_receipt_written'] = true;
    } else {
        $result['technical_receipt_written'] = false;
    }
} catch (\Throwable) {
    $result = [
        'ok' => false,
        'reason' => 'convergence_unavailable',
        'remote_http_calls' => 0,
        'business_db_writes' => 0,
        'technical_receipt_written' => false,
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 2);
