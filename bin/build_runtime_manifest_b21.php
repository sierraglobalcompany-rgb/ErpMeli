<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/RuntimePublicationPolicy.php';

use App\Services\RuntimePublicationPolicy;

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], true);
if ($dryRun) {
    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
    $issues = is_array($manifest) ? RuntimePublicationPolicy::manifestIssues($root, $manifest) : ['manifest_malformed'];
    $package = RuntimePublicationPolicy::packageEntries($root);
    fwrite(STDOUT, json_encode([
        'ok' => $issues === [],
        'manifest_components' => is_array($manifest['components'] ?? null) ? count($manifest['components']) : 0,
        'package_files' => count($package),
        'issues' => $issues,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit($issues === [] ? 0 : 1);
}

$manifest = RuntimePublicationPolicy::buildManifest($root);
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($root . '/resources/runtime-manifest.json', $json, LOCK_EX) !== strlen($json)) {
    fwrite(STDERR, "Unable to publish runtime manifest.\n");
    exit(1);
}
fwrite(STDOUT, 'Manifest components: ' . count($manifest['components']) . "\n");
