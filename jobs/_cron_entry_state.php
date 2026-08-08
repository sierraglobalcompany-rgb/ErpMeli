<?php

declare(strict_types=1);

/**
 * Observabilidad mínima que funciona antes del autoloader y de la base.
 * El archivo no contiene rutas, argumentos, configuración ni secretos.
 *
 * @param array<string,scalar|null> $extra
 */
function cron_entry_state_write(
    string $stage,
    string $version,
    string $build,
    array $extra = []
): void {
    $directory = cron_entry_storage_directory();
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return;
    }
    $allowedStages = [
        'php_opened', 'bootstrap_loaded', 'database_connected',
        'installation_validated', 'lock_acquired', 'queues_prepared',
        'work_selected', 'finished', 'duplicate_skipped', 'automation_stopped',
        'database_maintenance', 'entry_storage_unavailable', 'v3_operational_skip',
        'failed_before_bootstrap',
    ];
    $payload = [
        'component' => 'process_sync_queue',
        'version' => preg_replace('/[^A-Za-z0-9_.-]/', '_', $version),
        'build' => preg_replace('/[^A-Za-z0-9_.-]/', '_', $build),
        'stage' => in_array($stage, $allowedStages, true) ? $stage : 'failed_before_bootstrap',
        'observed_at' => gmdate('c'),
    ];
    foreach (['result', 'diagnostic', 'processed', 'remote'] as $key) {
        if (array_key_exists($key, $extra)) {
            $payload[$key] = $extra[$key];
        }
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }
    $target = $directory . '/cron-entry-state.json';
    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable) {
        $suffix = str_replace('.', '', uniqid('', true));
    }
    $temporary = $target . '.' . $suffix . '.tmp';
    if (@file_put_contents($temporary, $json, LOCK_EX) !== false) {
        @rename($temporary, $target);
    }
    @unlink($temporary);
}

/**
 * @return array{status:'acquired'|'busy'|'storage_unavailable',handle:mixed}
 */
function cron_entry_early_lock(): array
{
    $directory = cron_entry_storage_directory();
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['status' => 'storage_unavailable', 'handle' => null];
    }
    $handle = @fopen($directory . '/process-sync-queue.entry.lock', 'c+');
    if (!is_resource($handle)) {
        return ['status' => 'storage_unavailable', 'handle' => null];
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return ['status' => 'busy', 'handle' => null];
    }
    return ['status' => 'acquired', 'handle' => $handle];
}

/**
 * Resuelve el storage compartido sin cargar bootstrap.php.
 *
 * El job puede ejecutarse directamente desde la instalación o mediante
 * launcher/cron.php dentro de releases/<versión>. En ambos casos el marcador
 * debe ser visible para el runtime web.
 */
function cron_entry_storage_directory(): string
{
    if (defined('ERP_SHARED_ROOT')) {
        return rtrim((string) constant('ERP_SHARED_ROOT'), '/\\') . '/storage/cache';
    }

    $releaseRoot = dirname(__DIR__);
    if (is_file($releaseRoot . '/shared/current-release.json')) {
        return $releaseRoot . '/shared/storage/cache';
    }

    $managedRoot = dirname($releaseRoot, 2);
    if (
        basename(dirname($releaseRoot)) === 'releases'
        && is_file($managedRoot . '/shared/current-release.json')
    ) {
        return $managedRoot . '/shared/storage/cache';
    }

    return $releaseRoot . '/storage/cache';
}
