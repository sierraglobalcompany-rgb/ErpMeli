<?php

declare(strict_types=1);

require_once __DIR__ . '/_prebootstrap_runtime_paths.php';

if (!function_exists('erp_automation_emergency_stop_active')) {
    function erp_automation_emergency_stop_active(): bool
    {
        return erp_prebootstrap_pause_marker_exists('PAUSE_ERP_AUTOMATION');
    }
}

if (!function_exists('erp_automation_exit_if_emergency_stopped')) {
    function erp_automation_exit_if_emergency_stopped(string $component): void
    {
        if (!erp_automation_emergency_stop_active()) {
            return;
        }
        $safeComponent = preg_replace('/[^A-Za-z0-9_.-]/', '_', $component) ?: 'legacy_job';
        echo 'ERP_JOB_WAIT component=' . $safeComponent
            . ' reason=manual_automation_stop remote=false database=false' . PHP_EOL;
        exit(0);
    }
}
