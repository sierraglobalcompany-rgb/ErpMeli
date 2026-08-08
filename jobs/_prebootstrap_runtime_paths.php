<?php

declare(strict_types=1);

/**
 * Rutas visibles antes de cargar autoload, entorno o MariaDB.
 *
 * Un job puede ejecutarse directamente desde la instalación clásica o desde
 * releases/<release> mediante launcher/cron.php. Los marcadores persistentes
 * PAUSE_* viven en la raíz estable de la instalación, nunca dentro de una
 * release efímera. Los marcadores de trabajo viven en storage compartido.
 */
if (!function_exists('erp_prebootstrap_runtime_root')) {
    function erp_prebootstrap_runtime_root(): string
    {
        return rtrim(dirname(__DIR__), '/\\');
    }
}

if (!function_exists('erp_prebootstrap_installation_root')) {
    function erp_prebootstrap_installation_root(): string
    {
        if (defined('ERP_INSTALLATION_ROOT')) {
            return rtrim((string) constant('ERP_INSTALLATION_ROOT'), '/\\');
        }

        $runtimeRoot = erp_prebootstrap_runtime_root();
        if (basename(dirname($runtimeRoot)) === 'releases') {
            return rtrim(dirname($runtimeRoot, 2), '/\\');
        }

        return $runtimeRoot;
    }
}

if (!function_exists('erp_prebootstrap_shared_root')) {
    function erp_prebootstrap_shared_root(): string
    {
        if (defined('ERP_SHARED_ROOT')) {
            return rtrim((string) constant('ERP_SHARED_ROOT'), '/\\');
        }

        $installationRoot = erp_prebootstrap_installation_root();
        return is_file($installationRoot . '/shared/current-release.json')
            ? $installationRoot . '/shared'
            : $installationRoot;
    }
}

if (!function_exists('erp_prebootstrap_path_candidates')) {
    /** @return list<string> */
    function erp_prebootstrap_path_candidates(string $relative): array
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $installationRoot = erp_prebootstrap_installation_root();
        $sharedRoot = erp_prebootstrap_shared_root();
        $roots = array_values(array_unique([
            $sharedRoot,
            $installationRoot,
            $installationRoot . '/shared',
        ]));

        return array_map(
            static fn (string $root): string => rtrim($root, '/\\') . '/' . $relative,
            $roots
        );
    }
}

if (!function_exists('erp_prebootstrap_pause_marker_path')) {
    function erp_prebootstrap_pause_marker_path(string $name): string
    {
        if (!in_array($name, ['PAUSE_MELI_API', 'PAUSE_ERP_AUTOMATION'], true)) {
            throw new InvalidArgumentException('El marcador de seguridad no está permitido.');
        }

        return erp_prebootstrap_installation_root() . '/' . $name;
    }
}

if (!function_exists('erp_prebootstrap_pause_marker_exists')) {
    function erp_prebootstrap_pause_marker_exists(string $name): bool
    {
        $path = erp_prebootstrap_pause_marker_path($name);
        clearstatcache(true, $path);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return false;
        }
        fclose($handle);
        return true;
    }
}

