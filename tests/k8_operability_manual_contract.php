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
$run = static function (array $command) use ($root): array {
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('process_unavailable');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

$controller = $read('app/Controllers/SettingsController.php');
$manualStep = $read('app/Services/ManualSingleStepService.php');
$manualLauncher = $read('app/QueueCore/ManualQueueLauncher.php');
$manualExecution = $read('app/Services/ManualQueueExecutionService.php');
$runner = $read('app/QueueCore/QueueRunner.php');
$view = $read('app/Views/settings/manual_processing.php');
$setupView = $read('app/Views/settings/manual_processing_setup.php');
$sessionView = $read('app/Views/settings/manual_processing_session.php');
$cronView = $read('app/Views/settings/cron.php');
$legacy = $read('jobs/process_sync_queue.php');
$inventory = $read('app/Services/RuntimeProcessInventoryService.php');
$health = $read('app/Services/CronHealthService.php');

$startBody = $methodBody($controller, 'manualProcessingStart');
$interactiveBody = $methodBody($controller, 'manualProcessingInteractiveStep');
$testCronBody = $methodBody($controller, 'testCron', 9000);

$assert(str_contains($startBody, 'ManualSingleStepService())->execute'), 'manual_start_not_using_single_step_service');
$assert(str_contains($interactiveBody, 'ManualSingleStepService())->execute'), 'manual_interactive_not_using_single_step_service');
$assert(!str_contains($startBody, 'ManualCampaignService())->start'), 'manual_start_creates_persistent_campaign');
$assert(!str_contains($interactiveBody, 'ManualCampaignService())->start'), 'manual_interactive_creates_persistent_campaign');

$assert(str_contains($manualStep, 'SELECT GET_LOCK(?,3)'), 'manual_step_missing_double_click_lock');
$assert(str_contains($manualStep, 'ManualSourceAuthorityService'), 'manual_step_does_not_re_read_source_authority');
$assert(str_contains($manualStep, 'ManualInputVersion::deriveFromSourceAuthority'), 'manual_step_missing_durable_input_version');
$assert(str_contains($manualStep, '(new ManualQueueLauncher())->runExact('), 'manual_step_not_handing_off_to_queue_launcher');
$assert(!str_contains($manualStep, 'processExact('), 'manual_step_duplicates_business_handler');

$assert(str_contains($manualLauncher, 'QueueCoreFactory::build()'), 'manual_launcher_not_using_queue_core_factory');
$assert(str_contains($manualLauncher, "acquire('manual',"), 'manual_launcher_missing_global_execution_lease');
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

$assert(str_contains($manualExecution, 'Compatibilidad cerrada'), 'legacy_manual_execution_service_not_closed');
$assert(str_contains($manualExecution, "'processed' => 0"), 'legacy_manual_execution_service_should_process_zero');
$assert(str_contains($manualExecution, "'reached_remote' => false"), 'legacy_manual_execution_service_should_not_reach_remote');

$assert(str_contains($testCronBody, 'queue_v4_read_only'), 'cron_test_not_v4_read_only_preflight');
$assert(str_contains($testCronBody, "'processed' => 0"), 'cron_test_must_not_process_work');
$assert(str_contains($testCronBody, '/jobs/queue_v4_clean.php'), 'cron_test_missing_v4_launcher_file_check');
$assert(!str_contains($testCronBody, "begin('process_sync_queue'"), 'cron_test_writes_legacy_health');
$assert(str_contains($health, 'queue_v4_clean.php --runtime=45 --max-jobs=3'), 'cron_health_missing_single_v4_command');

$assert(str_contains($inventory, "if (\$name === 'queue_v4_clean.php')"), 'runtime_inventory_missing_v4_active_branch');
$assert(str_contains($inventory, "'active_launcher_count'"), 'runtime_inventory_missing_active_launcher_count');
$assert(str_contains($inventory, "'process_sync_queue.php', 'cron_v3_local.php', 'cron_v3_remote.php', 'cron_v4.php'"), 'runtime_inventory_missing_legacy_retired_set');
$assert(str_contains($inventory, "'retired_blocked'"), 'runtime_inventory_missing_retired_blocked_status');
$assert(!str_contains($inventory, "process_sync_queue.php') {\n                \$status = 'active_launcher'"), 'runtime_inventory_marks_process_sync_active');

$assert(str_contains($view, 'MANUAL_EXACT'), 'manual_view_missing_manual_exact_badge');
$assert(str_contains($view, 'Un paso idempotente por confirmación'), 'manual_view_missing_one_step_copy');
$assert(str_contains($view, 'no inicia ni continúa Cron en segundo plano'), 'manual_view_missing_no_background_copy');
$assert(str_contains($view, 'Esta confirmacion procesara solamente el primero'), 'manual_confirm_must_be_single_work');
$assert(str_contains($view, 'no deja una campana en segundo plano'), 'manual_browser_note_missing_no_background');
$assert(str_contains($setupView, 'sin dejar un proceso en segundo plano'), 'manual_setup_missing_no_background_contract');
$assert(str_contains($sessionView, 'Sin ejecución manual en segundo plano'), 'manual_session_missing_no_background_contract');
$assert(!str_contains($view, 'La campaña guarda avance cada'), 'manual_view_still_promises_campaign_progress');
$assert(!str_contains($controller, 'La campaña avanza con el lanzador normal de Hostinger'), 'controller_still_promises_hostinger_campaign');

$normal = $run([PHP_BINARY, $root . '/jobs/process_sync_queue.php']);
$assert($normal['exit'] === 0 && str_contains($normal['stdout'], 'LEGACY_AUTOMATION_RETIRED'), 'process_sync_queue_normal_not_retired');
foreach (['--record=1', '--persist=1', '--execute', '--set=1', '--rollback', '--manual'] as $flag) {
    $blocked = $run([PHP_BINARY, $root . '/jobs/process_sync_queue.php', $flag]);
    $assert($blocked['exit'] === 2 && str_contains($blocked['stderr'], 'LEGACY_AUTOMATION_BLOCKED'), 'legacy_mutating_flag_not_blocked:' . $flag);
}

$assert(str_contains($cronView, '--runtime=45 --max-jobs=3'), 'cron_view_missing_v4_launcher');
$assert(!str_contains($cronView, 'php jobs/process_sync_queue.php'), 'cron_view_exposes_legacy_launcher_command');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'K8_OPERABILITY_MANUAL_CONTRACT=PASS checks=' . $checks . ' real_meli_http=0 production_touched=no' . PHP_EOL;
