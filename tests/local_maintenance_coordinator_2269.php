<?php

declare(strict_types=1);

$project = dirname(__DIR__);
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'erp-local-coordinator-2269-' . bin2hex(random_bytes(6));
$cache = $temporary . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

try {
    if (!mkdir($cache, 0770, true) && !is_dir($cache)) {
        throw new RuntimeException('No se pudo crear el storage temporal.');
    }
    file_put_contents(
        $temporary . DIRECTORY_SEPARATOR . 'config.env',
        'APP_KEY="coordinator-test-key-' . str_repeat('x', 32) . '"' . PHP_EOL
    );
    define('ERP_INSTALLATION_ROOT', $temporary);
    define('ERP_SHARED_ROOT', $temporary);
    define('ERP_RELEASE_ROOT', $project);
    putenv('APP_KEY=coordinator-test-key-' . str_repeat('x', 32));
    require $project . '/bootstrap.php';
    require_once $project . '/jobs/_prebootstrap_runtime_paths.php';

    $service = new \App\Services\LocalMaintenanceCoordinator();
    $publicId = '12345678-abcd-4abc-8abc-1234567890ab';
    $service->publishBackup(47, $publicId, 'pending');
    $inspection = \App\Services\LocalMaintenanceCoordinator::inspectBeforeBootstrap(
        $temporary,
        $temporary
    );
    if (
        !$inspection['valid']
        || $inspection['task'] !== 'backup'
        || $inspection['backup_id'] !== 47
        || !hash_equals($publicId, $inspection['public_id'])
    ) {
        throw new RuntimeException('El marcador firmado no sobrevivió la lectura prebootstrap.');
    }
    $service->publishBackup(47, $publicId, 'running', 'qa-worker', 9, 'create', true);
    $inspection = \App\Services\LocalMaintenanceCoordinator::inspectBeforeBootstrap(
        $temporary,
        $temporary
    );
    if (
        !$inspection['valid']
        || $inspection['state'] !== 'running'
        || $inspection['owner'] !== 'qa-worker'
        || $inspection['generation'] !== 9
        || $inspection['phase'] !== 'create'
        || !$inspection['freeze_active']
    ) {
        throw new RuntimeException('La renovación atómica del coordinador no fue válida.');
    }

    $markerPath = $cache . DIRECTORY_SEPARATOR . 'local-maintenance-state.json';
    $tampered = json_decode((string) file_get_contents($markerPath), true);
    $tampered['backup_id'] = 48;
    file_put_contents($markerPath, json_encode($tampered, JSON_THROW_ON_ERROR));
    $inspection = \App\Services\LocalMaintenanceCoordinator::inspectBeforeBootstrap(
        $temporary,
        $temporary
    );
    if ($inspection['valid'] || $inspection['reason'] !== 'coordinator_signature_invalid') {
        throw new RuntimeException('El coordinador aceptó un marcador alterado.');
    }
    $invalidState = erp_prebootstrap_runtime_state();
    if (
        $invalidState['local_task'] !== 'backup_recovery'
        || erp_prebootstrap_runtime_mode($invalidState) !== 'local_maintenance'
    ) {
        throw new RuntimeException(
            'Un coordinador alterado no abrió la reconciliación local con remote=false.'
        );
    }

    file_put_contents($markerPath, '{"version":2,"task":"backup","backup_id":47');
    $inspection = \App\Services\LocalMaintenanceCoordinator::inspectBeforeBootstrap(
        $temporary,
        $temporary
    );
    $truncatedState = erp_prebootstrap_runtime_state();
    if (
        $inspection['valid']
        || $inspection['reason'] !== 'coordinator_marker_unreadable'
        || $truncatedState['local_task'] !== 'backup_recovery'
        || erp_prebootstrap_runtime_mode($truncatedState) !== 'local_maintenance'
    ) {
        throw new RuntimeException(
            'Un coordinador truncado no quedó aislado en recuperación local.'
        );
    }

    @unlink($markerPath);
    file_put_contents(
        $cache . DIRECTORY_SEPARATOR . 'database-snapshot-active.json',
        json_encode([
            'snapshot_id' => '12345678abcd4abc8abc1234',
            'execution_tag' => 'queued',
            'format' => 3,
        ], JSON_THROW_ON_ERROR)
    );
    $state = erp_prebootstrap_runtime_state();
    if (
        $state['local_task'] !== 'backup_recovery'
        || erp_prebootstrap_runtime_mode($state) !== 'local_maintenance'
    ) {
        throw new RuntimeException('Un snapshot huérfano no abrió el carril de reconciliación local.');
    }

    file_put_contents(
        $cache . DIRECTORY_SEPARATOR . 'backup-maintenance-request.json',
        '{}'
    );
    $state = erp_prebootstrap_runtime_state();
    if (
        !$state['backup_requested']
        || erp_prebootstrap_runtime_mode($state) !== 'local_maintenance'
    ) {
        throw new RuntimeException('La señal heredada dejó de ser compatible.');
    }

    $launcher = (string) file_get_contents($project . '/jobs/process_sync_queue.php');
    foreach ([
        'LocalMaintenanceCoordinator',
        'terminal_reconciled',
        'ERP_CRON_WAIT component=local_backup',
        'remote=false',
    ] as $needle) {
        if (!str_contains($launcher, $needle)) {
            throw new RuntimeException('El lanzador no integra el contrato: ' . $needle);
        }
    }

    echo "PASS local_maintenance_coordinator_2269\n";
} finally {
    $removeTree($temporary);
}
