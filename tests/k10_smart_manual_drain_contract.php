<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$version = trim((string) file_get_contents($root . '/VERSION'));
$migration = (string) file_get_contents($root . '/database/migrations/300_manual_drain_sessions_2_40_0.sql');
$service = (string) file_get_contents($root . '/app/Services/ManualDrainSessionService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$view = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
$worker = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanWorker.php');
$leaseService = (string) file_get_contents($root . '/app/QueueCore/QueueExecutionLeaseService.php');

$assert(in_array($version, ['2.40.0', '2.40.1', '2.40.2'], true), 'version_not_2400_or_2401_or_2402');
$assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS manual_drain_sessions'), 'manual_drain_sessions_missing');
$assert(str_contains($migration, 'session_token CHAR(64) NOT NULL'), 'session_token_missing');
$assert(str_contains($migration, 'UNIQUE KEY uq_manual_drain_session_token'), 'session_token_unique_missing');
$assert(str_contains($migration, 'status_ui VARCHAR(30) NOT NULL'), 'metadata_state_missing');
$assert(str_contains($migration, 'burst_remote_calls_current INT UNSIGNED NOT NULL DEFAULT 0'), 'burst_accumulator_column_missing');
$assert(!str_contains($migration, 'queue_core_jobs'), 'k10_migration_must_not_create_queue_core_dependency');

$assert(str_contains($service, 'QueueExecutionLeaseService'), 'global_execution_lease_not_used');
$assert(str_contains($service, "private const LAUNCHER = 'manual'"), 'launcher_must_use_existing_enum_manual');
$assert(str_contains($service, 'QueueV4CleanWorker'), 'canonical_worker_not_used');
$assert(str_contains($service, "->run('manual', 1, 25)"), 'manual_step_must_claim_one_v4_job');
$assert(!str_contains($service, 'QueueCoreRepository'), 'queue_core_fifo_must_not_be_used');
$assert(!str_contains($service, 'new MeliApiClient'), 'manual_drain_must_not_call_meli_directly');
$assert(str_contains($service, 'GET_LOCK'), 'concurrent_step_lock_missing');
$assert(str_contains($service, 'STEP_ALREADY_RUNNING'), 'concurrent_step_error_missing');
$lockBody = substr($service, (int) strpos($service, 'private function getAdvisoryLock'), 500);
$assert(str_contains($lockBody, 'return false;'), 'advisory_lock_error_must_fail_closed');
$assert(str_contains($service, 'DRAIN_AUTHORITY_BUSY'), 'busy_authority_contract_missing');
$statusBody = substr($service, (int) strpos($service, 'public function status'), 700);
$assert(!str_contains($statusBody, 'expireStaleSessions'), 'status_read_must_not_mutate_expired_sessions');

foreach ([
    '/settings/manual-processing/drain/start' => 'manualDrainStart',
    '/settings/manual-processing/drain/step' => 'manualDrainStep',
    '/settings/manual-processing/drain/heartbeat' => 'manualDrainHeartbeat',
    '/settings/manual-processing/drain/pause' => 'manualDrainPause',
    '/settings/manual-processing/drain/resume' => 'manualDrainResume',
    '/settings/manual-processing/drain/stop' => 'manualDrainStop',
    '/settings/manual-processing/drain/profile' => 'manualDrainProfile',
    '/settings/manual-processing/drain/status.json' => 'manualDrainStatus',
] as $path => $method) {
    $assert(str_contains($routes, $path), 'route_missing:' . $path);
    $assert(str_contains($controller, 'public function ' . $method . '('), 'controller_method_missing:' . $method);
}

$mutationBody = substr($controller, (int) strpos($controller, 'private function manualDrainJsonMutation'), 3500);
$globalAuthBody = substr($controller, (int) strpos($controller, 'private function requireManualDrainGlobalAuthority'), 1200);
$assert(str_contains($mutationBody, 'requireManualDrainGlobalAuthority()'), 'manual_drain_requires_global_guard');
$assert(str_contains($globalAuthBody, 'requireAdminPermanent()'), 'manual_drain_requires_permanent_admin');
$assert(str_contains($globalAuthBody, 'ApiHealthAccessScope'), 'manual_drain_scope_service_missing');
$assert(str_contains($globalAuthBody, "['application']") || str_contains($globalAuthBody, "['application'"), 'manual_drain_requires_application_scope');
$assert(substr_count($mutationBody, 'Csrf::validate') >= 1, 'manual_drain_requires_csrf');
$assert(substr_count($mutationBody, 'assertSameOrigin') >= 1, 'manual_drain_requires_same_origin');
$assert(str_contains($mutationBody, "'profile' => \$service->profile"), 'manual_drain_profile_endpoint_missing');
$assert(str_contains($service, 'profile_revision=profile_revision+1'), 'manual_profile_live_revision_missing');
$assert(str_contains($service, "'remote_calls_per_burst' => max(1, min(60"), 'manual_burst_must_not_be_forced_to_one');
$assert(str_contains($service, 'manual_physical_http_burst_limit'), 'manual_burst_limit_not_bound_to_api_context');
$assert(str_contains($service, 'burst_remote_calls_current') && str_contains($service, 'manual_burst_remaining'), 'manual_burst_accumulator_missing');
$assert(str_contains($service, 'physical_http_calls'), 'manual_remote_counter_must_use_physical_http');
$assert(!str_contains($service, '$remoteBefore = $this->transportCount()'), 'manual_remote_counter_must_not_use_global_before_after');
$assert(str_contains($service, 'projectStepState'), 'manual_error_projection_missing');
$assert(!str_contains($service, "str_contains(\$classification, 'rate_limit_deferred')"), 'manual_local_defer_must_not_be_rate_limited');
$assert(str_contains($service, "'pause_and_ask'") && str_contains($service, "'auto_degrade'"), 'manual_429_action_values_missing');
$assert(str_contains($service, 'degradedProfile') && str_contains($service, "'auto_degraded' => \$autoDegraded"), 'manual_auto_degrade_must_have_effect');
$assert(str_contains($service, "? 'COOLING'") && str_contains($service, "? 'RATE_LIMITED'"), 'manual_auto_degrade_must_not_stay_rate_limited');
$assert(str_contains($service, 'PROFILE_GENERATION_MISMATCH'), 'manual_generation_fence_missing');
$assert(str_contains($controller, 'PROFILE_GENERATION_REQUIRED'), 'manual_generation_required_for_mutations_missing');
$assert(str_contains($worker, 'receiptForRun'), 'worker_receipt_for_run_missing');
$assert(str_contains($worker, 'ManualRemoteCallLimitException') && str_contains($worker, 'capacity_deferred:manual_burst'), 'manual_burst_limit_must_not_be_review');
$assert(str_contains($worker, 'queue_v4_clean_transport_events') && str_contains($worker, 'physicalHttpCallsForRun'), 'worker_physical_http_attribution_missing');

$assert(str_contains($view, 'Procesamiento manual seguro'), 'manual_drain_operator_title_missing');
$assert(str_contains($view, 'MANUAL_EXACT'), 'manual_drain_operator_classification_missing');
$assert(str_contains($view, 'Detalle técnico avanzado') && str_contains($view, 'K10') && str_contains($view, 'Queue V4 boundary'), 'manual_drain_technical_detail_missing');
$assert(str_contains($view, '/settings/manual-processing/drain/start'), 'manual_drain_start_form_missing');
$assert(str_contains($view, '/settings/manual-processing/drain/step'), 'manual_drain_step_form_missing');
$assert(str_contains($view, '/settings/manual-processing/drain/profile'), 'manual_drain_profile_form_missing');
$assert(str_contains($view, '/settings/manual-processing/drain/pause') && str_contains($view, '/settings/manual-processing/drain/resume'), 'manual_drain_pause_resume_ui_missing');
$assert(str_contains($view, 'data-k10-burst-current') && str_contains($view, 'burst_remote_calls_current') && str_contains($view, 'data-k10-next-safe'), 'manual_drain_burst_429_panel_missing');
$assert(str_contains($view, 'let inFlight = false') && str_contains($view, 'scheduleStep'), 'manual_drain_serial_browser_loop_missing');
$assert(str_contains($view, 'runOneStepSoon()') && str_contains($view, 'Sin trabajo elegible'), 'manual_drain_no_work_loop_must_stop');
$assert(str_contains($view, 'data-k10-feedback aria-live="polite"'), 'manual_drain_live_feedback_missing');
$assert(str_contains($view, 'data-k10-action="heartbeat"'), 'manual_drain_hidden_heartbeat_form_missing');
$assert(str_contains($view, 'if (!token || inFlight) return;'), 'manual_drain_heartbeat_must_not_overlap_step');
$assert(str_contains($view, "action === 'pause'") && str_contains($view, "state !== 'PAUSED'"), 'manual_drain_pause_resume_visibility_must_follow_state');
$assert(str_contains($view, '/settings/manual-processing/drain/status.json'), 'manual_drain_status_refresh_missing');
$assert(str_contains($view, "url.searchParams.set('drain_generation'"), 'manual_drain_status_refresh_must_send_generation');
$assert(str_contains($view, '/settings/manual-processing/drain/heartbeat'), 'manual_drain_heartbeat_fetch_missing');
$assert(str_contains($view, 'window.setInterval(heartbeat, 15000)'), 'manual_drain_15s_heartbeat_missing');

$assert(str_contains($worker, "'run_id' => \$runId"), 'worker_run_receipt_missing_run_id');
$assert(str_contains($worker, "'run_id' => 0"), 'worker_noop_receipt_missing_run_id');
$assert(str_contains($leaseService, 'isTransientAcquireRace'), 'lease_acquire_transient_race_guard_missing');
$assert(str_contains($leaseService, 'return null;'), 'lease_acquire_transient_race_must_return_busy');

fwrite(STDOUT, "K10_SMART_MANUAL_DRAIN_CONTRACT=PASS\n");
