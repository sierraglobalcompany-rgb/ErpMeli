<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/_automation_emergency_stop.php';
erp_automation_exit_if_emergency_stopped('manual_engine_probe');
require __DIR__ . '/_meli_emergency_stop.php';
erp_meli_exit_if_emergency_stopped('manual_engine_probe');

// Compatibilidad inactiva. La certificación larga fue retirada: el lanzador
// principal mide su propia ventana y este archivo nunca abre la base ni duerme.
$manifest = json_decode(
    (string) @file_get_contents(dirname(__DIR__) . '/resources/runtime-manifest.json'),
    true
);
$version = is_array($manifest) ? (string) ($manifest['version'] ?? 'unknown') : 'unknown';
$build = is_array($manifest) ? (string) ($manifest['build_id'] ?? 'unknown') : 'unknown';

echo 'ERP_CRON_SKIP component=manual_engine_probe'
    . ' version=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $version)
    . ' build=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $build)
    . ' reason=retired_queue_v4_only' . PHP_EOL;
