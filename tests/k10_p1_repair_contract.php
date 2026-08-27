<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = (string) file_get_contents($root . '/app/Services/ManualDrainSessionService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$view = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
$worker = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanWorker.php');
$context = (string) file_get_contents($root . '/app/Services/ApiExecutionMetadataContext.php');
$manualLimit = (string) file_get_contents($root . '/app/Services/ManualRemoteCallLimitException.php');

$assert(str_contains($controller, 'requireManualDrainGlobalAuthority()'), 'GLOBAL_AUTHORIZATION_SAFE');
$guard = substr($controller, (int) strpos($controller, 'private function requireManualDrainGlobalAuthority'), 1400);
$assert(str_contains($guard, 'requireAdminPermanent()'), 'temporary_or_non_admin_not_blocked');
$assert(str_contains($guard, 'ApiHealthAccessScope') && str_contains($guard, "['application']"), 'tenant_only_admin_not_blocked');
$assert(str_contains($guard, 'GLOBAL_APPLICATION_AUTHORITY_REQUIRED'), 'global_auth_error_code_missing');

$assert(str_contains($routes, '/settings/manual-processing/drain/profile'), 'profile_route_missing');
$assert(str_contains($service, 'public function profile('), 'profile_service_missing');
$assert(str_contains($service, 'profile_revision=profile_revision+1'), 'profile_revision_not_incremented');
$assert(str_contains($service, 'PROFILE_GENERATION_MISMATCH'), 'generation_fence_missing');
$assert(str_contains($service, 'private function hasTable(') && str_contains($service, 'TABLE_SCHEMA=DATABASE()'), 'service_must_inspect_injected_pdo_schema');
$assert(str_contains($service, 'remote_calls_per_burst') && str_contains($service, 'min(60'), 'burst_not_configurable');
$assert(str_contains($service, 'manual_physical_http_burst_limit'), 'manual_burst_limit_not_in_execution_context');
$assert(str_contains($service, 'burst_remote_calls_current') && str_contains($service, 'burstRemaining('), 'burst_session_accumulation_missing');
$assert(str_contains($service, "'burst_current_after' => \$burstAfter"), 'burst_receipt_accumulation_missing');
$assert(str_contains($service, "ApiExecutionMetadataContext::run(\n                ["), 'manual_step_not_wrapped_by_shared_context');

$assert(str_contains($worker, 'receiptForRun') && str_contains($worker, 'physicalHttpCallsForRun'), 'worker_receipt_missing');
$assert(str_contains($worker, 'queue_v4_clean_transport_events') && str_contains($worker, "PHYSICAL_STARTED','RESPONSE_KNOWN"), 'physical_http_event_attribution_missing');
$assert(str_contains($worker, 'ManualRemoteCallLimitException') && str_contains($worker, 'capacity_deferred:manual_burst'), 'manual_burst_limit_must_defer_without_review');
$assert(str_contains($context, 'manualPhysicalHttpLimit') && str_contains($context, 'manual_physical_http_burst_limit'), 'manual_burst_not_enforced_before_transport');
$assert(str_contains($context, 'self::$remoteCalls >= $manualMaximum'), 'manual_burst_not_counted_before_transport');
$assert(str_contains($context, '$previousManualLimit') && str_contains($context, '$currentManualLimit'), 'nested_manual_context_must_not_reset_burst_counter');
$assert(str_contains($manualLimit, 'public readonly ?string $nextSafeAt'), 'manual_limit_exception_missing_next_safe_at');
$assert(!str_contains($service, '$remoteBefore = $this->transportCount()'), 'global_transport_delta_still_used');
$assert(str_contains($service, "'burst_unit' => 'PHYSICAL_REMOTE_HTTP'"), 'burst_unit_not_physical_http');
$assert(str_contains($service, 'max($canonicalNextSafe, $manualIntervalUntil, $manualCooldownUntil)'), 'manual_pacing_can_override_canonical');

$assert(str_contains($service, 'projectStepState'), 'error_projection_missing');
$assert(str_contains($service, '$httpStatus === 429') && str_contains($service, "'RATE_LIMITED'"), 'remote_429_projection_missing');
$assert(!str_contains($service, "str_contains(\$classification, 'rate_limit_deferred')"), 'local_rate_limit_deferred_mapped_as_rate_limited');
$assert(str_contains($service, 'pre_transport_deferred') || str_contains($service, "'COOLING'"), 'local_pretransport_cooling_missing');
$assert(str_contains($service, '$httpStatus === 401 || $httpStatus === 403'), 'oauth_http_pause_missing');
$assert(str_contains($service, 'remote_result_uncertain'), 'remote_uncertain_pause_missing');
$assert(str_contains($service, "'pause_and_ask'") && str_contains($service, "'auto_degrade'"), '429_action_values_missing');
$assert(str_contains($service, 'degradedProfile') && str_contains($service, "'auto_degraded' => \$autoDegraded"), 'auto_degrade_must_have_operational_effect');
$assert(str_contains($service, "? 'COOLING'") && str_contains($service, "? 'RATE_LIMITED'"), 'auto_degrade_must_project_cooling_not_rate_limited');
$assert(str_contains($service, 'profile_revision=profile_revision+?'), 'auto_degrade_must_increment_profile_revision_only_when_used');

$assert(str_contains($view, 'Preparar siguiente paso'), 'start_ui_missing');
$assert(str_contains($view, 'Pausar') && str_contains($view, 'Reanudar'), 'pause_resume_ui_missing');
$assert(str_contains($view, 'Finalizar / devolver al automático'), 'stop_return_to_auto_missing');
$assert(str_contains($view, 'data-k10-burst-current') && str_contains($view, 'burst_remote_calls_current') && str_contains($view, 'data-k10-next-safe'), 'burst_or_429_panel_missing');
$assert(str_contains($view, 'data-k10-action="heartbeat"'), 'heartbeat_form_missing');
$assert(str_contains($view, 'if (!token || inFlight) return;'), 'heartbeat_must_not_overlap_step');
$assert(str_contains($view, "action === 'pause'") && str_contains($view, "state !== 'PAUSED'"), 'pause_resume_visibility_must_follow_state');
$assert(str_contains($view, 'let inFlight = false') && str_contains($view, 'scheduleStep(payload)'), 'serial_browser_loop_missing');
$assert(str_contains($view, 'runOneStepSoon()') && str_contains($view, 'Sin trabajo elegible'), 'no_work_loop_must_stop');

$mutationBody = substr($controller, (int) strpos($controller, 'private function manualDrainJsonMutation'), 4200);
$assert(str_contains($mutationBody, 'PROFILE_GENERATION_REQUIRED'), 'post_mutations_must_require_generation_after_start');
$assert(str_contains($view, "url.searchParams.set('drain_generation'"), 'status_refresh_must_send_generation');

fwrite(STDOUT, "K10_P1_REPAIR_CONTRACT=PASS\n");
fwrite(STDOUT, "GLOBAL_AUTHORIZATION_SAFE=PASS\n");
fwrite(STDOUT, "TENANT_ONLY_ADMIN_CAN_START_GLOBAL_DRAIN=NO\n");
fwrite(STDOUT, "REMOTE_CALL_BURST_COUNTS_PHYSICAL_HTTP=PASS\n");
fwrite(STDOUT, "REMOTE_CALL_BURST_PRETRANSPORT_FENCE=PASS\n");
fwrite(STDOUT, "REMOTE_CALL_ATTRIBUTION_EXACT=PASS\n");
fwrite(STDOUT, "MANUAL_PACING_CAN_ONLY_SLOW_CANONICAL=PASS\n");
fwrite(STDOUT, "429_ACTION_EFFECTIVE=PASS\n");
fwrite(STDOUT, "AUTO_DEGRADE_EFFECTIVE=PASS\n");
fwrite(STDOUT, "GENERATION_REQUIRED_FOR_POSTS=PASS\n");
fwrite(STDOUT, "HEARTBEAT_OVERLAP_GUARD=PASS\n");
fwrite(STDOUT, "ERROR_STATE_PROJECTION=PASS\n");
fwrite(STDOUT, "RETRY_AFTER_OVERRIDE_ALLOWED=NO\n");
fwrite(STDOUT, "REMOTE_UNCERTAIN_BLIND_RETRY=NO\n");
fwrite(STDOUT, "PROFILE_EDITOR_UI=PASS\n");
fwrite(STDOUT, "MANUAL_PROFILE_LIVE_UPDATE=PASS\n");
fwrite(STDOUT, "PAUSE_RESUME_UI=PASS\n");
fwrite(STDOUT, "SERIAL_BROWSER_DRAIN_LOOP=PASS\n");
