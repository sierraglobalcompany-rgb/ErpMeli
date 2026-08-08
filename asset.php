<?php

declare(strict_types=1);

if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$installationRoot = __DIR__;
$assetRoot = $installationRoot . '/public/assets';
$pointerPath = $installationRoot . '/shared/current-release.json';
if (is_file($pointerPath)) {
    try {
        $pointer = json_decode(
            (string) file_get_contents($pointerPath),
            true,
            16,
            JSON_THROW_ON_ERROR
        );
        $relative = str_replace('\\', '/', (string) ($pointer['path'] ?? ''));
        $releaseId = (string) ($pointer['release_id'] ?? '');
        if (
            $relative === ''
            || $releaseId === ''
            || str_starts_with($relative, '/')
            || preg_match('#^[A-Za-z]:/#', $relative) === 1
            || in_array('..', explode('/', $relative), true)
        ) {
            throw new RuntimeException('invalid_pointer');
        }
        $releaseRoot = realpath($installationRoot . '/' . $relative);
        $releasesRoot = realpath($installationRoot . '/releases');
        if (
            $releaseRoot === false
            || $releasesRoot === false
            || basename($releaseRoot) !== $releaseId
            || !str_starts_with(
                str_replace('\\', '/', $releaseRoot) . '/',
                rtrim(str_replace('\\', '/', $releasesRoot), '/') . '/'
            )
        ) {
            throw new RuntimeException('invalid_release');
        }
        $assetRoot = $releaseRoot . '/public/assets';
    } catch (Throwable) {
        http_response_code(503);
        header('Cache-Control: no-store');
        exit;
    }
}

$relativeAsset = str_replace('\\', '/', trim((string) ($_GET['path'] ?? '')));
if (
    $relativeAsset === ''
    || str_starts_with($relativeAsset, '/')
    || str_contains($relativeAsset, "\0")
    || in_array('..', explode('/', $relativeAsset), true)
    || preg_match('#^[A-Za-z0-9._/-]+$#', $relativeAsset) !== 1
) {
    http_response_code(404);
    exit;
}
$assetRootReal = realpath($assetRoot);
$file = realpath($assetRoot . '/' . $relativeAsset);
if (
    $assetRootReal === false
    || $file === false
    || !is_file($file)
    || !str_starts_with(
        str_replace('\\', '/', $file),
        rtrim(str_replace('\\', '/', $assetRootReal), '/') . '/'
    )
) {
    http_response_code(404);
    exit;
}

$extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
$types = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf' => 'font/ttf',
    'map' => 'application/json; charset=utf-8',
];
if (!isset($types[$extension])) {
    http_response_code(404);
    exit;
}

$size = (int) filesize($file);
$modified = (int) filemtime($file);
$etag = '"' . hash('sha256', $file . '|' . $size . '|' . $modified) . '"';
header('Content-Type: ' . $types[$extension]);
header('X-Content-Type-Options: nosniff');
if ($relativeAsset === 'emergency-control.js') {
    // El control de emergencia no puede sobrevivir un cambio de build en la
    // caché del navegador. La URL además lleva fingerprint de contenido.
    header('Cache-Control: private, no-cache, max-age=0, must-revalidate');
} else {
    header('Cache-Control: public, max-age=31536000, immutable');
}
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . $size);
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    exit;
}
readfile($file);
