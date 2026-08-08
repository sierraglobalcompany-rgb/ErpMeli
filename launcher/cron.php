<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$installationRoot = dirname(__DIR__);
$pointerPath = $installationRoot . '/shared/current-release.json';
if (!is_file($pointerPath)) {
    fwrite(STDERR, "No hay release administrada activa.\n");
    exit(2);
}
try {
    $pointer = json_decode((string) file_get_contents($pointerPath), true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "El puntero de release es inválido.\n");
    exit(2);
}
$relative = str_replace('\\', '/', (string) ($pointer['path'] ?? ''));
$releaseRoot = $relative !== '' && !str_starts_with($relative, '/') && !in_array('..', explode('/', $relative), true)
    ? realpath($installationRoot . '/' . $relative)
    : false;
$releasesRoot = realpath($installationRoot . '/releases');
$job = basename((string) ($argv[1] ?? 'process_sync_queue.php'));
if (
    $releaseRoot === false
    || $releasesRoot === false
    || !str_starts_with(str_replace('\\', '/', $releaseRoot) . '/', rtrim(str_replace('\\', '/', $releasesRoot), '/') . '/')
    || preg_match('/^[a-z0-9_-]+\.php$/i', $job) !== 1
    || !is_file($releaseRoot . '/jobs/' . $job)
) {
    fwrite(STDERR, "Job o release no disponible.\n");
    exit(2);
}
define('ERP_RELEASE_BOOTSTRAPPED', true);
define('ERP_INSTALLATION_ROOT', $installationRoot);
define('ERP_RELEASE_ROOT', $releaseRoot);
define('ERP_RELEASE_ID', (string) ($pointer['release_id'] ?? basename($releaseRoot)));
define('ERP_SHARED_ROOT', $installationRoot . '/shared');
array_splice($argv, 1, 1);
$_SERVER['argv'] = $argv;
require $releaseRoot . '/jobs/' . $job;
