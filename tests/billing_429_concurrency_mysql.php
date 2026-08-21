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
$repository = $read('app/QueueV4Clean/QueueV4CleanRepository.php');
$worker = $read('app/QueueV4Clean/QueueV4CleanWorker.php');
$client = $read('app/Services/MeliApiClient.php');
$controller = $read('app/Controllers/SettingsController.php');
$workloadView = $read('app/Views/settings/api_workload.php');
$settingsDefinitions = $read('app/Repositories/SettingsDefinitionRepository.php');

$assert(str_contains($policy, 'BILLING_MIN_INTERVAL_SECONDS = 900'), 'billing_interval_900_missing');
$assert(str_contains($policy, 'BILLING_429_ESCALATION_WINDOW_HOURS = 72'), 'billing_escalation_window_72h_missing');
$assert(str_contains($policy, "api.rhythm.billing_429_backoff_1_minutes', 30"), 'first_429_30m_config_missing');
$assert(str_contains($policy, "api.rhythm.billing_429_backoff_2_minutes', 120"), 'second_429_2h_config_missing');
$assert(str_contains($policy, "api.rhythm.billing_429_backoff_3_minutes', 360"), 'third_429_6h_config_missing');
$assert(str_contains($policy, "api.rhythm.billing_429_backoff_max_minutes', 720"), 'fourth_429_12h_config_missing');
$assert(str_contains($policy, 'max($policySeconds, $retryAfter)'), 'retry_after_not_authoritative');
$assert(str_contains($policy, 'min($persistedBlock, $rateNext)'), 'old_persisted_block_can_override_new_policy');

foreach ([
    'billing_429_backoff_1_minutes',
    'billing_429_backoff_2_minutes',
    'billing_429_backoff_3_minutes',
    'billing_429_backoff_max_minutes',
] as $key) {
    $assert(str_contains($controller, "api.rhythm.{$key}"), 'settings_save_missing_' . $key);
    $assert(str_contains($workloadView, 'name="' . $key . '"'), 'settings_ui_missing_' . $key);
    $assert(str_contains($settingsDefinitions, "api.rhythm.{$key}"), 'settings_definition_missing_' . $key);
}
$assert(str_contains($workloadView, 'No reduce Retry-After enviado por Mercado Libre'), 'settings_ui_retry_after_notice_missing');
$assert(str_contains($workloadView, 'Sólo aplica a Billing 429 remoto real'), 'settings_ui_remote_only_notice_missing');
$assert(str_contains($controller, 'max($first') && str_contains($controller, 'max($second') && str_contains($controller, 'max($third'), 'settings_save_normalization_missing');

$assert(str_contains($repository, 'parkFinancialReconciliationUntil'), 'finance_parking_method_missing');
$parkingStart = strpos($repository, 'public function parkFinancialReconciliationUntil');
$parkingEnd = strpos($repository, 'public function review', $parkingStart === false ? 0 : $parkingStart);
$parkingBody = $parkingStart !== false && $parkingEnd !== false ? substr($repository, $parkingStart, $parkingEnd - $parkingStart) : '';
$assert($parkingBody !== '', 'finance_parking_body_missing');
$assert(str_contains($parkingBody, "state='waiting'"), 'finance_parking_does_not_wait');
$assert(str_contains($parkingBody, "state IN ('ready','waiting')"), 'finance_parking_touches_terminal_states');
$assert(str_contains($parkingBody, "JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='financial_reconciliation'"), 'finance_parking_not_capability_scoped');
$assert(!preg_match('/attempt_count\s*=/', $parkingBody), 'finance_parking_mutates_attempt_count');
$assert(!preg_match('/lease_generation\s*=/', $parkingBody), 'finance_parking_mutates_lease_generation');

$assert(str_contains($worker, 'shouldParkFinancialReconciliation'), 'worker_parking_gate_missing');
$assert(str_contains($worker, "'billing_endpoint_interval'") && str_contains($worker, "'billing_429_backoff'"), 'worker_scope_gate_missing');
$assert(str_contains($worker, "(string) (\$payload['capability'] ?? '')") && str_contains($worker, "'financial_reconciliation'"), 'worker_capability_gate_missing');
$assert(str_contains($worker, "'domain_exact'"), 'worker_job_type_gate_missing');

$assert(str_contains($client, 'dispatch') || str_contains($client, 'rateLimitNextSafeAt'), 'client_transport_authority_unexpected');

$command = [PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . 'billing_429_emergency_hotfix_mysql.php'];
$descriptor = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($command, $descriptor, $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('unable_to_spawn_emergency_hotfix_test');
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);

$assert($exit === 0, 'emergency_hotfix_test_failed:' . trim((string) $stderr));
$assert(str_contains((string) $stdout, 'AT_MOST_ONE_PROBE_AFTER_BREAKER=PASS'), 'one_probe_regression_missing');
$assert(str_contains((string) $stdout, 'SECOND_PROBE_PRETRANSPORT_BLOCKED=PASS'), 'second_probe_pretransport_regression_missing');
$assert(str_contains((string) $stdout, 'FINANCE_GLOBAL_PARKING=PASS'), 'finance_parking_regression_missing');
$assert(str_contains((string) $stdout, 'ORDER_EXACT_CAN_PROGRESS_DURING_BILLING_BLOCK=PASS'), 'non_billing_progress_regression_missing');
$assert(str_contains((string) $stdout, 'REAL_HTTP=0'), 'real_http_not_zero');

fwrite(STDOUT, 'BILLING_429_CONCURRENCY_MYSQL=PASS checks=' . $checks
    . ' levels=30m/2h/6h/12h interval=900 finance_parking=waiting real_http=0 settings_ui=pass' . PHP_EOL);
