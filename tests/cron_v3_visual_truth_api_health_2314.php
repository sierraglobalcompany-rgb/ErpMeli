<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$appJs = (string) file_get_contents($root . '/public/assets/app.js');
$apiJs = (string) file_get_contents($root . '/public/assets/api-health.js');
$apiShell = (string) file_get_contents($root . '/app/Views/settings/api_health_shell.php');
$cronShell = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
};

$assert(!str_contains($appJs, 'data-cron-v3') && !str_contains($appJs, 'data-cron-control'), 'Cron JS must not retain V3 controls.');
$assert(!str_contains($appJs, 'v3-runtime') && !str_contains($appJs, 'cron/parked'), 'Cron JS must not poll V3 routes.');
$assert(str_contains($cronShell, 'data-queue-v4-clean'), 'Cron shell must expose Queue V4 as the only launcher surface.');
$assert(str_contains($cronShell, 'Riesgos API · últimos 30 días'), 'Cron shell must expose the direct-telemetry risk card.');
$assert(str_contains($apiShell, 'AssetVersionService::fingerprint'), 'API Health shell must fingerprint its managed asset.');
$assert(!str_contains($apiShell, 'api-health.js?v=2.28.37'), 'API Health shell must not ship stale 2.28.37 cache key.');
$assert(
    str_contains($apiShell, 'api-health.js?v=2.32.0')
        || str_contains($apiShell, 'api-health.js?v=2.33.0')
        || str_contains($apiShell, 'api-health.js?v=2.34.1')
        || str_contains($apiShell, 'AssetVersionService::fingerprint'),
    'API Health shell must ship the current build cache key.'
);
$assert(str_contains($apiJs, 'loadOperational()'), 'API Health JS must load its bounded operational snapshot before heavy HTML.');
$assert(str_contains($apiJs, 'No se pudo comprobar esta sección'), 'API Health JS must render a human fallback when a section fails.');

echo "cron_v3_visual_truth_api_health_2314: OK\n";
