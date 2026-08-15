<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueCore\HistoricalBacklogSourceRegistry;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

$rawArguments = $_SERVER['argv'] ?? [];
$arguments = is_array($rawArguments) ? array_map('strval', $rawArguments) : [];
$option = static function (string $name, ?string $default = null) use ($arguments): ?string {
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with((string) $argument, $prefix)) {
            return trim(substr((string) $argument, strlen($prefix)));
        }
    }
    return $default;
};
$write = static function (array $result): void {
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
};
$action = (string) $option('action', 'status');
if (!in_array($action, ['status', 'sources'], true)) {
    $safeAction = preg_replace('/[^A-Za-z0-9_.-]/', '_', $action) ?: 'unknown';
    $write([
        'ok' => false,
        'status' => 'LEGACY_MUTATION_RETIRED',
        'component' => 'queue_core_historical',
        'action' => $safeAction,
        'remote' => false,
        'http' => 0,
    ]);
    exit(0);
}

require dirname(__DIR__) . '/bootstrap.php';

try {
    $registry = new HistoricalBacklogSourceRegistry();
    if ($action === 'sources') {
        $write(['ok' => true, 'status' => 'read_only', 'sources' => $registry->keys()]);
        exit(0);
    }
    Database::useProfile('cli');
    $pdo = Database::connectionFresh();
    if ($action === 'status') {
        $rows = $pdo->query(
            'SELECT source_key,company_id,meli_account_id,enabled,state,high_water_id,cursor_id,
                    generation,last_scanned,last_created,last_duplicates,last_reviewed,updated_at
             FROM queue_core_historical_checkpoints ORDER BY source_key,company_id,meli_account_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $write(['ok' => true, 'status' => 'read_only', 'checkpoints' => $rows]);
        exit(0);
    }
} catch (Throwable $error) {
    $write(['ok' => false, 'status' => 'local_failure', 'error_class' => strtolower((new ReflectionClass($error))->getShortName())]);
    exit(2);
}
