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
$single = (string) file_get_contents($root . '/app/Services/ManualSingleStepService.php');
$launcher = (string) file_get_contents($root . '/app/QueueCore/ManualQueueLauncher.php');

$assert(str_contains($controller, 'manualProcessingRetired'), 'manual_retired_handler_missing');
$assert(str_contains($controller, 'RETIRED_BLOCKED'), 'manual_retired_state_missing');
$assert(str_contains($routes, '/settings/manual-processing/drain/profile'), 'retired_profile_route_missing');
$assert(str_contains($routes, '/settings/manual-processing/drain/status.json'), 'retired_status_route_missing');
$assert(!str_contains($view, 'data-k10-burst-current'), 'old_burst_ui_still_visible');
$assert(!str_contains($view, 'data-k10-action="heartbeat"'), 'old_heartbeat_ui_still_visible');
$assert(str_contains($view, 'Procesar selección exacta'), 'f5_exact_process_ui_missing');
$assert(str_contains($single, 'executeMany('), 'f5_amount_execution_missing');
$assert(str_contains($single, 'array_slice($rows, 0, $limit)'), 'f5_preview_subset_missing');
$assert(substr_count($launcher, "->acquire('manual'") === 1, 'f5_second_global_lease_regression');
$assert(str_contains($launcher, "'manual_step_no_background_continuation'"), 'f5_no_background_closeout_missing');

fwrite(STDOUT, "K10_P1_REPAIR_CONTRACT=RETIRED_BY_F5_PASS\n");
fwrite(STDOUT, "GLOBAL_AUTHORIZATION_SAFE=REPLACED_BY_SINGLE_GLOBAL_QUEUE_LEASE\n");
fwrite(STDOUT, "SERIAL_BROWSER_DRAIN_LOOP=RETIRED\n");
fwrite(STDOUT, "PROFILE_EDITOR_UI=RETIRED\n");
