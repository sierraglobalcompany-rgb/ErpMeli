<?php

declare(strict_types=1);

$installationRoot = dirname(__DIR__);
$sharedRoot = $installationRoot . '/shared';
$pointerPath = $sharedRoot . '/current-release.json';

try {
    $pointer = json_decode((string) file_get_contents($pointerPath), true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    exit('La release activa no se puede determinar. Use el panel de rescate.');
}

$relative = str_replace('\\', '/', (string) ($pointer['path'] ?? ''));
if ($relative === '' || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)) {
    http_response_code(503);
    exit('El puntero de release contiene una ruta inválida.');
}
$releaseRoot = realpath($installationRoot . '/' . $relative);
$releasesRoot = realpath($installationRoot . '/releases');
if (
    $releaseRoot === false
    || $releasesRoot === false
    || !str_starts_with(str_replace('\\', '/', $releaseRoot) . '/', rtrim(str_replace('\\', '/', $releasesRoot), '/') . '/')
    || !is_file($releaseRoot . '/public/index.php')
) {
    http_response_code(503);
    exit('La release activa no está disponible. Use el panel de rescate.');
}

$maintenance = [];
if (is_file($sharedRoot . '/maintenance.json')) {
    try {
        $maintenance = json_decode((string) file_get_contents($sharedRoot . '/maintenance.json'), true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $maintenance = ['enabled' => true];
    }
}
$uriPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$allowedDuringMaintenance = preg_match('#/(settings/update|update-run|update_rescue\.php|login|logout)(?:/|$)#', $uriPath) === 1;
if (($maintenance['enabled'] ?? false) && !$allowedDuringMaintenance) {
    http_response_code(503);
    header('Retry-After: 60');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Actualización en progreso</title><body style="font-family:system-ui;background:#f4f7fb;padding:8vh 1rem">';
    echo '<main style="max-width:620px;margin:auto;background:#fff;padding:2rem;border-radius:16px;border:1px solid #dbe4f0">';
    echo '<h1>Actualización segura en progreso</h1><p>El ERP volverá a estar disponible cuando terminen las comprobaciones.</p></main></body></html>';
    exit;
}

define('ERP_RELEASE_BOOTSTRAPPED', true);
define('ERP_INSTALLATION_ROOT', $installationRoot);
define('ERP_RELEASE_ROOT', $releaseRoot);
define('ERP_RELEASE_ID', (string) ($pointer['release_id'] ?? basename($releaseRoot)));
define('ERP_SHARED_ROOT', $sharedRoot);
require $releaseRoot . '/public/index.php';
