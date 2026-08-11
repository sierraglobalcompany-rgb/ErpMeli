<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$authority = json_decode(
    (string) file_get_contents($root . '/resources/release/updater-authority-2.36.4.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
$legacyPath = $root . '/' . (string) $authority['supersedes_inventory']['path'];
$legacyBytes = file_get_contents($legacyPath);
if (!is_string($legacyBytes)
    || !hash_equals((string) $authority['supersedes_inventory']['sha256'], hash('sha256', $legacyBytes))
) {
    throw new RuntimeException('legacy_updater_inventory_drift');
}
$legacy = json_decode($legacyBytes, true, 32, JSON_THROW_ON_ERROR);
$legacyFiles = [];
foreach ($legacy['files'] as $file) {
    $legacyFiles[(string) $file['path']] = (string) $file['final_sha256'];
}
if (count($legacyFiles) !== (int) $authority['supersedes_inventory']['file_count']) {
    throw new RuntimeException('legacy_updater_count_drift');
}

$intentional = [];
foreach ($authority['intentional_locked_changes'] as $change) {
    $path = (string) $change['path'];
    $intentional[$path] = true;
    if (!isset($legacyFiles[$path])
        || !hash_equals($legacyFiles[$path], (string) $change['previous_sha256'])
        || !hash_equals((string) $change['target_sha256'], hash_file('sha256', $root . '/' . $path))
    ) {
        throw new RuntimeException('intentional_updater_change_drift:' . $path);
    }
}

$unchangedLines = [];
foreach ($legacyFiles as $path => $hash) {
    if (isset($intentional[$path])) {
        continue;
    }
    if (!is_file($root . '/' . $path) || !hash_equals($hash, hash_file('sha256', $root . '/' . $path))) {
        throw new RuntimeException('unexpected_locked_updater_change:' . $path);
    }
    $unchangedLines[$path] = $path . "\t" . $hash;
}
ksort($unchangedLines, SORT_STRING);
$aggregate = hash('sha256', implode("\n", $unchangedLines) . "\n");
if (count($unchangedLines) !== (int) $authority['unchanged_locked']['file_count']
    || !hash_equals((string) $authority['unchanged_locked']['sorted_path_hash_lines_sha256'], $aggregate)
) {
    throw new RuntimeException('unchanged_updater_authority_drift');
}

foreach ($authority['new_runtime_dependencies'] as $dependency) {
    $path = (string) $dependency['path'];
    if (!is_file($root . '/' . $path)
        || !hash_equals((string) $dependency['sha256'], hash_file('sha256', $root . '/' . $path))
    ) {
        throw new RuntimeException('new_updater_dependency_drift:' . $path);
    }
}

fwrite(STDOUT, 'Updater authority 2.36.4: PASS legacy=' . count($legacyFiles)
    . ' intentional=' . count($intentional) . ' unchanged=' . count($unchangedLines) . PHP_EOL);

