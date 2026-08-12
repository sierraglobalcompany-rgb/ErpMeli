<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/ManagedRuntimePublicationPolicy.php';

use App\Services\ManagedRuntimePublicationPolicy;

$root = dirname(__DIR__);
$target = 'resources/release/updater-authority-2.37.1.json';
$baseCommit = '05603aa7a9fd63da87d81518955c731fc69b8574';
$previous = json_decode(
    ManagedRuntimePublicationPolicy::gitBlob($root, 'HEAD', 'resources/release/updater-authority-2.37.0.json'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);

$base = [];
foreach (ManagedRuntimePublicationPolicy::packageEntries($root, $baseCommit) as $entry) {
    $base[$entry['path']] = $entry['sha256'];
}
$dependencies = [];
foreach (ManagedRuntimePublicationPolicy::packageEntries($root, 'HEAD') as $entry) {
    $path = $entry['path'];
    if ($path === 'resources/runtime-manifest.json' || $path === $target) {
        continue;
    }
    if (!isset($base[$path]) || !hash_equals($base[$path], $entry['sha256'])) {
        $dependencies[] = ['path' => $path, 'sha256' => $entry['sha256']];
    }
}
usort($dependencies, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
if ($dependencies === []) {
    throw new RuntimeException('updater_dependency_delta_empty');
}

$authority = [
    'schema_version' => 1,
    'target_version' => '2.37.1',
    'contract' => 'The 2.37.0 to 2.37.1 update stabilizes Queue V4 Clean against the canonical MariaDB 11.8.8 schema, preserves legacy engines inert, creates no Hostinger scheduler, activates no engine, performs no readiness automatically and preserves the frozen updater write-set.',
    'supersedes_inventory' => $previous['supersedes_inventory'],
    'unchanged_locked' => $previous['unchanged_locked'],
    'intentional_locked_changes' => $previous['intentional_locked_changes'],
    'new_runtime_dependencies' => $dependencies,
];
$json = json_encode($authority, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($root . '/' . $target, $json, LOCK_EX) !== strlen($json)) {
    throw new RuntimeException('updater_authority_publish_failed');
}
fwrite(STDOUT, 'UPDATER_AUTHORITY_2371=' . count($dependencies) . PHP_EOL);

