<?php

declare(strict_types=1);

if (!function_exists('erp_retired_job')) {
    function erp_retired_job(string $component): never
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(404);
            exit;
        }
        require_once __DIR__ . '/_automation_emergency_stop.php';
        erp_automation_exit_if_emergency_stopped($component);
        require_once __DIR__ . '/_meli_emergency_stop.php';
        erp_meli_exit_if_emergency_stopped($component);

        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $component) ?: 'legacy_job';
        echo 'ERP_JOB_SKIP component=' . $safe
            . ' reason=retired_queue_v4_only' . PHP_EOL;
        exit(0);
    }
}
