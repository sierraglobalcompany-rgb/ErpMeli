<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/ManagedRuntimePublicationPolicy.php';

use App\Services\ManagedRuntimePublicationPolicy;

function rpAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<string> $arguments */
function rpGit(string $root, array $arguments): string
{
    $pipes = [];
    $process = proc_open(array_merge(['git', '-C', $root], $arguments), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('git unavailable');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('git failed: ' . trim((string) $err));
    }
    return trim((string) $out);
}

function rpWrite(string $root, string $path, string $bytes): void
{
    $absolute = $root . '/' . $path;
    if (!is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0777, true);
    }
    file_put_contents($absolute, $bytes);
}

$root = sys_get_temp_dir() . '/erp-runtime-policy-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
try {
    rpGit($root, ['init', '--quiet']);
    rpGit($root, ['config', 'user.email', 'runtime-policy@example.invalid']);
    rpGit($root, ['config', 'user.name', 'Runtime Policy Test']);
    foreach ([
        'VERSION', 'asset.php', 'bootstrap.php', 'index.php', 'login.php', 'actualizar.php',
        'stop.php', 'mantenimiento.php', 'recuperar.php', 'cron-status.php', 'launcher/entrypoint.php',
        'public/index.php', 'resources/runtime-manifest.json', 'app/Test.php',
        'bin/create_admin.php', 'bin/database_growth_audit.php', 'bin/database_physical_recovery.php',
        'bin/db_explain_audit.php', 'bin/meli_api_audit.php', 'bin/migrate.php',
        'bin/query_performance_report.php', 'bin/queue_core_dependency_check.php',
        'bin/runtime_process_audit.php',
    ] as $path) {
        rpWrite($root, $path, $path === 'VERSION' ? "2.36.9\n" : "<?php // {$path}\n");
    }
    rpWrite($root, 'config.env.example', "EXAMPLE=1\n");
    rpWrite($root, 'resources/mercadolibre-api/generated/data.json', "{}\n");
    $registry = [
        'schema_version' => 1,
        'runtime_manifest_paths_sha256' => hash('sha256', implode("\n", [
            'VERSION',
            'actualizar.php',
            'app/Test.php',
            'asset.php',
            'bin/create_admin.php',
            'bin/database_growth_audit.php',
            'bin/database_physical_recovery.php',
            'bin/db_explain_audit.php',
            'bin/meli_api_audit.php',
            'bin/migrate.php',
            'bin/query_performance_report.php',
            'bin/queue_core_dependency_check.php',
            'bin/runtime_process_audit.php',
            'bootstrap.php',
            'cron-status.php',
            'index.php',
            'launcher/entrypoint.php',
            'login.php',
            'mantenimiento.php',
            'public/index.php',
            'recuperar.php',
            'resources/mercadolibre-api/generated/data.json',
            'resources/release/managed-runtime-dependencies-2.36.9.json',
            'stop.php',
        ]) . "\n"),
        'classification_rules' => [
            ['id' => 'runtime-files', 'classification' => 'RUNTIME_REQUIRED', 'kind' => 'regex',
                'value' => '#^(?:app/.*\\.php|resources/(?:mercadolibre-api/generated/data\\.json|release/managed-runtime-dependencies-2\\.36\\.9\\.json))$#D'],
        ],
        'runtime_dependencies' => [
            ['id' => 'registry', 'path' => 'resources/release/managed-runtime-dependencies-2.36.9.json',
                'classification' => 'RUNTIME_REQUIRED', 'required_in_runtime_manifest' => true,
                'consumers' => [['source_path' => 'app/Test.php', 'symbol' => 'test', 'path_literal' => 'registry']],
                'provenance' => ['kind' => 'generated']],
            ['id' => 'data', 'path' => 'resources/mercadolibre-api/generated/data.json', 'classification' => 'RUNTIME_REQUIRED',
                'required_in_runtime_manifest' => true,
                'consumers' => [['source_path' => 'app/Test.php', 'symbol' => 'test', 'path_literal' => 'data']],
                'provenance' => ['kind' => 'source']],
        ],
    ];
    rpWrite($root, 'resources/release/managed-runtime-dependencies-2.36.9.json',
        json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    rpGit($root, ['add', '.']);
    rpGit($root, ['commit', '--quiet', '-m', 'base']);
    $base = rpGit($root, ['rev-parse', 'HEAD']);

    rpWrite($root, 'app/Test.php', "<?php // changed\r\n");
    rpGit($root, ['add', 'app/Test.php']);
    rpGit($root, ['commit', '--quiet', '-m', 'head']);
    $head = rpGit($root, ['rev-parse', 'HEAD']);
    $manifest = ManagedRuntimePublicationPolicy::buildManifest($root, $head, $base);
    rpAssert(ManagedRuntimePublicationPolicy::manifestIssues($root, $manifest, $head, $base) === [], 'valid manifest rejected');
    rpAssert(ManagedRuntimePublicationPolicy::installedManifestIssues($root, $manifest) === [], 'installed completeness rejected');

    $missing = $manifest;
    unset($missing['components']['runtime_resources_mercadolibre_api_generated_data_json']);
    rpAssert((bool) array_filter(ManagedRuntimePublicationPolicy::manifestIssues($root, $missing, $head, $base),
        static fn (string $issue): bool => str_starts_with($issue, 'manifest_component_missing:')), 'missing not detected');
    rpAssert((bool) array_filter(ManagedRuntimePublicationPolicy::installedManifestIssues($root, $missing),
        static fn (string $issue): bool => str_starts_with($issue, 'manifest_required_dependency_missing:')),
        'installed readiness accepted an unmanifested runtime dependency');

    $partial = $manifest;
    unset($partial['components']['runtime_app_test_php']);
    $partialPaths = array_values(array_map(
        static fn (array $component): string => (string) $component['path'],
        $partial['components'],
    ));
    sort($partialPaths, SORT_STRING);
    $partial['publication_policy']['component_count'] = count($partialPaths);
    $partial['publication_policy']['paths_sha256'] = hash('sha256', implode("\n", $partialPaths) . "\n");
    rpAssert(in_array('manifest_installed_inventory_mismatch',
        ManagedRuntimePublicationPolicy::installedManifestIssues($root, $partial), true),
        'installed readiness accepted a self-consistent partial manifest');

    $stale = $manifest;
    $stale['components']['runtime_app_test_php']['sha256'] = str_repeat('0', 64);
    rpAssert(in_array('manifest_raw_hash_mismatch:app/Test.php',
        ManagedRuntimePublicationPolicy::manifestIssues($root, $stale, $head, $base), true), 'stale hash not detected');

    $orphan = $manifest;
    $orphan['components']['runtime_config_env_example'] = [
        'path' => 'config.env.example', 'sha256' => hash('sha256', "EXAMPLE=1\n"),
        'sha256_lf' => hash('sha256', "EXAMPLE=1\n"), 'text' => true,
    ];
    rpAssert(in_array('manifest_component_orphan:config.env.example',
        ManagedRuntimePublicationPolicy::manifestIssues($root, $orphan, $head, $base), true), 'orphan not detected');

    $case = $manifest;
    $case['components']['case_collision'] = $case['components']['runtime_app_test_php'];
    $case['components']['case_collision']['path'] = 'APP/Test.php';
    rpAssert((bool) array_filter(ManagedRuntimePublicationPolicy::manifestIssues($root, $case, $head, $base),
        static fn (string $issue): bool => str_starts_with($issue, 'manifest_path_case_collision:')), 'case collision not detected');

    $workingHashBefore = hash_file('sha256', $root . '/app/Test.php');
    rpWrite($root, 'app/Test.php', "<?php // uncommitted mismatch\n");
    $entry = array_values(array_filter(ManagedRuntimePublicationPolicy::packageEntries($root, $head),
        static fn (array $row): bool => $row['path'] === 'app/Test.php'))[0] ?? null;
    rpAssert(is_array($entry) && $entry['sha256'] === $manifest['components']['runtime_app_test_php']['sha256'],
        'package did not use exact Git blob');
    rpAssert(hash_file('sha256', $root . '/app/Test.php') !== $workingHashBefore, 'working mismatch fixture failed');

    rpWrite($root, 'link-target.txt', "../../escape\n");
    $linkBlob = rpGit($root, ['hash-object', '-w', 'link-target.txt']);
    rpGit($root, ['update-index', '--add', '--cacheinfo', '120000,' . $linkBlob . ',app/Escape.php']);
    rpGit($root, ['commit', '--quiet', '-m', 'unsafe symlink fixture']);
    $symlinkHead = rpGit($root, ['rev-parse', 'HEAD']);
    $symlinkRejected = false;
    try {
        ManagedRuntimePublicationPolicy::packageEntries($root, $symlinkHead);
    } catch (RuntimeException $exception) {
        $symlinkRejected = str_contains($exception->getMessage(), 'unsafe Git type/mode');
    }
    rpAssert($symlinkRejected, 'A runtime symlink escaped package policy.');

    fwrite(STDOUT, "runtime_publication_policy_b21: PASS\n");
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @chmod($entry->getPathname(), 0777);
            @rmdir($entry->getPathname());
        } else {
            @chmod($entry->getPathname(), 0666);
            @unlink($entry->getPathname());
        }
    }
    @rmdir($root);
}
