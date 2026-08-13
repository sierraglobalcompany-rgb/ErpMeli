<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$gitBlob = static function (string $path) use ($root): string {
    $pipes = [];
    $process = proc_open(
        ['git', '-C', $root, 'show', 'HEAD:' . $path],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('git_blob_process_unavailable:' . $path);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($stdout)) {
        throw new RuntimeException('git_blob_unavailable:' . $path . ':' . trim((string) $stderr));
    }
    return $stdout;
};

$authorityPath = $root . '/resources/release/updater-authority-2.38.3.json';
$authorityBytes = file_get_contents($authorityPath);
if (!is_string($authorityBytes) || str_contains($authorityBytes, 'PENDING')) {
    throw new RuntimeException('updater_authority_unfrozen');
}
$authority = json_decode($authorityBytes, true, 32, JSON_THROW_ON_ERROR);
if (($authority['target_version'] ?? null) !== '2.38.3') {
    throw new RuntimeException('updater_authority_version_invalid');
}

$lineEndingEquivalent = 0;
$matchesLockedAuthority = static function (string $bytes, string $expected) use (&$lineEndingEquivalent): bool {
    if (hash_equals($expected, hash('sha256', $bytes))) {
        return true;
    }
    $lf = str_replace(["\r\n", "\r"], "\n", $bytes);
    $crlf = str_replace("\n", "\r\n", $lf);
    if (hash_equals($expected, hash('sha256', $crlf))) {
        $lineEndingEquivalent++;
        return true;
    }
    return false;
};

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
        || !hash_equals((string) $change['target_sha256'], hash('sha256', $gitBlob($path)))
    ) {
        throw new RuntimeException('intentional_updater_change_drift:' . $path);
    }
}

$unchangedLines = [];
foreach ($legacyFiles as $path => $hash) {
    if (isset($intentional[$path])) {
        continue;
    }
    if (!is_file($root . '/' . $path) || !$matchesLockedAuthority($gitBlob($path), $hash)) {
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

$dependencies = [];
foreach ($authority['new_runtime_dependencies'] as $dependency) {
    $path = (string) $dependency['path'];
    if (isset($dependencies[$path])) {
        throw new RuntimeException('duplicate_updater_dependency:' . $path);
    }
    $dependencies[$path] = true;
    if (!is_file($root . '/' . $path)
        || !hash_equals((string) $dependency['sha256'], hash('sha256', $gitBlob($path)))
    ) {
        throw new RuntimeException('new_updater_dependency_drift:' . $path);
    }
}

fwrite(STDOUT, 'Updater authority 2.38.3: PASS legacy=' . count($legacyFiles)
    . ' intentional=' . count($intentional) . ' unchanged=' . count($unchangedLines)
    . ' dependencies=' . count($dependencies)
    . ' crlf_equivalent=' . $lineEndingEquivalent . PHP_EOL);
