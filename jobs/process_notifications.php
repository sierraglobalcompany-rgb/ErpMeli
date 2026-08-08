<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/_automation_emergency_stop.php';
erp_automation_exit_if_emergency_stopped('process_notifications');
require __DIR__ . '/_meli_emergency_stop.php';
erp_meli_exit_if_emergency_stopped('process_notifications');

$manifest = json_decode(
    (string) @file_get_contents(dirname(__DIR__) . '/resources/runtime-manifest.json'),
    true
);
$version = is_array($manifest) ? (string) ($manifest['version'] ?? 'unknown') : 'unknown';
$build = is_array($manifest) ? (string) ($manifest['build_id'] ?? 'unknown') : 'unknown';

// Adaptador de compatibilidad: no abre base, no adquiere locks y no procesa
// recursos. Hostinger puede conservar temporalmente una tarea antigua sin
// duplicar Webhook-First mientras el administrador la retira.
echo 'ERP_CRON_SKIP component=process_notifications'
    . ' version=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $version)
    . ' build=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $build)
    . ' reason=retired_single_launcher'
    . ' use=process_sync_queue' . PHP_EOL;
