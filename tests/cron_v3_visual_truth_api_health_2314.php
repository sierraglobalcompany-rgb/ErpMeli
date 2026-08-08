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

$assert(str_contains($appJs, 'const isRuntimeOperational ='), 'Cron JS must centralize operational V3 detection.');
$assert(str_contains($appJs, 'hideLegacyV3PanelsWhenOperational()'), 'Cron JS must hide Shadow/Canary when V3 is operational.');
$assert(str_contains($appJs, 'if (!operationalMode)'), 'Cron polling must not fetch setup/canary in operational mode.');
$assert(str_contains($cronShell, 'data-cron-launcher-detail'), 'Cron launcher subtitle must be live-updated from V3 signals.');
$assert(str_contains($apiShell, 'data-operational-url='), 'API Health shell must expose V3 operational snapshot URL.');
$assert(!str_contains($apiShell, 'api-health.js?v=2.28.37'), 'API Health shell must not ship stale 2.28.37 cache key.');
$assert(
    str_contains($apiShell, 'api-health.js?v=2.32.0')
        || str_contains($apiShell, 'api-health.js?v=2.33.0')
        || str_contains($apiShell, 'api-health.js?v=2.34.1'),
    'API Health shell must ship the current build cache key.'
);
$assert(str_contains($apiJs, 'loadOperational()'), 'API Health JS must load operational snapshot before heavy HTML.');
$assert(str_contains($apiJs, 'No se pudo comprobar esta sección'), 'API Health JS must render a human fallback when a section fails.');

echo "cron_v3_visual_truth_api_health_2314: OK\n";
