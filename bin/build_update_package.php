<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$arguments = [];
$cliArguments = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
foreach (array_slice($cliArguments, 1) as $argument) {
    if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
        [$key, $value] = explode('=', substr($argument, 2), 2);
        $arguments[$key] = $value;
    }
}
foreach (['source', 'output', 'private-key', 'key-id'] as $required) {
    if (trim((string) ($arguments[$required] ?? '')) === '') {
        fwrite(STDERR, "Falta --{$required}=...\n");
        exit(2);
    }
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "La extensión zip es necesaria para construir .erpupd.\n");
    exit(2);
}

$source = realpath((string) $arguments['source']);
$keyPath = realpath((string) $arguments['private-key']);
if ($source === false || !is_dir($source) || $keyPath === false || !is_file($keyPath)) {
    fwrite(STDERR, "La fuente o la clave privada no existen.\n");
    exit(2);
}
$version = trim((string) (@file_get_contents($source . '/VERSION') ?: ($arguments['version'] ?? '')));
if (preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9._-]+)?$/', $version) !== 1) {
    fwrite(STDERR, "VERSION no es válida.\n");
    exit(2);
}
$releaseId = strtolower((string) ($arguments['release-id'] ?? ($version . '-' . gmdate('YmdHis'))));
if (preg_match('/^[a-z0-9][a-z0-9._-]{2,119}$/', $releaseId) !== 1) {
    fwrite(STDERR, "release-id no es válido.\n");
    exit(2);
}

$allowedRootFiles = [
    '.htaccess',
    'asset.php',
    'actualizar.php',
    'bootstrap.php',
    'composer.json',
    'composer.lock',
    'config.env.example',
    'index.php',
    'login.php',
    'mantenimiento.php',
    'recuperar.php',
    'stop.php',
    'VERSION',
];
$allowedRoots = ['app', 'database', 'jobs', 'launcher', 'public', 'resources', 'stop'];
$forbiddenSegments = [
    '.git', '.github', '.idea', '.vscode', 'audits', 'docs', 'graphify-out',
    'node_modules', 'tests', 'tmp', 'tools', 'vendor',
];
$isAllowedRuntimePath = static function (string $relative) use (
    $allowedRootFiles,
    $allowedRoots,
    $forbiddenSegments
): bool {
    $segments = explode('/', $relative);
    $top = $segments[0];
    if (count($segments) === 1) {
        return in_array($relative, $allowedRootFiles, true);
    }
    if (!in_array($top, $allowedRoots, true)) {
        return false;
    }
    foreach ($segments as $segment) {
        if (in_array(strtolower($segment), $forbiddenSegments, true)) {
            return false;
        }
    }
    return true;
};
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $entry) {
    if (!$entry->isFile() || $entry->isLink()) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1));
    if ($relative === 'update-manifest.json' || !$isAllowedRuntimePath($relative)) {
        continue;
    }
    $files[] = ['path' => $relative, 'sha256' => hash_file('sha256', $entry->getPathname()), 'size' => $entry->getSize()];
}
usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
$packagedPaths = array_column($files, 'path');
$forbiddenPackagePatterns = [
    '#(^|/)(?:PAUSE_MELI_API|PAUSE_ERP_AUTOMATION)$#',
    '#(^|/)(?:config\.env|\.env)$#',
    '#(^|/)(?:graphify-out|tests|docs|audits|vendor|node_modules)(?:/|$)#i',
    '#\.(?:sql\.gz|erpbackup|log|bak|dump)$#i',
];
foreach ($packagedPaths as $packagedPath) {
    foreach ($forbiddenPackagePatterns as $pattern) {
        if (preg_match($pattern, $packagedPath) === 1) {
            fwrite(STDERR, "El paquete contiene un archivo prohibido: {$packagedPath}\n");
            exit(3);
        }
    }
}
foreach ([
    'VERSION',
    'asset.php',
    'bootstrap.php',
    'index.php',
    'login.php',
    'actualizar.php',
    'stop.php',
    'mantenimiento.php',
    'recuperar.php',
    'launcher/entrypoint.php',
    'public/index.php',
    'resources/runtime-manifest.json',
] as $requiredRuntime) {
    if (!in_array($requiredRuntime, $packagedPaths, true)) {
        fwrite(STDERR, "La fuente no contiene un runtime obligatorio: {$requiredRuntime}\n");
        exit(3);
    }
}
$upgradeFrom = array_values(array_filter(array_map('trim', explode(',', (string) ($arguments['upgrade-from'] ?? '*')))));
$manifest = [
    'manifest_version' => 1,
    'product_id' => 'erp-meli',
    'release_id' => $releaseId,
    'version' => $version,
    'sequence' => (int) ($arguments['sequence'] ?? 0),
    'channel' => (string) ($arguments['channel'] ?? 'stable'),
    'published_at' => gmdate('c'),
    'expires_at' => gmdate('c', time() + (max(1, (int) ($arguments['expires-days'] ?? 90)) * 86400)),
    'upgrade_from' => $upgradeFrom,
    'required_bridges' => [],
    'requirements' => [
        'php_min' => '8.3.0',
        'php_max_exclusive' => '8.6.0',
        'extensions' => ['curl', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'session', 'sodium'],
    ],
    'files' => $files,
    'migrations' => array_map('basename', glob($source . '/database/migrations/*.sql') ?: []),
    'health_checks' => ['bootstrap', 'front_controller', 'database', 'storage'],
    'rollback' => ['code_compatible' => true, 'database_restore_required' => false],
    'signing_key_id' => (string) $arguments['key-id'],
];

$canonical = static function (array $value): string {
    $sort = function (array &$item) use (&$sort): void {
        if (!array_is_list($item)) ksort($item);
        foreach ($item as &$child) if (is_array($child)) $sort($child);
    };
    $sort($value);
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
};
$privateKey = openssl_pkey_get_private((string) file_get_contents($keyPath));
if ($privateKey === false || !openssl_sign($canonical($manifest), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
    fwrite(STDERR, "No fue posible firmar el manifiesto.\n");
    exit(3);
}
$manifest['signature'] = base64_encode($signature);
$output = (string) $arguments['output'];
if (!str_ends_with(strtolower($output), '.erpupd')) {
    $output .= '.erpupd';
}
$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "No fue posible crear el paquete.\n");
    exit(3);
}
$zip->addFromString('update-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
foreach ($files as $file) {
    $zip->addFile($source . '/' . $file['path'], $file['path']);
}
$zip->close();
echo "Paquete: {$output}\nSHA-256: " . hash_file('sha256', $output) . "\nArchivos: " . count($files) . "\n";
