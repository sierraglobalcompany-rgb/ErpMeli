<?php

declare(strict_types=1);

use App\Core\Database;
use App\Work\Adapters\QueueV4CurrentDrainer;
use App\Work\Contracts\DrainerContract;
use App\QueueV4Clean\QueueV4CleanOAuthStageContext;
use App\QueueV4Clean\QueueV4CleanSafeDiagnosticService;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AutomationCallBudgetService;
use App\Services\AutomationCliCapacityArgumentParser;
use App\Services\CronDeadlineContext;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['runtime::', 'max-jobs::', 'max-calls::']);
$runtime = max(5, min(45, (int) ($options['runtime'] ?? 45)));
$hasCanonicalMaxCalls = array_key_exists('max-calls', $options);

try {
    $capacityArgs = (new AutomationCliCapacityArgumentParser())->parse($options);
} catch (\InvalidArgumentException $error) {
    fwrite(STDERR, "QUEUE_V4_CLEAN_FAILED\n");
    if ($error->getMessage() === 'legacy_capacity_argument_removed') {
        fwrite(STDERR, "safe_error=La unidad trabajos fue retirada; configure llamadas físicas en el ERP.\n");
        exit(2);
    }
    fwrite(STDERR, 'safe_error=' . $error->getMessage() . PHP_EOL);
    exit(2);
}

try {
    Database::useProfile('cli');
    $budget = (new AutomationCallBudgetService())->resolve(
        $capacityArgs['max_calls'],
    );
    $requestedMaxCalls = (int) $budget['requested_max_calls'];
    CronDeadlineContext::start($runtime, max(1, $runtime - 5), 8, 3);
    /** @var DrainerContract $drainer */
    $drainer = new QueueV4CurrentDrainer(Database::connectionFresh());
    $result = $drainer->drain('cron_v4', $requestedMaxCalls, $runtime)->metadata;
    $result['control_unit'] = 'PHYSICAL_API_CALL';
    $result['max_calls_source'] = $budget['max_calls_source'];
    $result['canonical_max_calls_input_used'] = $hasCanonicalMaxCalls;
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $error) {
    $receipt = (new QueueV4CleanSafeDiagnosticService())->capture(
        $error,
        QueueV4CleanOAuthStageContext::current()
    );
    fwrite(STDERR, "QUEUE_V4_CLEAN_FAILED\n");
    fwrite(STDERR, 'diagnostic_id=' . $receipt['diagnostic_id'] . PHP_EOL);
    fwrite(STDERR, 'error_class=' . $receipt['error_class'] . PHP_EOL);
    fwrite(STDERR, 'safe_stage=' . $receipt['safe_stage'] . PHP_EOL);
    fwrite(STDERR, 'file=' . $receipt['file'] . PHP_EOL);
    fwrite(STDERR, 'line=' . $receipt['line'] . PHP_EOL);
    exit(1);
} finally {
    CronDeadlineContext::clear();
}
