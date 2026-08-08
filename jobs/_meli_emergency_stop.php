<?php

declare(strict_types=1);

require_once __DIR__ . '/_prebootstrap_runtime_paths.php';

/**
 * Guardia de emergencia anterior a autoload, bootstrap y PDO.
 *
 * Este archivo no depende de ninguna clase del ERP. Los jobs heredados pueden
 * quedar configurados accidentalmente en Hostinger sin abrir MariaDB ni
 * realizar transporte mientras exista PAUSE_MELI_API.
 */
function erp_meli_emergency_stop_active(): bool
{
    return erp_prebootstrap_pause_marker_exists('PAUSE_MELI_API');
}

function erp_meli_exit_if_emergency_stopped(string $component): void
{
    if (!erp_meli_emergency_stop_active()) {
        return;
    }
    $safeComponent = preg_replace('/[^A-Za-z0-9_.-]/', '_', $component) ?: 'legacy_job';
    echo 'ERP_JOB_WAIT component=' . $safeComponent
        . ' reason=emergency_api_stop remote=false database=false' . PHP_EOL;
    exit(0);
}
