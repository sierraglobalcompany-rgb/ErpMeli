<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require_once __DIR__ . '/../app/Services/AutomationCallBudgetService.php';
require_once __DIR__ . '/../app/Services/AutomationCliCapacityArgumentParser.php';

use App\Services\AutomationCliCapacityArgumentParser;

$parser = new AutomationCliCapacityArgumentParser();

$cases = [
    [[], null],
    [['max-calls' => '3'], 3],
    [['max-calls' => '0'], 1],
    [['max-calls' => '99'], 99],
    [['max-calls' => '101'], 100],
];

foreach ($cases as [$input, $expectedCalls]) {
    $actual = $parser->parse($input);
    k1b_assert($actual['max_calls'] === $expectedCalls, 'parse_max_calls_' . json_encode($input));
    k1b_assert(array_keys($actual) === ['max_calls'], 'parse_only_max_calls_' . json_encode($input));
}

foreach ([['max-calls' => 'abc'], ['max-calls' => ''], ['max-calls' => false], ['max-calls' => true], ['max-calls' => ['1','2']], ['max-calls'=>1.0], ['max-calls'=>'-1'], ['max-calls'=>'1e2']] as $input) {
    try {
        $parser->parse($input);
        k1b_assert(false, 'invalid_capacity_argument_' . json_encode($input));
    } catch (InvalidArgumentException $error) {
        k1b_assert($error->getMessage() === 'invalid_capacity_argument', 'invalid_capacity_argument_' . json_encode($input));
    }
}

try {
    $parser->parse(['max-jobs' => '2']);
    k1b_assert(false, 'legacy_capacity_argument_removed');
} catch (InvalidArgumentException $error) {
    k1b_assert($error->getMessage() === 'legacy_capacity_argument_removed', 'legacy_capacity_argument_removed');
}

$cron = file_get_contents(__DIR__ . '/../app/Services/CronHealthService.php');
$shell = file_get_contents(__DIR__ . '/../app/Views/settings/cron_shell.php');
$calibration = file_get_contents(__DIR__ . '/../app/Views/settings/api_workload.php');
$apiHealth = file_get_contents(__DIR__ . '/../app/Views/settings/api_health.php');
$settingsDefinitions = file_get_contents(__DIR__ . '/../app/Repositories/SettingsDefinitionRepository.php');
$worker = file_get_contents(__DIR__ . '/../app/QueueV4Clean/QueueV4CleanWorker.php');
$email = file_get_contents(__DIR__ . '/../app/Services/CriticalApiAlertEmailService.php');
$client = file_get_contents(__DIR__ . '/../app/Services/MeliApiClient.php');
$migration = file_get_contents(__DIR__ . '/../database/migrations/301_k1d_api_safety_2_40_1.sql');
$index = file_get_contents(__DIR__ . '/../public/index.php');

k1b_assert(!str_contains($cron, '--runtime=45 --max-calls='), 'PRIMARY_RECOMMENDED_COMMAND_CONTAINS_MAX_CALLS_NO');
k1b_assert(!str_contains($cron, '--runtime=45 --max-jobs='), 'PRIMARY_RECOMMENDED_COMMAND_CONTAINS_MAX_JOBS_NO');
k1b_assert(str_contains($calibration, 'Overrides técnicos') && str_contains($calibration, '--max-calls=N') && !str_contains($calibration, '--max-jobs=N'), 'ADVANCED_OVERRIDE_DOCUMENTED');
k1b_assert(substr_count($calibration, '<form') === substr_count($calibration, '</form>'), 'FORM_TAGS_BALANCED');
k1b_assert(!str_contains($calibration, 'critical-email-test-form') && !str_contains($calibration, '/settings/api-health/email-test'), 'EMAIL_TEST_NOT_IN_CALIBRATION');
k1b_assert(str_contains($apiHealth, '/settings/api-health/email-settings') && str_contains($apiHealth, '/settings/api-health/email-test'), 'EMAIL_SETTINGS_AND_TEST_IN_API_HEALTH');
k1b_assert(str_contains($settingsDefinitions, 'managed_elsewhere') && str_contains($settingsDefinitions, '/settings/cron/rhythm') && str_contains($settingsDefinitions, '/settings/api-health'), 'GENERIC_SETTINGS_LINK_MANAGED_CONTROLS');
k1b_assert(str_contains($index, '/settings/cron/call-budget'), 'CALL_BUDGET_DEDICATED_SAVE');
$runtimeCatchOffset = strpos($worker, 'catch (RuntimeException $error)');
$runtimeCatch = $runtimeCatchOffset === false ? '' : substr($worker, $runtimeCatchOffset, 280);
k1b_assert($runtimeCatch !== '' && !str_contains($runtimeCatch, '$outcome'), 'NO_RUNTIME_CATCH_OUTCOME_DEPENDENCY');
k1b_assert(substr_count($worker, '$endReason = \'remote_429_global_pause\';') >= 3, 'FIRST_429_STOPS_WAITING_REVIEW_EXCEPTION_PATHS');
k1b_assert(str_contains($email, 'claimSendLease') && str_contains($email, 'status="sending"') && str_contains($email, 'rowCount() === 1'), 'EMAIL_ATOMIC_CLAIM');
k1b_assert(str_contains($email, 'public function sendTest') && !str_contains(substr($email, strpos($email, 'public function sendTest'), 500), 'alerts.email.enabled'), 'EMAIL_TEST_INDEPENDENT_OF_ENABLED');
k1b_assert(str_contains($email, 'humanContextLabels') && str_contains($email, 'Content-Type: text/plain; charset=UTF-8') && str_contains($email, 'preg_replace'), 'EMAIL_HUMAN_LABELS_AND_SAFE_UTF8_HEADERS');
k1b_assert(str_contains($client, "\$meta['source']") && str_contains($client, "\$meta['job_type']") && str_contains($client, "\$meta['source_work_id']"), 'EMAIL_CONTEXT_CANONICAL_META');
k1b_assert(!str_contains($migration, 'notify_scheduler_fatal') && !str_contains($migration, 'notify_recovery'), 'UNIMPLEMENTED_EMAIL_SETTINGS_NOT_MIGRATED');

echo "STATUS=PASS K1D_FIX1_PR11_GATES\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";
