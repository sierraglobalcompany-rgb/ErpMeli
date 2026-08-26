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

$assert($version === '2.40.0', 'version_not_2400');
$assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS manual_drain_sessions'), 'manual_drain_sessions_missing');
$assert(str_contains($migration, 'session_token CHAR(64) NOT NULL'), 'session_token_missing');
$assert(str_contains($migration, 'UNIQUE KEY uq_manual_drain_session_token'), 'session_token_unique_missing');
$assert(str_contains($migration, 'status_ui VARCHAR(30) NOT NULL'), 'metadata_state_missing');
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
    '/settings/manual-processing/drain/status.json' => 'manualDrainStatus',
] as $path => $method) {
    $assert(str_contains($routes, $path), 'route_missing:' . $path);
    $assert(str_contains($controller, 'public function ' . $method . '('), 'controller_method_missing:' . $method);
}

$mutationBody = substr($controller, (int) strpos($controller, 'private function manualDrainJsonMutation'), 2200);
$assert(substr_count($mutationBody, 'requireAdminPermanent()') >= 1, 'manual_drain_requires_permanent_admin');
$assert(substr_count($mutationBody, 'Csrf::validate') >= 1, 'manual_drain_requires_csrf');
$assert(substr_count($mutationBody, 'assertSameOrigin') >= 1, 'manual_drain_requires_same_origin');

$assert(str_contains($view, 'K10 · SMART MANUAL DRAIN'), 'manual_drain_card_missing');
$assert(str_contains($view, 'V4_AUTOMATIC_BOUNDARY'), 'manual_drain_classification_missing');
$assert(str_contains($view, '/settings/manual-processing/drain/start'), 'manual_drain_start_form_missing');
$assert(str_contains($view, '/settings/manual-processing/drain/step'), 'manual_drain_step_form_missing');
$assert(str_contains($view, 'data-k10-feedback aria-live="polite"'), 'manual_drain_live_feedback_missing');
$assert(str_contains($view, '/settings/manual-processing/drain/status.json'), 'manual_drain_status_refresh_missing');
$assert(str_contains($view, '/settings/manual-processing/drain/heartbeat'), 'manual_drain_heartbeat_fetch_missing');
$assert(str_contains($view, 'window.setInterval(heartbeat, 15000)'), 'manual_drain_15s_heartbeat_missing');

$assert(str_contains($worker, "'run_id' => \$runId"), 'worker_run_receipt_missing_run_id');
$assert(str_contains($worker, "'run_id' => 0"), 'worker_noop_receipt_missing_run_id');
$assert(str_contains($leaseService, 'isTransientAcquireRace'), 'lease_acquire_transient_race_guard_missing');
$assert(str_contains($leaseService, 'return null;'), 'lease_acquire_transient_race_must_return_busy');

fwrite(STDOUT, "K10_SMART_MANUAL_DRAIN_CONTRACT=PASS\n");
