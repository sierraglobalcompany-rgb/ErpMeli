<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueCore\HistoricalAdmissionPolicy;
use App\QueueCore\HistoricalBacklogImporter;
use App\QueueCore\HistoricalBacklogSourceRegistry;
use App\QueueCore\HistoricalSourceClosureService;
use App\QueueCore\QueueCoreRepository;

require dirname(__DIR__) . '/bootstrap.php';

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
$has = static fn (string $flag): bool => in_array('--' . $flag, $arguments, true);
$write = static function (array $result): void {
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
};

try {
    Database::useProfile('cli');
    $pdo = Database::connectionFresh();
    $registry = new HistoricalBacklogSourceRegistry();
    $action = (string) $option('action', 'status');
    if ($action === 'sources') {
        $write(['ok' => true, 'status' => 'read_only', 'sources' => $registry->keys()]);
        exit(0);
    }
    if ($action === 'status') {
        $rows = $pdo->query(
            'SELECT source_key,company_id,meli_account_id,enabled,state,high_water_id,cursor_id,
                    generation,last_scanned,last_created,last_duplicates,last_reviewed,updated_at
             FROM queue_core_historical_checkpoints ORDER BY source_key,company_id,meli_account_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $write(['ok' => true, 'status' => 'read_only', 'checkpoints' => $rows]);
        exit(0);
    }
    $source = (string) $option('source', '');
    $company = (int) $option('company', '0');
    $account = (int) $option('account', '0');
    $capacity = (int) $option('capacity', '10');
    $importer = new HistoricalBacklogImporter(
        $pdo,
        new QueueCoreRepository($pdo),
        $registry,
        new HistoricalAdmissionPolicy($capacity),
    );
    if ($action === 'enable') {
        if (!$has('confirm-enable')) {
            $write(['ok' => false, 'status' => 'explicit_confirmation_required']);
            exit(2);
        }
        $write(['ok' => true, 'status' => 'enabled', 'checkpoint' => $importer->enable($source, $company, $account, 'historical_cli')]);
        exit(0);
    }
    if ($action === 'enable-global') {
        if (!$has('confirm-enable')) {
            $write(['ok' => false, 'status' => 'explicit_confirmation_required']);
            exit(2);
        }
        $update = $pdo->prepare(
            "UPDATE queue_core_feature_flags
             SET enabled=1,generation=generation+1,updated_at=UTC_TIMESTAMP(3)
             WHERE feature_key='historical_importer' AND enabled=0"
        );
        $update->execute();
        $write(['ok' => true, 'status' => 'historical_importer_enabled', 'changed' => $update->rowCount()]);
        exit(0);
    }
    if ($action === 'import') {
        $write(['ok' => true, 'result' => $importer->run($source, $company, $account, (int) $option('limit', '50'))]);
        exit(0);
    }
    if ($action === 'close') {
        $result = (new HistoricalSourceClosureService($pdo, $registry))->closeCompleted((int) $option('limit', '50'));
        $write(['ok' => true, 'status' => 'closed_known_results', 'result' => $result]);
        exit(0);
    }
    $write(['ok' => false, 'status' => 'unknown_action']);
    exit(2);
} catch (Throwable $error) {
    $write(['ok' => false, 'status' => 'local_failure', 'error_class' => strtolower((new ReflectionClass($error))->getShortName())]);
    exit(2);
}
