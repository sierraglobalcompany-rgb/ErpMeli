<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/_automation_emergency_stop.php';
erp_automation_exit_if_emergency_stopped('process_manual_queue');
require __DIR__ . '/_meli_emergency_stop.php';
erp_meli_exit_if_emergency_stopped('process_manual_queue');

$manifest = json_decode((string) @file_get_contents(dirname(__DIR__) . '/resources/runtime-manifest.json'), true);
$version = is_array($manifest) ? (string) ($manifest['version'] ?? 'unknown') : 'unknown';
$build = is_array($manifest) ? (string) ($manifest['build_id'] ?? 'unknown') : 'unknown';

echo 'ERP_MANUAL_BOOT component=process_manual_queue version='
    . preg_replace('/[^A-Za-z0-9_.-]/', '_', $version)
    . ' build=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $build) . PHP_EOL;
echo 'ERP_MANUAL_SKIP reason=retired_interactive_web version='
    . preg_replace('/[^A-Za-z0-9_.-]/', '_', $version)
    . ' use=process_sync_queue' . PHP_EOL;
exit(0);
