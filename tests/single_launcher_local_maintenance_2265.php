<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/erp-launcher-2265-' . bin2hex(random_bytes(6));
@mkdir($root . '/storage/cache', 0700, true);
file_put_contents($root . '/PAUSE_MELI_API', 'test');
file_put_contents($root . '/PAUSE_ERP_AUTOMATION', 'test');
file_put_contents(
    $root . '/storage/cache/database-mutation-freeze.json',
    json_encode([
        'version' => 1,
        'purpose' => 'database_sanitation',
        'owner' => 'maintenance:test',
        'context' => ['session_id' => 73],
    ], JSON_THROW_ON_ERROR)
);
define('ERP_INSTALLATION_ROOT', $root);
define('ERP_SHARED_ROOT', $root);
require dirname(__DIR__) . '/jobs/_prebootstrap_runtime_paths.php';

try {
    $state = erp_prebootstrap_runtime_state();
    if (($state['local_task'] ?? '') !== 'database_sanitation'
        || (int) ($state['local_id'] ?? 0) !== 73
        || erp_prebootstrap_runtime_mode($state) !== 'local_maintenance') {
        throw new RuntimeException('El lanzador único no reconoció el saneamiento local.');
    }
    $cron = (string) file_get_contents(dirname(__DIR__) . '/jobs/process_sync_queue.php');
    foreach ([
        'DatabaseMaintenanceService',
        'ImportedMeliDataResetService',
        'BackupCenterService',
        'RestoreService',
    ] as $service) {
        if (!str_contains($cron, $service)) {
            throw new RuntimeException('El lanzador único no integra ' . $service . '.');
        }
    }
    if (!str_contains($cron, '$localMaintenanceRequestCount > 1')
        || !str_contains($cron, 'local_maintenance_conflict')) {
        throw new RuntimeException(
            'El lanzador debe bloquear solicitudes locales incompatibles en lugar de escoger una.'
        );
    }
    foreach (['process_database_maintenance.php', 'reset_imported_meli_data.php'] as $retiredJob) {
        $source = (string) file_get_contents(dirname(__DIR__) . '/jobs/' . $retiredJob);
        if (!str_contains($source, '_retired_job.php')
            || str_contains($source, 'cron_entry_early_lock()')
            || str_contains($source, "require __DIR__ . '/_bootstrap.php'")) {
            throw new RuntimeException(
                'El ejecutor puntual todavía puede adquirir recursos: ' . $retiredJob
            );
        }
    }
    foreach ([
        'app/Views/settings/database_maintenance.php',
        'app/Views/settings/imported_data_reset.php',
    ] as $viewPath) {
        $source = (string) file_get_contents(dirname(__DIR__) . '/' . $viewPath);
        if (str_contains($source, 'php jobs/process_database_maintenance.php')
            || str_contains($source, 'php jobs/reset_imported_meli_data.php')) {
            throw new RuntimeException(
                'La interfaz todavía anuncia un ejecutor puntual: ' . $viewPath
            );
        }
    }
    $maintenanceView = (string) file_get_contents(dirname(__DIR__) . '/app/Views/settings/database_maintenance.php');
    if (
        !str_contains($maintenanceView, 'data-step-url')
        || !str_contains($maintenanceView, 'Mantenga esta pestaña abierta')
        || str_contains($maintenanceView, 'php jobs/process_sync_queue.php')
    ) {
        throw new RuntimeException('La interfaz de saneamiento debe avanzar por navegador sin pedir cron.');
    }
    $registeredAdapter = (string) file_get_contents(
        dirname(__DIR__) . '/app/Services/RegisteredManualCampaignAdapter.php'
    );
    $interactiveContract = (string) file_get_contents(
        dirname(__DIR__) . '/app/Services/InteractiveCampaignAdapter.php'
    );
    if (str_contains($registeredAdapter, 'processStep(')
        || str_contains($interactiveContract, 'function processStep(')) {
        throw new RuntimeException(
            'El adaptador interactivo todavía expone una ruta ejecutora residual.'
        );
    }
    echo "PASS single_launcher_local_maintenance_2265\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($root . '/storage/cache/database-mutation-freeze.json');
    @unlink($root . '/PAUSE_MELI_API');
    @unlink($root . '/PAUSE_ERP_AUTOMATION');
    @rmdir($root . '/storage/cache');
    @rmdir($root . '/storage');
    @rmdir($root);
}