if (!function_exists('erp_prebootstrap_marker_exists')) {
    function erp_prebootstrap_marker_exists(string $relative): bool
    {
        if (in_array($relative, ['PAUSE_MELI_API', 'PAUSE_ERP_AUTOMATION'], true)) {
            return erp_prebootstrap_pause_marker_exists($relative);
        }
        foreach (erp_prebootstrap_path_candidates($relative) as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('erp_prebootstrap_read_json_marker')) {
    /** @return array<string,mixed> */
    function erp_prebootstrap_read_json_marker(string $relative): array
    {
        foreach (erp_prebootstrap_path_candidates($relative) as $candidate) {
            clearstatcache(true, $candidate);
            $size = @filesize($candidate);
            if (!is_int($size) || $size < 2 || $size > 16384) {
                continue;
            }
            $raw = @file_get_contents($candidate);
            if (!is_string($raw)) {
                continue;
            }
            $decoded = json_decode($raw, true, 8);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}

if (!function_exists('erp_prebootstrap_coordinator_state')) {
    /**
     * @return array{
     *   exists:bool,valid:bool,task:string,backup_id:int,public_id:string,
     *   state:string,owner:string,generation:int,phase:string,freeze_active:bool,
     *   expires_at:int,reason:string
     * }
     */
    function erp_prebootstrap_coordinator_state(): array
    {
        $class = erp_prebootstrap_runtime_root()
            . '/app/Services/LocalMaintenanceCoordinator.php';
        if (!is_file($class)) {
            return [
                'exists' => false,
                'valid' => false,
                'task' => '',
                'backup_id' => 0,
                'public_id' => '',
                'state' => '',
                'owner' => '',
                'generation' => 0,
                'phase' => '',
                'freeze_active' => false,
                'expires_at' => 0,
                'reason' => 'coordinator_unavailable',
            ];
        }
        require_once $class;
        return \App\Services\LocalMaintenanceCoordinator::inspectBeforeBootstrap(
            erp_prebootstrap_shared_root(),
            erp_prebootstrap_installation_root()
        );
    }
}

if (!function_exists('erp_prebootstrap_runtime_state')) {
    /**
     * @return array{
     *   automation_stopped:bool,backup_requested:bool,restore_requested:bool,
     *   mutation_freeze:bool,snapshot_freeze:bool,local_task:string,local_id:int,
     *   coordinator_exists:bool,coordinator_valid:bool,coordinator_task:string,
     *   coordinator_reason:string
     * }
     */
    function erp_prebootstrap_runtime_state(): array
    {
        $coordinator = erp_prebootstrap_coordinator_state();
        $freeze = erp_prebootstrap_read_json_marker(
            'storage/cache/database-mutation-freeze.json'
        );
        $purpose = (string) ($freeze['purpose'] ?? '');
        $context = is_array($freeze['context'] ?? null) ? $freeze['context'] : [];
        $localTask = '';
        $localId = 0;
        if ($purpose === 'database_sanitation') {
            $localTask = $purpose;
            $localId = max(0, (int) ($context['session_id'] ?? 0));
        } elseif ($purpose === 'imported_data_reset') {
            $localTask = $purpose;
            $localId = max(0, (int) ($context['request_id'] ?? 0));
        }
        if ($localId < 1) {
            $localTask = '';
        }
        $backupRequested = erp_prebootstrap_marker_exists(
            'storage/cache/backup-maintenance-request.json'
        ) || (bool) $coordinator['exists'];
        $snapshotFreeze = erp_prebootstrap_marker_exists(
            'storage/cache/database-snapshot-active.json'
        );
        if (
            $localTask === ''
            && (
                ($snapshotFreeze && !$backupRequested)
                || ($coordinator['exists'] && !$coordinator['valid'])
            )
        ) {
            // Un snapshot huérfano o coordinador inválido nunca habilita la
            // automatización normal. Solo permite cargar MariaDB en modo
            // remoto=false para reconciliar la copia exacta y salir.
            $localTask = 'backup_recovery';
            $localId = max(0, (int) $coordinator['backup_id']);
        }
        return [
            'automation_stopped' => erp_prebootstrap_pause_marker_exists('PAUSE_ERP_AUTOMATION'),
            'backup_requested' => $backupRequested
                || ($coordinator['valid'] && $coordinator['task'] === 'backup'),
            'restore_requested' => erp_prebootstrap_marker_exists(
                'storage/cache/restore-maintenance-request.json'
            ),
            'mutation_freeze' => erp_prebootstrap_marker_exists(
                'storage/cache/database-mutation-freeze.json'
            ),
            'snapshot_freeze' => $snapshotFreeze,
            'local_task' => $localTask,
            'local_id' => $localId,
            'coordinator_exists' => (bool) $coordinator['exists'],
            'coordinator_valid' => (bool) $coordinator['valid'],
            'coordinator_task' => (string) $coordinator['task'],
            'coordinator_reason' => (string) $coordinator['reason'],
        ];
    }
}

if (!function_exists('erp_prebootstrap_runtime_mode')) {
    /**
     * @param array{
     *   automation_stopped:bool,backup_requested:bool,restore_requested:bool,
     *   mutation_freeze:bool,snapshot_freeze:bool,local_task:string,local_id:int,
     *   coordinator_exists:bool,coordinator_valid:bool,coordinator_task:string,
     *   coordinator_reason:string
     * } $state
     * @return 'maintenance'|'automation_stopped'|'local_maintenance'|'normal'
     */
    function erp_prebootstrap_runtime_mode(array $state): string
    {
        $localMaintenance = $state['backup_requested']
            || $state['restore_requested']
            || $state['local_task'] !== '';
        /*
         * Un coordinador inválido debe llegar a la reconciliación de MariaDB.
         * El modo local mantiene remote=false; detenerlo aquí producía un
         * bloqueo permanente imposible de reparar desde el propio lanzador.
         */
        if (($state['mutation_freeze'] || $state['snapshot_freeze']) && !$localMaintenance) {
            return 'maintenance';
        }
        if ($state['automation_stopped'] && !$localMaintenance) {
            return 'automation_stopped';
        }
        return $localMaintenance ? 'local_maintenance' : 'normal';
    }
}
