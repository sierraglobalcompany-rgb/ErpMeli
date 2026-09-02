<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($full)) {
        fwrite(STDERR, "missing:$path\n");
        exit(1);
    }
    return (string) file_get_contents($full);
};
$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL:$label\n");
        exit(1);
    }
    echo "PASS:$label\n";
};

$controller = $read('app/Controllers/SettingsController.php');
$view = $read('app/Views/settings/manual_processing.php');
$single = $read('app/Services/ManualSingleStepService.php');
$launcher = $read('app/QueueCore/ManualQueueLauncher.php');
$routes = $read('public/index.php');

$assert(str_contains($single, 'function executeMany(string $previewToken, int $userId, int $limit)'), 'manual_amount_entrypoint');
$assert(str_contains($single, 'array_slice($rows, 0, $limit)'), 'preview_bound_exact_subset');
$assert(str_contains($single, "'manual-explicit', \$previewToken, (string) \$userId,"), 'explicit_attempt_key_bound_to_preview');
$assert(str_contains($single, 'runExactBatch($items)'), 'single_step_uses_batch_launcher');

$assert(substr_count($launcher, "->acquire('manual'") === 1, 'single_global_lease_acquire');
$assert(str_contains($launcher, 'runExactBatch(array $items)'), 'batch_launcher_exists');
$assert(str_contains($launcher, "'manual_step_no_background_continuation'"), 'no_background_continuation_guard');
$assert(str_contains($launcher, "['manual_exact']"), 'manual_exact_runner_filter');
$assert(str_contains($launcher, "'manual',\$jobId"), 'only_bound_job_id_run');

$assert(str_contains($controller, 'executeMany('), 'controller_processes_user_limit');
$assert(str_contains($controller, 'process_limit'), 'controller_reads_process_limit');
$assert(!str_contains($controller, 'manualProcessingRetired'), 'retired_endpoint_handler_removed');
$assert(!str_contains($controller, 'new \\App\\Services\\ManualDrainSessionService())->status'), 'manual_page_no_drain_status_load');
$assert(!str_contains($controller, 'private function manualDrainJsonMutation'), 'drain_mutation_removed_from_controller');
$assert(!str_contains($controller, 'private function manualProcessingSessionList'), 'session_list_removed_from_controller');

$retiredRoutes = [
    '/settings/manual-processing/setup',
    '/settings/manual-processing/session',
    '/settings/manual-processing/status.json',
    '/settings/manual-processing/interactive/step',
    '/settings/manual-processing/drain/start',
    '/settings/manual-processing/drain/status.json',
];
foreach ($retiredRoutes as $retiredRoute) {
    $assert(!str_contains($routes, $retiredRoute), 'legacy_manual_route_removed:' . $retiredRoute);
}
$assert(str_contains($routes, "'manualProcessing'"), 'manual_processing_route_kept');
$assert(str_contains($routes, "'manualProcessingPreview'"), 'manual_processing_preview_route_kept');
$assert(str_contains($routes, "'manualProcessingStart'"), 'manual_processing_start_route_kept');
$assert(!str_contains($view, 'k10-manual-drain'), 'k10_panel_removed');
$assert(!str_contains($view, 'data-k10'), 'k10_javascript_removed');
$assert(str_contains($view, '¿Cuántos procesar?'), 'kiss_amount_ui');
$assert(str_contains($view, 'Procesar selección exacta'), 'kiss_process_button');
$assert(str_contains($view, 'BACKGROUND_CONTINUATION=0'), 'no_background_claim_visible');
$assert(str_contains($view, 'ACTIVE_DRAINERS_MAX=1'), 'single_drainer_claim_visible');

echo "STATUS=F5_MANUAL_KISS_CONTRACT_PASS\n";
