<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$methodBody = static function (string $source, string $method, int $length = 7000): string {
    $at = strpos($source, 'public function ' . $method);
    return $at === false ? '' : substr($source, $at, $length);
};

$controller = $read('app/Controllers/SettingsController.php');
$manualStep = $read('app/Services/ManualSingleStepService.php');
$manualLauncher = $read('app/QueueCore/ManualQueueLauncher.php');
$runner = $read('app/QueueCore/QueueRunner.php');
$view = $read('app/Views/settings/manual_processing.php');
$routes = $read('public/index.php');

$startBody = $methodBody($controller, 'manualProcessingStart');
$retiredBody = $methodBody($controller, 'manualProcessingRetired', 2000);

$assert(str_contains($startBody, 'ManualSingleStepService())->executeMany'), 'manual_start_not_using_exact_selection_service');
$assert(str_contains($startBody, 'process_limit'), 'manual_start_missing_amount_limit');
$assert(!str_contains($startBody, 'ManualCampaignService())->start'), 'manual_start_creates_persistent_campaign');

$assert(str_contains($manualStep, 'SELECT GET_LOCK(?,3)'), 'manual_step_missing_double_click_lock');
$assert(str_contains($manualStep, 'ManualSourceAuthorityService'), 'manual_step_does_not_re_read_source_authority');
$assert(str_contains($manualStep, 'ManualInputVersion::deriveFromSourceAuthority'), 'manual_step_missing_durable_input_version');
$assert(str_contains($manualStep, 'runExactBatch($items)'), 'manual_step_not_handing_off_to_batch_launcher');
$assert(!str_contains($manualStep, 'processExact('), 'manual_step_duplicates_business_handler');

$assert(str_contains($manualLauncher, 'QueueCoreFactory::build()'), 'manual_launcher_not_using_queue_core_factory');
$assert(substr_count($manualLauncher, "->acquire('manual'") === 1, 'manual_launcher_must_acquire_one_global_execution_lease');
$assert(str_contains($manualLauncher, 'recoverAbandonedManualJobs()'), 'manual_launcher_missing_abandoned_manual_recovery');
$assert(str_contains($manualLauncher, "queue_domain='manual'"), 'manual_launcher_missing_manual_domain_busy_check');
$assert(str_contains($manualLauncher, 'new QueueRunRequest('), 'manual_launcher_not_using_queue_runner_request');
$assert(str_contains($manualLauncher, "'manual_exact'"), 'manual_launcher_not_restricted_to_manual_exact');
$assert(str_contains($manualLauncher, "'manual',\$jobId"), 'manual_launcher_missing_target_job_fence');
$assert(str_contains($manualLauncher, 'abandonManualJob'), 'manual_launcher_missing_no_background_closeout');
$assert(!str_contains($manualLauncher, 'new MeliApiClient'), 'manual_launcher_must_not_own_transport');

$assert(str_contains($runner, 'ApiExecutionMetadataContext::run'), 'queue_runner_missing_shared_execution_metadata');
$assert(str_contains($runner, 'claimNext($request'), 'queue_runner_missing_shared_claim_path');
$assert(str_contains($runner, 'beginAttempt($claim'), 'queue_runner_missing_shared_attempt_path');
$assert(str_contains($runner, 'finish($claim'), 'queue_runner_missing_shared_finish_path');

$assert(str_contains($retiredBody, 'RETIRED_BLOCKED'), 'legacy_manual_routes_not_explicitly_retired');
$assert(str_contains($routes, '/settings/manual-processing/drain/start') && str_contains($routes, "'manualProcessingRetired'"), 'manual_drain_routes_not_retired');
$assert(!str_contains($view, 'k10-manual-drain'), 'manual_view_still_exposes_k10_drain');
$assert(!str_contains($view, 'data-k10'), 'manual_view_still_exposes_k10_javascript');
$assert(str_contains($view, '¿Cuántos procesar?'), 'manual_view_missing_amount_question');
$assert(str_contains($view, 'Qué se va a procesar'), 'manual_view_missing_preview_question');
$assert(str_contains($view, 'Procesar selección exacta'), 'manual_view_missing_exact_process_button');
$assert(str_contains($view, 'BACKGROUND_CONTINUATION=0'), 'manual_view_missing_no_background_copy');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "K8_OPERABILITY_MANUAL_CONTRACT=PASS\n";
echo "CHECKS={$checks}\n";
