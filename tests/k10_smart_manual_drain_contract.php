<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$view = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
$launcher = (string) file_get_contents($root . '/app/QueueCore/ManualQueueLauncher.php');

$assert(str_contains($controller, 'public function manualProcessingRetired()'), 'manual_retired_handler_missing');
$assert(str_contains($controller, 'RETIRED_BLOCKED'), 'manual_retired_state_missing');
$assert(!str_contains($controller, 'private function manualDrainJsonMutation'), 'manual_drain_mutation_should_not_be_runtime_reachable');
$assert(!str_contains($controller, 'private function requireManualDrainGlobalAuthority'), 'manual_drain_global_guard_should_be_removed_with_runtime_path');
$assert(!str_contains($controller, 'ManualDrainSessionService())->status'), 'manual_page_must_not_load_drain_session');

foreach ([
    '/settings/manual-processing/drain/start',
    '/settings/manual-processing/drain/step',
    '/settings/manual-processing/drain/heartbeat',
    '/settings/manual-processing/drain/pause',
    '/settings/manual-processing/drain/resume',
    '/settings/manual-processing/drain/stop',
    '/settings/manual-processing/drain/profile',
    '/settings/manual-processing/drain/status.json',
] as $path) {
    $assert(str_contains($routes, $path), 'retired_route_missing:' . $path);
}
$assert(substr_count($routes, "'manualProcessingRetired'") >= 10, 'legacy_manual_routes_not_bound_to_retired_handler');

$assert(!str_contains($view, 'Procesamiento manual seguro'), 'old_manual_drain_title_still_visible');
$assert(!str_contains($view, 'data-k10'), 'old_manual_drain_js_still_visible');
$assert(!str_contains($view, '/settings/manual-processing/drain/'), 'old_manual_drain_forms_still_visible');
$assert(str_contains($view, 'Sin campaña, sin drenador continuo, sin trabajo en segundo plano'), 'f5_no_drain_copy_missing');
$assert(substr_count($launcher, "->acquire('manual'") === 1, 'f5_manual_batch_must_use_one_global_lease');

fwrite(STDOUT, "K10_SMART_MANUAL_DRAIN_CONTRACT=RETIRED_BY_F5_PASS\n");
