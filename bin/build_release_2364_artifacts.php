<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/MeliNotificationTopicRegistry.php';
require dirname(__DIR__) . '/app/Services/ManagedRuntimePublicationPolicy.php';

use App\Services\ManagedRuntimePublicationPolicy;
if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** @return array{exit:int,stdout:string,stderr:string} */
function release2364Git(string $root, array $arguments): array
{
    $pipes = [];
    $process = proc_open(array_merge(['git', '-C', $root], $arguments), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['exit' => 127, 'stdout' => '', 'stderr' => 'git_unavailable'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

/** @param array<string,mixed> $value */
function release2364Json(array $value, bool $pretty = true): string
{
    $sort = static function (array &$item) use (&$sort): void {
        if (!array_is_list($item)) {
            ksort($item, SORT_STRING);
        }
        foreach ($item as &$child) {
            if (is_array($child)) {
                $sort($child);
            }
        }
    };
    $sort($value);
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    return json_encode($value, $flags) . "\n";
}

/**
 * @param list<array{path:string,mode:string,object:string,size:int,sha256:string}> $entries
 * @param array<string,string> $bytes
 */
function release2364Zip(string $path, array $entries, array $bytes, int $timestamp, ?string $manifest = null): void
{
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    $zip = new ZipArchive();
    $open = false;
    try {
        if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('artifact_zip_create_failed');
        }
        $open = true;
        if ($manifest !== null) {
            if (!$zip->addFromString('update-manifest.json', $manifest)
                || !$zip->setMtimeName('update-manifest.json', $timestamp)
                || !$zip->setExternalAttributesName('update-manifest.json', ZipArchive::OPSYS_UNIX, 0100644 << 16)
            ) {
                throw new RuntimeException('update_manifest_zip_write_failed');
            }
        }
        foreach ($entries as $entry) {
            $name = $entry['path'];
            if (!array_key_exists($name, $bytes)
                || !$zip->addFromString($name, $bytes[$name])
                || !$zip->setMtimeName($name, $timestamp)
            ) {
                throw new RuntimeException('artifact_entry_write_failed:' . $name);
            }
            $mode = $entry['mode'] === '100755' ? 0100755 : 0100644;
            if (!$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16)) {
                throw new RuntimeException('artifact_mode_write_failed:' . $name);
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('artifact_zip_close_failed');
        }
        $open = false;

        $verify = new ZipArchive();
        if ($verify->open($temporary, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('artifact_zip_reopen_failed');
        }
        $expectedCount = count($entries) + ($manifest === null ? 0 : 1);
        if ($verify->numFiles !== $expectedCount) {
            $verify->close();
            throw new RuntimeException('artifact_entry_count_invalid');
        }
        $seen = [];
        for ($index = 0; $index < $verify->numFiles; $index++) {
            $stat = $verify->statIndex($index);
            $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            if ($name === '' || isset($seen[strtolower($name)])) {
                $verify->close();
                throw new RuntimeException('artifact_path_collision');
            }
            $seen[strtolower($name)] = true;
            $actual = $verify->getFromIndex($index);
            $expected = $name === 'update-manifest.json' && $manifest !== null ? $manifest : ($bytes[$name] ?? null);
            if (!is_string($actual) || !is_string($expected) || !hash_equals(hash('sha256', $expected), hash('sha256', $actual))) {
                $verify->close();
                throw new RuntimeException('artifact_entry_hash_invalid:' . $name);
            }
        }
        $verify->close();
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('artifact_replace_refused');
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('artifact_publish_failed');
        }
    } finally {
        if ($open) {
            @$zip->close();
        }
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

$arguments = [];
foreach (array_slice(is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], 1) as $argument) {
    if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $arguments[$name] = $value;
    }
}

try {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('zip_extension_missing');
    }
    $source = realpath((string) ($arguments['source'] ?? dirname(__DIR__)));
    $output = realpath((string) ($arguments['output-dir'] ?? ''));
    $ref = trim((string) ($arguments['ref'] ?? 'HEAD'));
    $baseRef = trim((string) ($arguments['base-ref'] ?? '07f4421f67ddd883e391457888a4be7b7062c186'));
    if ($source === false || $output === false || !is_dir($output) || !is_writable($output) || $ref === '' || $baseRef === '') {
        throw new RuntimeException('artifact_arguments_invalid');
    }
    $top = release2364Git($source, ['rev-parse', '--show-toplevel']);
    if ($top['exit'] !== 0 || realpath(trim($top['stdout'])) !== $source) {
        throw new RuntimeException('source_not_git_toplevel');
    }
    $headResult = release2364Git($source, ['rev-parse', '--verify', $ref]);
    $baseResult = release2364Git($source, ['rev-parse', '--verify', $baseRef]);
    $treeResult = release2364Git($source, ['show', '-s', '--format=%T', trim($headResult['stdout'])]);
    $commit = strtolower(trim($headResult['stdout']));
    $baseCommit = strtolower(trim($baseResult['stdout']));
    $tree = strtolower(trim($treeResult['stdout']));
    foreach ([$commit, $baseCommit, $tree] as $identity) {
        if (preg_match('/^[a-f0-9]{40}$/', $identity) !== 1) {
            throw new RuntimeException('git_identity_invalid');
        }
    }
    if (!hash_equals(ManagedRuntimePublicationPolicy::VERSION, trim(ManagedRuntimePublicationPolicy::gitBlob($source, $commit, 'VERSION')))) {
        throw new RuntimeException('release_version_invalid');
    }

    $entries = ManagedRuntimePublicationPolicy::packageEntries($source, $commit);
    $files = [];
    foreach ($entries as $entry) {
        $files[$entry['path']] = ManagedRuntimePublicationPolicy::gitBlob($source, $commit, $entry['path']);
    }
    $runtimeManifest = json_decode($files['resources/runtime-manifest.json'], true, 512, JSON_THROW_ON_ERROR);
    $issues = ManagedRuntimePublicationPolicy::packageIssues($source, $runtimeManifest, $files, $commit);
    if ($issues !== []) {
        throw new RuntimeException('package_authority_failed:' . implode(',', $issues));
    }

    $baseEntries = [];
    foreach (ManagedRuntimePublicationPolicy::packageEntries($source, $baseCommit) as $entry) {
        $baseEntries[$entry['path']] = $entry;
    }
    $currentEntries = [];
    $overlayEntries = [];
    foreach ($entries as $entry) {
        $currentEntries[$entry['path']] = $entry;
        if (!isset($baseEntries[$entry['path']]) || !hash_equals($baseEntries[$entry['path']]['sha256'], $entry['sha256'])) {
            $overlayEntries[] = $entry;
        }
    }
    $deletedRuntime = array_values(array_diff(array_keys($baseEntries), array_keys($currentEntries)));
    if ($deletedRuntime !== []) {
        throw new RuntimeException('ftp_overlay_cannot_represent_deletions:' . implode(',', $deletedRuntime));
    }

    $timestamp = (int) strtotime(ManagedRuntimePublicationPolicy::BUILT_AT);
    if ($timestamp <= 0) {
        throw new RuntimeException('release_timestamp_invalid');
    }
    $fullPath = $output . DIRECTORY_SEPARATOR . 'ERP_MELI_2.36.4_GIT_EXACT.zip';
    $overlayPath = $output . DIRECTORY_SEPARATOR . 'ERP_MELI_2.36.4_FTP_REPAIR_OVERLAY.zip';
    $updatePath = $output . DIRECTORY_SEPARATOR . 'ERP_MELI_2.36.4_UPDATE_PACKAGE.erpupd';
    release2364Zip($fullPath, $entries, $files, $timestamp);
    release2364Zip($overlayPath, $overlayEntries, $files, $timestamp);

    $fileManifest = array_map(static fn (array $entry): array => [
        'path' => $entry['path'], 'sha256' => $entry['sha256'], 'size' => $entry['size'],
    ], $entries);
    $updateManifest = [
        'manifest_version' => 1,
        'product_id' => 'erp-meli',
        'release_id' => 'erp-meli-2.36.4-managed-local',
        'version' => '2.36.4',
        'sequence' => 23604,
        'channel' => 'manual',
        'source_trust' => 'local_admin',
        'published_at' => ManagedRuntimePublicationPolicy::BUILT_AT,
        'expires_at' => '2099-12-31T23:59:59+00:00',
        'upgrade_from' => ['2.36.3'],
        'required_bridges' => [],
        'requirements' => [
            'php_min' => '8.3.0',
            'php_max_exclusive' => '8.6.0',
            'extensions' => ['curl', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'session', 'sodium'],
        ],
        'files' => $fileManifest,
        'migrations' => array_values(array_map('basename', array_filter(
            array_column($entries, 'path'),
            static fn (string $path): bool => preg_match('#^database/migrations/[^/]+\.sql$#', $path) === 1,
        ))),
        'health_checks' => ['bootstrap', 'front_controller', 'database', 'storage'],
        'rollback' => ['code_compatible' => true, 'database_restore_required' => false],
    ];
    $updateManifestBytes = release2364Json($updateManifest);
    release2364Zip($updatePath, $entries, $files, $timestamp, $updateManifestBytes);

    $overlayInventory = [
        'schema' => 'erp-meli-2363-ftp-overlay-v1',
        'version' => '2.36.4',
        'base_commit' => $baseCommit,
        'commit' => $commit,
        'tree' => $tree,
        'file_count' => count($overlayEntries),
        'files' => array_map(static fn (array $entry): array => [
            'path' => $entry['path'], 'mode' => $entry['mode'], 'size' => $entry['size'], 'sha256' => $entry['sha256'],
        ], $overlayEntries),
        'never_overwrite' => [
            'config.env', '.env', 'shared/config.env', 'storage/', 'shared/storage/',
            'PAUSE_MELI_API', 'PAUSE_ERP_AUTOMATION', 'shared/current-release.json',
        ],
    ];
    $inventoryPath = $output . DIRECTORY_SEPARATOR . 'ERP_MELI_2.36.4_FTP_REPAIR_OVERLAY_INVENTORY.json';
    file_put_contents($inventoryPath, release2364Json($overlayInventory));

    $instructionPath = $output . DIRECTORY_SEPARATOR . 'ERP_MELI_2.36.4_FTP_INSTRUCTIONS.md';
    $reportPath = $output . DIRECTORY_SEPARATOR . 'ERP_MELI_2.36.4_SAFE_CONFIG_HOTFIX_REPORT.md';
    file_put_contents($instructionPath, ManagedRuntimePublicationPolicy::gitBlob($source, $commit, 'docs/release_2364_safe_config.md'));
    file_put_contents($reportPath, ManagedRuntimePublicationPolicy::gitBlob(
        $source,
        $commit,
        'docs/ERP_MELI_2.36.4_SAFE_CONFIG_HOTFIX_REPORT.md',
    ));

    $artifacts = [];
    foreach ([$fullPath, $overlayPath, $updatePath, $inventoryPath, $instructionPath, $reportPath] as $path) {
        $artifacts[basename($path)] = ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }
    $authority = [
        'schema' => 'erp-meli-2363-artifacts-v1',
        'source' => 'GIT_OBJECT_DATABASE',
        'commit' => $commit,
        'tree' => $tree,
        'base_commit' => $baseCommit,
        'version' => '2.36.4',
        'runtime_files' => count($entries),
        'runtime_components' => count((array) ($runtimeManifest['components'] ?? [])),
        'overlay_files' => count($overlayEntries),
        'artifacts' => $artifacts,
    ];
    $authorityPath = $output . DIRECTORY_SEPARATOR . 'ERP_MELI_2.36.4_ARTIFACT_MANIFEST.json';
    file_put_contents($authorityPath, release2364Json($authority));
    $artifacts[basename($authorityPath)] = ['bytes' => filesize($authorityPath), 'sha256' => hash_file('sha256', $authorityPath)];
    $sumsPath = $output . DIRECTORY_SEPARATOR . 'ERP_MELI_2.36.4_SHA256SUMS.txt';
    $sumLines = [];
    foreach ($artifacts as $name => $definition) {
        $sumLines[] = $definition['sha256'] . '  ' . $name;
    }
    sort($sumLines, SORT_STRING);
    file_put_contents($sumsPath, implode("\n", $sumLines) . "\n");
    $artifacts[basename($sumsPath)] = ['bytes' => filesize($sumsPath), 'sha256' => hash_file('sha256', $sumsPath)];
    fwrite(STDOUT, release2364Json([
        'ok' => true,
        'commit' => $commit,
        'tree' => $tree,
        'runtime_files' => count($entries),
        'overlay_files' => count($overlayEntries),
        'artifacts' => $artifacts,
    ], false));
} catch (Throwable $error) {
    fwrite(STDERR, 'Artifact build 2.36.4: FAIL ' . $error->getMessage() . "\n");
    exit(1);
}
