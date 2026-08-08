<?php

declare(strict_types=1);

/**
 * Despacha un entrypoint estable hacia la misma release indicada por el
 * puntero atómico. Devuelve false cuando no hay instalación administrada para
 * que una instalación clásica pueda seguir usando su runtime local.
 */
function erp_dispatch_active_entrypoint(
    string $installationRoot,
    string $entrypoint,
    bool $allowClassicRecovery = false
): bool
{
    if (defined('ERP_RELEASE_BOOTSTRAPPED')) {
        return false;
    }
    $allowed = [
        'index.php',
        'login.php',
        'actualizar.php',
        'stop.php',
        'mantenimiento.php',
        'recuperar.php',
        'cron-status.php',
    ];
    if (!in_array($entrypoint, $allowed, true)) {
        throw new RuntimeException('El entrypoint solicitado no está permitido.');
    }

    $installationRoot = rtrim($installationRoot, '/\\');
    $pointerPath = $installationRoot . '/shared/current-release.json';
    if (!is_file($pointerPath)) {
        return false;
    }
    try {
        $pointer = json_decode(
            (string) file_get_contents($pointerPath),
            true,
            16,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable $error) {
        if ($allowClassicRecovery) {
            return false;
        }
        throw new RuntimeException('El puntero de release no es válido.', 0, $error);
    }
    $relative = str_replace('\\', '/', (string) ($pointer['path'] ?? ''));
    $releaseId = (string) ($pointer['release_id'] ?? '');
    if (
        $relative === ''
        || $releaseId === ''
        || str_starts_with($relative, '/')
        || preg_match('#^[A-Za-z]:/#', $relative) === 1
        || in_array('..', explode('/', $relative), true)
    ) {
        if ($allowClassicRecovery) {
            return false;
        }
        throw new RuntimeException('El puntero de release contiene una ruta insegura.');
    }

    $releaseRoot = realpath($installationRoot . '/' . $relative);
    $releasesRoot = realpath($installationRoot . '/releases');
    if (
        $releaseRoot === false
        || $releasesRoot === false
        || !str_starts_with(
            str_replace('\\', '/', $releaseRoot) . '/',
            rtrim(str_replace('\\', '/', $releasesRoot), '/') . '/'
        )
        || basename($releaseRoot) !== $releaseId
        || !is_file($releaseRoot . '/' . $entrypoint)
    ) {
        if ($allowClassicRecovery) {
            return false;
        }
        throw new RuntimeException('La release activa no contiene el entrypoint solicitado.');
    }

    define('ERP_RELEASE_BOOTSTRAPPED', true);
    define('ERP_INSTALLATION_ROOT', $installationRoot);
    define('ERP_RELEASE_ROOT', $releaseRoot);
    define('ERP_RELEASE_ID', $releaseId);
    define('ERP_SHARED_ROOT', $installationRoot . '/shared');
    require $releaseRoot . '/' . $entrypoint;
    return true;
}
