<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';
$arguments=is_array($_SERVER['argv']??null)?array_map('strval',$_SERVER['argv']):[];

$option = static function (array $arguments, string $name): ?string {
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }
    return null;
};

$recordRequested = ($option($arguments, 'record') ?? '0') === '1';
$companyId = (int) ($option($arguments, 'company') ?? 0);
$accountId = (int) ($option($arguments, 'account') ?? 0);
$pdo = null;
try {
    \App\Core\Database::useProfile('cli');
    $pdo = \App\Core\Database::connectionFresh();
    $result = \App\QueueCore\QueueCoreReadinessOperationLock::with($pdo, static function () use (
        $pdo, $companyId, $accountId, $option, $arguments, $recordRequested
    ): array {
        $receipts = new \App\QueueCore\QueueCoreReadinessReceiptService($pdo);
        $engine = (new \App\QueueCore\QueueEngineControlService($pdo))->snapshot();
        if ($recordRequested && $companyId > 0 && $accountId > 0) {
            // Un FAIL previo a la medición impide que una excepción o caída deje
            // reutilizable un PASS anterior cuando el lock vuelva a liberarse.
            $receipts->record(
                $engine['generation'], 'convergence', false,
                ['reason' => 'convergence_measurement_in_progress'], 3600, $companyId, $accountId
            );
        }
        try {
            $service = new \App\QueueCore\QueueCoreConvergenceService($pdo);
            $result = $service->compare(
                $companyId,
                $accountId,
                (string) ($option($arguments, 'from') ?? ''),
                (string) ($option($arguments, 'to') ?? ''),
                (int) ($option($arguments, 'limit') ?? 500),
            );
        } catch (\Throwable) {
            return [
                'ok' => false,
                'reason' => 'convergence_unavailable',
                'remote_http_calls' => 0,
                'business_db_writes' => 0,
                'technical_receipt_written' => $recordRequested && $companyId > 0 && $accountId > 0,
            ];
        }
        if ($recordRequested) {
            $receipts->record(
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
        return $result;
    });
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
