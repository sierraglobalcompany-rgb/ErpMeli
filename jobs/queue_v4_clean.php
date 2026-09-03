<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanScheduler;
use App\QueueV4Clean\QueueV4CleanOAuthStageContext;
use App\QueueV4Clean\QueueV4CleanSafeDiagnosticService;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AutomationCallBudgetService;
use App\Services\CronDeadlineContext;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['runtime::', 'max-jobs::', 'max-calls::']);
$runtime = max(5, min(45, (int) ($options['runtime'] ?? 45)));
$hasLegacyMaxJobs = array_key_exists('max-jobs', $options);
$hasCanonicalMaxCalls = array_key_exists('max-calls', $options);
if ($hasLegacyMaxJobs && $hasCanonicalMaxCalls) {
    fwrite(STDERR, "QUEUE_V4_CLEAN_FAILED\n");
    fwrite(STDERR, "safe_error=dual_capacity_arguments\n");
    exit(2);
}

try {
    Database::useProfile('cli');
    $budget = (new AutomationCallBudgetService())->resolve(
        $hasCanonicalMaxCalls ? (int) $options['max-calls'] : null,
        $hasLegacyMaxJobs ? (int) $options['max-jobs'] : null,
    );
    $maxCalls = (int) $budget['max_calls'];
    CronDeadlineContext::start($runtime, max(1, $runtime - 5), 8, 3);
    $result = (new QueueV4CleanScheduler(Database::connectionFresh()))->run($maxCalls, $runtime);
    $result['control_unit'] = 'PHYSICAL_API_CALL';
    $result['max_calls'] = $maxCalls;
    $result['max_calls_source'] = $budget['max_calls_source'];
    $result['configured_max_calls'] = $budget['configured_max_calls'];
    $result['canonical_max_calls_input_used'] = $hasCanonicalMaxCalls;
    $result['legacy_max_jobs_compat_input_used'] = $hasLegacyMaxJobs;
    $result['legacy_max_jobs_normalized_to_calls'] = $hasLegacyMaxJobs && !$hasCanonicalMaxCalls;
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
