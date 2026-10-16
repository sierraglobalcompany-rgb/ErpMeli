<?php

declare(strict_types=1);

/**
 * ERP MELI 2.39.8 — Billing 429 forensic/concurrency contract.
 *
 * This test is intentionally small and delegates the disposable MariaDB
 * exercise to the existing B429-C1 gate. It then adds source-level assertions
 * that protect the current incident semantics:
 *
 * - local/pre-transport rhythm defers are not remote HTTP 429.
 * - physical Billing 429 backoff is durable and keyed by endpoint evidence.
 * - monitor/reporting code must separate policy_delay from reached_remote=1
 *   http_status=429.
 */

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$requiredEnv = ['ERP_MIGRATOR_TEST_DSN', 'ERP_MIGRATOR_TEST_USER', 'ERP_MIGRATOR_TEST_PASS'];
foreach ($requiredEnv as $key) {
    $assert(trim((string) getenv($key)) !== '', 'missing_env_' . $key);
}

$subtest = __DIR__ . '/billing_remote_safety_containment_2393_mysql.php';
$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open([PHP_BINARY, $subtest], $descriptorSpec, $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('b429_c1_process_open_failed');
}
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$errorOutput = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
if ($exitCode !== 0) {
    fwrite(STDERR, (string) $output);
    fwrite(STDERR, (string) $errorOutput);
    throw new RuntimeException('b429_c1_gate_failed_exit_' . $exitCode);
}

$assert(str_contains($output, 'BILLING_REMOTE_SAFETY_CONTAINMENT_2393=PASS'), 'b429_c1_gate_not_passed');
$assert(str_contains($output, 'interval=900'), 'b429_c1_interval_not_certified');
$assert(str_contains($output, 'levels=30/60/120/240'), 'b429_c1_backoff_levels_not_certified');
$assert(str_contains($output, 'physical_max=1'), 'b429_c1_physical_max_not_certified');
$assert(str_contains($output, 'pointer=waiting attempt_penalty=0'), 'b429_c1_attempt_penalty_contract_missing');

$rhythm = (string) file_get_contents($root . '/app/Services/ApiRhythmPolicyService.php');
$client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
$presenter = (string) file_get_contents($root . '/app/Services/ApiLogRiskPresenter.php');
$counter = (string) file_get_contents($root . '/app/Services/ManualCampaignCallCounter.php');
$financial = (string) file_get_contents($root . '/app/Services/SaleFinancialService.php');

$assert(str_contains($rhythm, 'BILLING_MIN_INTERVAL_SECONDS = 900'), 'billing_900_seconds_changed');
$assert(str_contains($rhythm, "WHERE endpoint_key=? AND dispatched_at IS NOT NULL"), 'billing_gate_not_endpoint_dispatch_based');
$assert(str_contains($rhythm, "http_status=429 AND reached_remote=1"), 'billing_backoff_not_limited_to_physical_429');
$assert(str_contains($rhythm, "SELECT * FROM api_rhythm_states WHERE scope_key='global' FOR UPDATE"), 'billing_gate_missing_global_lock');
$assert(str_contains($rhythm, "status IN ('reserved','dispatched')"), 'billing_gate_missing_live_permit_fence');
$assert(str_contains($rhythm, 'endpoint:shared:'), 'billing_endpoint_penalty_not_shared');

$assert(str_contains($client, "'type' => 'api_rhythm_deferred'"), 'client_missing_rhythm_defer_type');
$assert(str_contains($client, "'outcome_class' => 'policy_delay'"), 'client_missing_policy_delay');
$assert(str_contains($client, "'reached_remote' => false"), 'client_local_defer_not_marked_pretransport');
$assert(str_contains($client, 'ApiExecutionMetadataContext::markRemoteBlocked()'), 'client_does_not_mark_remote_blocked');
$assert(str_contains($client, '$rhythm->dispatched($rhythmPermit)'), 'client_dispatch_not_fenced_before_transport');

$assert(str_contains($health, 'SUM(l.reached_remote=1 AND l.http_status=429) http_429'), 'api_health_remote_429_not_authoritative');
$assert(str_contains($health, 'SUM(l.outcome_class="policy_delay") policy_delays'), 'api_health_policy_delay_not_separate');
$assert(str_contains($health, '$isRateLimit = $reachedRemote && $httpStatus === 429'), 'api_health_incident_rate_limit_not_remote_guarded');
$assert(str_contains($health, "Protección preventiva del ERP"), 'api_health_policy_copy_missing');
$assert(str_contains($presenter, 'El ERP aplazó la operación de forma preventiva antes de enviarla.'), 'log_presenter_policy_delay_copy_missing');
$assert(str_contains($counter, 'MAX(outcome_class="policy_delay" AND reached_remote=0) policy_delay'), 'manual_counter_policy_delay_mixed');
$assert(str_contains($counter, 'MAX(http_status=429 AND reached_remote=1) remote_rate_limit'), 'manual_counter_remote_rate_limit_mixed');

$assert(str_contains($financial, 'next_run_at=IF(status IN ("running","complete","review","error","partial"),next_run_at,UTC_TIMESTAMP())'), 'financial_enqueue_wait_preservation_changed');
$assert(!str_contains($financial, 'status IN ("running","complete","review","error","partial","retry","awaiting_remote")'), 'h5r_retry_wait_preservation_already_changed_unexpectedly');

fwrite(
    STDOUT,
    'BILLING_429_CONCURRENCY_MYSQL=PASS'
    . ' checks=' . $checks
    . ' remote_429_requires_reached_remote=1'
    . ' local_rate_limited_is_policy_delay_pretransport'
    . ' billing_interval=900'
    . ' physical_backoff=30/60/120/240'
    . ' gate_scope=GLOBAL_ENDPOINT'
    . ' atomicity=GLOBAL_STATE_FOR_UPDATE_PLUS_LIVE_PERMIT_FENCE'
    . ' attempts_not_penalized_for_local_defer=PASS'
    . ' h5r_wait_preservation_required=YES'
    . ' real_http=0'
    . PHP_EOL
);
