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

$manual = (string) file_get_contents($root . '/app/Services/ManualSingleStepService.php');
$assert(str_contains($manual, '(new ManualQueueLauncher())->runExact('), 'manual_exact_launcher_missing');
$assert(!str_contains($manual, 'QueueV4CleanScheduler'), 'manual_must_not_start_scheduler');
$assert(!str_contains($manual, 'while ('), 'manual_must_not_loop_in_background');

$manualView = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
$assert(str_contains($manualView, 'MANUAL_EXACT'), 'manual_exact_label_missing');
$assert(str_contains($manualView, 'no inicia ni continúa Cron en segundo plano'), 'manual_background_boundary_missing');

$settings = (string) file_get_contents($root . '/app/Views/settings/index.php');
foreach (['MANUAL_EXACT', 'LOCAL_ONLY'] as $label) {
    $assert(str_contains($settings, $label), 'settings_surface_label_missing:' . $label);
}

$modules = (string) file_get_contents($root . '/app/Views/settings/modules.php');
$assert(str_contains($modules, 'BUFFER_ONLY'), 'module_buffer_label_missing');
$assert(str_contains((string) file_get_contents($root . '/app/Views/settings/diagnostics.php'), 'Catálogo de incidentes'), 'diagnostics_materializer_freshness_missing');
$assert(str_contains($modules, 'no se reponen automáticamente'), 'module_no_auto_recovery_message_missing');

foreach (['backups.php', 'database_maintenance.php', 'imported_data_reset.php'] as $view) {
    $contents = (string) file_get_contents($root . '/app/Views/settings/' . $view);
    $assert(str_contains($contents, 'LOCAL_ONLY'), 'local_only_label_missing:' . $view);
    $assert(str_contains($contents, 'Cron'), 'local_only_cron_boundary_missing:' . $view);
}

fwrite(STDOUT, 'OPERATIONAL_SURFACE_CONTRACT_23917=PASS checks=' . $checks . ' real_meli_http=0' . PHP_EOL);
