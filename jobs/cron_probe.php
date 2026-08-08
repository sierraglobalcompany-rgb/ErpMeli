<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/_automation_emergency_stop.php';
erp_automation_exit_if_emergency_stopped('cron_probe');
require __DIR__ . '/_meli_emergency_stop.php';
erp_meli_exit_if_emergency_stopped('cron_probe');

$probeToken = 'PROBE-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
echo 'ERP_CRON_PROBE_START run=' . $probeToken
    . ' php=' . PHP_VERSION
    . ' sapi=' . PHP_SAPI
    . ' time=' . gmdate('Y-m-d\TH:i:s\Z') . PHP_EOL;
if (function_exists('ob_flush')) {
    @ob_flush();
}
flush();

$health = null;
$healthId = 0;
$startedAt = microtime(true);
$integrityFailure = null;
$runtimeVersion = PHP_VERSION;
try {
    require dirname(__DIR__) . '/bootstrap.php';

    $requiredExtensions = ['curl', 'json', 'openssl', 'pdo', 'pdo_mysql', 'mbstring', 'session', 'iconv'];
    $missing = array_values(array_filter(
        $requiredExtensions,
        static fn(string $extension): bool => !extension_loaded($extension)
    ));
    if ($missing !== []) {
        throw new RuntimeException('Faltan extensiones PHP obligatorias: ' . implode(', ', $missing) . '.');
    }
    $runtimeVersion = (string) phpversion();
    if (version_compare($runtimeVersion, '8.3.0', '<') || version_compare($runtimeVersion, '8.6.0', '>=')) {
        throw new RuntimeException('La versión PHP no está dentro del rango validado 8.3–8.5.');
    }

    $config = \App\Core\AppPaths::configFile();
    if (!is_file($config) || !is_readable($config)) {
        throw new RuntimeException('No se encontró la configuración privada legible.');
    }
    if ((int) \App\Core\Database::connectionFresh()->query('SELECT 1')->fetchColumn() !== 1) {
        throw new RuntimeException('MySQL no respondió a la prueba segura.');
    }
    $storage = \App\Core\AppPaths::storage();
    foreach ([$storage, \App\Core\AppPaths::storage('cache'), \App\Core\AppPaths::storage('logs')] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el storage privado.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('El storage privado no tiene permisos de escritura.');
        }
    }
    $health = new \App\Services\CronHealthService();
    $run = $health->begin('cron_probe', 'hostinger_test', 5);
    $healthId = (int) $run['id'];

    $integrityService = new \App\Services\ReleaseIntegrityService();
    $integrity = $integrityService->inspect(true);
    if (!$integrity['ok']) {
        $integrityFailure = $integrity['errors'][0] ?? [
            'code' => 'integrity_failed',
            'component' => 'release',
        ];
        throw new RuntimeException(
            'La instalación no coincide con el manifiesto de la release: '
            . (string) ($integrityFailure['code'] ?? 'integrity_failed') . '.'
        );
    }

    $integrityService->stampRun($healthId, 'cron_probe');
    $summary = [
        'probe' => true,
        'extensions' => 'ok',
        'bootstrap' => 'ok',
        'database' => 'ok',
        'storage' => 'ok',
        'release_version' => (string) $integrity['version'],
        'release_build_id' => (string) $integrity['build_id'],
        'minimum_migration' => (string) $integrity['minimum_migration'],
        'release_integrity' => 'ok',
        'processed' => 0,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ];
    $health->finish($healthId, 'success', $summary, 'Diagnóstico CLI Hostinger correcto.', 0);
    echo 'ERP_CRON_PROBE_OK run=' . $probeToken
        . ' duration_ms=' . $summary['duration_ms']
        . ' version=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $integrity['version'])
        . ' build=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $integrity['build_id'])
        . ' database=ok storage=ok migration='
        . preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $integrity['minimum_migration'])
        . ' components=ok' . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    $safeMessage = class_exists(\App\Services\Logger::class)
        ? mb_substr(\App\Services\Logger::redactString($error->getMessage()), 0, 300)
        : 'El diagnóstico CLI no pudo completar una validación segura.';
    if ($health instanceof \App\Services\CronHealthService && $healthId > 0) {
        try {
            $health->finish($healthId, 'error', ['probe' => true], $safeMessage, 1);
        } catch (Throwable) {
            // El probe conserva el error original aunque la observabilidad falle.
        }
    }
    $reason = (string) ($integrityFailure['code'] ?? $error::class);
    $component = (string) ($integrityFailure['component'] ?? 'runtime');
    fwrite(
        STDERR,
        'ERP_CRON_PROBE_ERROR run=' . $probeToken
        . ' reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $reason)
        . ' component=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $component)
        . ' expected=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $runtimeVersion)
        . ' message=' . $safeMessage . PHP_EOL
    );
    exit(1);
}
