<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/ManagedRuntimePublicationPolicy.php';

use App\Services\ManagedRuntimePublicationPolicy;

$root = dirname(__DIR__);
$arguments = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$dryRun = in_array('--dry-run', $arguments, true);
$ref = 'HEAD';
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--ref=')) {
        $ref = trim(substr($argument, strlen('--ref=')));
    }
}
if ($ref === '') {
    fwrite(STDERR, "Manifest ref cannot be empty.\n");
    exit(2);
}
if ($dryRun) {
    $manifest = json_decode(ManagedRuntimePublicationPolicy::gitBlob($root, $ref, 'resources/runtime-manifest.json'), true);
    $issues = is_array($manifest) ? ManagedRuntimePublicationPolicy::manifestIssues($root, $manifest, $ref) : ['manifest_malformed'];
    $package = ManagedRuntimePublicationPolicy::packageEntries($root, $ref);
    fwrite(STDOUT, json_encode([
        'ok' => $issues === [],
        'manifest_components' => is_array($manifest['components'] ?? null) ? count($manifest['components']) : 0,
        'package_files' => count($package),
        'issues' => $issues,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit($issues === [] ? 0 : 1);
}

$manifest = ManagedRuntimePublicationPolicy::buildManifest($root, $ref);
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($root . '/resources/runtime-manifest.json', $json, LOCK_EX) !== strlen($json)) {
    fwrite(STDERR, "Unable to publish runtime manifest.\n");
    exit(1);
}
fwrite(STDOUT, 'Manifest components: ' . count($manifest['components']) . "\n");
