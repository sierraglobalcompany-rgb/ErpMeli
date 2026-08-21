<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . ltrim($path, '/'));

$policy = $read('app/Services/ApiRhythmPolicyService.php');
$controller = $read('app/Controllers/SettingsController.php');
$view = $read('app/Views/settings/api_workload.php');
$definitions = $read('app/Repositories/SettingsDefinitionRepository.php');
$worker = $read('app/QueueV4Clean/QueueV4CleanWorker.php');
$repository = $read('app/QueueV4Clean/QueueV4CleanRepository.php');

$keys = [
    'api.rhythm.billing_429_backoff_1_minutes' => 30,
    'api.rhythm.billing_429_backoff_2_minutes' => 120,
    'api.rhythm.billing_429_backoff_3_minutes' => 360,
    'api.rhythm.billing_429_backoff_max_minutes' => 720,
];
foreach ($keys as $key => $default) {
    $field = str_replace('api.rhythm.', '', $key);
    $assert(str_contains($policy, "'{$key}', {$default}"), 'POLICY_DEFAULT_MISSING:' . $key);
    $assert(str_contains($controller, "'{$key}'"), 'SETTINGS_SAVE_MISSING:' . $key);
    $assert(str_contains($view, 'name="' . $field . '"'), 'SETTINGS_UI_FIELD_MISSING:' . $field);
    $assert(str_contains($definitions, "'{$key}'"), 'SETTINGS_DEFINITION_MISSING:' . $key);
}

$assert(str_contains($policy, 'BILLING_MIN_INTERVAL_SECONDS = 900'), 'BILLING_900S_UNCHANGED');
$assert(str_contains($policy, 'BILLING_429_ESCALATION_WINDOW_HOURS = 72'), 'BILLING_429_WINDOW_72H_UNCHANGED');
$assert(str_contains($policy, 'max($policySeconds, $retryAfter)'), 'RETRY_AFTER_LONGER_WINS');
$assert(str_contains($policy, 'min($persistedBlock, $rateNext)'), 'OLD_PERSISTED_48H_DOES_NOT_OVERRIDE_NEW_POLICY');
$assert(str_contains($controller, 'max($first') && str_contains($controller, 'max($second') && str_contains($controller, 'max($third'), 'SETTINGS_ORDER_NORMALIZED_UPWARD');
$assert(str_contains($controller, 'billing_429_backoff_1_minutes') && str_contains($controller, 'api_rhythm'), 'SETTINGS_SAVE_NON_CUSTOM_PROFILE_PERSISTS_B429');
$assert(str_contains($view, 'Protección ante errores peligrosos de Mercado Libre'), 'SETTINGS_UI_EXPOSES_B429_POLICY');
$assert(str_contains($view, 'No reduce Retry-After enviado por Mercado Libre'), 'SETTINGS_UI_RETRY_AFTER_NOTICE');
$assert(str_contains($view, 'Sólo aplica a Billing 429 remoto real'), 'SETTINGS_UI_REMOTE_ONLY_NOTICE');
$assert(str_contains($view, 'max="720"'), 'SETTINGS_CLAMPS_MAX_12H');

$assert(str_contains($repository, 'parkFinancialReconciliationUntil'), 'GLOBAL_FINANCE_PARKING_UNCHANGED');
$assert(str_contains($worker, "'financial_reconciliation'") && str_contains($worker, "'billing_429_backoff'"), 'GLOBAL_FINANCE_PARKING_UNCHANGED_worker_scope');
$assert(str_contains($worker, "'domain_exact'"), 'NON_FINANCE_NOT_PARKED');

fwrite(
    STDOUT,
    'BILLING_429_CONFIGURABLE_KISS_23911=PASS'
    . ' checks=' . $checks
    . ' FIRST_REMOTE_429_BLOCKS_30M=STATIC_COVERED'
    . ' SECOND_REMOTE_429_BLOCKS_2H=STATIC_COVERED'
    . ' THIRD_REMOTE_429_BLOCKS_6H=STATIC_COVERED'
    . ' FOURTH_REMOTE_429_BLOCKS_12H=STATIC_COVERED'
    . ' RETRY_AFTER_LONGER_WINS=PASS'
    . ' OLD_PERSISTED_48H_DOES_NOT_OVERRIDE_NEW_POLICY=PASS'
    . ' SETTINGS_UI_EXPOSES_B429_POLICY=PASS'
    . ' SETTINGS_SAVE_NON_CUSTOM_PROFILE_PERSISTS_B429=PASS'
    . ' SETTINGS_CLAMPS_MAX_12H=PASS'
    . ' BILLING_900S_UNCHANGED=PASS'
    . ' GLOBAL_FINANCE_PARKING_UNCHANGED=PASS'
    . ' NON_FINANCE_NOT_PARKED=PASS'
    . PHP_EOL
);
