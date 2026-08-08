<?php

declare(strict_types=1);

$installationRoot = dirname(__DIR__);
try {
    $pointer = json_decode((string) file_get_contents($installationRoot . '/shared/current-release.json'), true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    exit;
}
$relative = str_replace('\\', '/', (string) ($pointer['path'] ?? ''));
$releaseRoot = $relative !== '' && !str_starts_with($relative, '/') && !in_array('..', explode('/', $relative), true)
    ? realpath($installationRoot . '/' . $relative)
    : false;
$releasesRoot = realpath($installationRoot . '/releases');
if (
    $releaseRoot === false
    || $releasesRoot === false
    || !str_starts_with(str_replace('\\', '/', $releaseRoot) . '/', rtrim(str_replace('\\', '/', $releasesRoot), '/') . '/')
    || !is_file($releaseRoot . '/public/webhook_mercadolibre.php')
) {
    http_response_code(503);
    exit;
}
define('ERP_RELEASE_BOOTSTRAPPED', true);
define('ERP_INSTALLATION_ROOT', $installationRoot);
define('ERP_RELEASE_ROOT', $releaseRoot);
define('ERP_RELEASE_ID', (string) ($pointer['release_id'] ?? basename($releaseRoot)));
define('ERP_SHARED_ROOT', $installationRoot . '/shared');
require $releaseRoot . '/public/webhook_mercadolibre.php';
