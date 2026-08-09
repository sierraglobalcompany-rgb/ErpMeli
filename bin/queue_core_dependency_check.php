<?php

declare(strict_types=1);

/** @return array{exit:int,stdout:string,stderr:string} */
function runGit(string $root, array $arguments): array
{
    $process = proc_open(array_merge(['git', '-C', $root], $arguments), [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        return ['exit' => 127, 'stdout' => '', 'stderr' => 'git_unavailable'];
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

/** @param array<string,mixed> $rule */
function ruleMatches(array $rule, string $path): bool
{
    $kind = (string) ($rule['kind'] ?? '');
    if ($kind === 'exact') {
        return in_array($path, is_array($rule['values'] ?? null) ? $rule['values'] : [], true);
    }
    if ($kind === 'regex') {
        return @preg_match((string) ($rule['value'] ?? ''), $path) === 1;
    }

    return false;
}

/** @return list<string> */
function changedPaths(string $root, string $base, string $target, array &$issues): array
{
    $diff = runGit($root, ['diff', '--name-only', $base, $target]);
    if ($diff['exit'] !== 0) {
        $issues[] = 'git_delta_unavailable';

        return [];
    }
    $paths = preg_split('/\R/', trim($diff['stdout'])) ?: [];
    $paths = array_values(array_filter(array_map(
        static fn (string $path): string => str_replace('\\', '/', trim($path)),
        $paths
    ), static fn (string $path): bool => $path !== ''));
    sort($paths, SORT_STRING);

    return $paths;
}

/** @return list<string> */
function manifestPaths(array $manifest): array
{
    $paths = [];
    foreach (is_array($manifest['components'] ?? null) ? $manifest['components'] : [] as $component) {
        if (is_array($component) && is_string($component['path'] ?? null)) {
            $paths[] = str_replace('\\', '/', $component['path']);
        }
    }

    $paths = array_values(array_unique($paths));
    sort($paths, SORT_STRING);

    return $paths;
}

/** @return array<string,string> */
function targetSources(string $root, string $target, array $classified, array $registry, array &$issues): array
{
    $paths = [];
    foreach ($classified as $path => $classification) {
        if ($classification === 'RUNTIME_REQUIRED' && str_ends_with($path, '.php')) {
            $paths[] = $path;
        }
    }
    foreach (is_array($registry['runtime_dependencies'] ?? null) ? $registry['runtime_dependencies'] : [] as $dependency) {
        if (!is_array($dependency)) {
            continue;
        }
        $dependencyPath = (string) ($dependency['path'] ?? '');
        if ($dependencyPath === '' || runGit($root, ['show', $target . ':' . $dependencyPath])['exit'] !== 0) {
            continue;
        }
        foreach (is_array($dependency['consumers'] ?? null) ? $dependency['consumers'] : [] as $consumer) {
            if (is_array($consumer) && is_string($consumer['source_path'] ?? null)) {
                $paths[] = $consumer['source_path'];
            }
        }
    }
    $sources = [];
    foreach (array_values(array_unique($paths)) as $path) {
        $blob = runGit($root, ['show', $target . ':' . $path]);
        if ($blob['exit'] !== 0) {
            $issues[] = 'runtime_source_unavailable:' . $path;
            continue;
        }
        $sources[$path] = $blob['stdout'];
    }
    ksort($sources, SORT_STRING);

    return $sources;
}

/**
 * @param array<string,string> $sources
 * @return list<array{path:string,source_paths:list<string>}>
 */
function discoverStaticRuntimeDependencies(array $sources, array $operations, array $registeredPaths = []): array
{
    $found = [];
    $operationPattern = implode('|', array_map(static fn (string $value): string => preg_quote($value, '#'), $operations));
    foreach ($sources as $sourcePath => $source) {
        $lines = preg_split('/\R/', $source) ?: [];
        foreach ($lines as $index => $line) {
            if (preg_match_all('#resources/[A-Za-z0-9._/*?-]+#', $line, $matches) !== 1 && empty($matches[0])) {
                continue;
            }
            $start = max(0, $index - 2);
            $context = implode("\n", array_slice($lines, $start, 5));
            if (preg_match('#\b(?:' . $operationPattern . ')\b#', $context) !== 1) {
                continue;
            }
            foreach ($matches[0] as $path) {
                if (strpbrk((string) $path, '*?') !== false) {
                    continue;
                }
                $path = rtrim((string) $path, './');
                if ($path === '') {
                    continue;
                }
                $found[$path] ??= [];
                $found[$path][] = $sourcePath;
            }
        }
        if (preg_match('#\b(?:' . $operationPattern . ')\b#', $source) === 1
            && preg_match_all('#resources/[A-Za-z0-9._/*?-]+#', $source, $sourceMatches) > 0
        ) {
            foreach ($sourceMatches[0] as $path) {
                $path = rtrim((string) $path, './');
                if ($path === '' || strpbrk($path, '*?') !== false || !isset($registeredPaths[$path])) {
                    continue;
                }
                $found[$path] ??= [];
                $found[$path][] = $sourcePath;
            }
        }
    }
    ksort($found, SORT_STRING);
    $result = [];
    foreach ($found as $path => $sourcePaths) {
        $sourcePaths = array_values(array_unique($sourcePaths));
        sort($sourcePaths, SORT_STRING);
        $result[] = ['path' => $path, 'source_paths' => $sourcePaths];
    }

    return $result;
}

/**
 * @param array<string,string> $sources
 * @param list<array<string,mixed>> $policies
 * @return list<array{source_path:string,line:int,operation:string}>
 */
function unboundedDynamicRuntimePaths(array $sources, array $operations, array $policies): array
{
    $operationPattern = implode('|', array_map(static fn (string $value): string => preg_quote($value, '#'), $operations));
    $unbounded = [];
    foreach ($sources as $sourcePath => $source) {
        foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
            if (preg_match_all('#\b(' . $operationPattern . ')\b#', $line, $matches) < 1) {
                continue;
            }
            foreach ($matches[1] as $operation) {
                $bounded = false;
                foreach ($policies as $policy) {
                    if (@preg_match((string) ($policy['source_regex'] ?? ''), $sourcePath) !== 1
                        || !in_array($operation, is_array($policy['operations'] ?? null) ? $policy['operations'] : [], true)
                        || trim((string) ($policy['bounded_by'] ?? '')) === ''
                        || trim((string) ($policy['data_kind'] ?? '')) === '') {
                        continue;
                    }
                    $bounded = true;
                    break;
                }
                if (!$bounded) {
                    $unbounded[] = [
                        'source_path' => $sourcePath,
                        'line' => $index + 1,
                        'operation' => $operation,
                    ];
                }
            }
        }
    }

    return $unbounded;
}

$root = dirname(__DIR__);
$options = getopt('', ['json', 'classification-only', 'target:', 'authority:']);
$target = trim((string) ($options['target'] ?? 'HEAD'));
$authority = trim((string) ($options['authority'] ?? $target));
$registryResult = runGit($root, [
    'show', $authority . ':resources/release/managed-runtime-dependencies-2.36.1.json',
]);
$registry = $registryResult['exit'] === 0 ? json_decode($registryResult['stdout'], true) : null;
$issues = [];
if (!is_array($registry)) {
    $issues[] = 'dependency_registry_invalid';
    $registry = [];
}
$base = (string) ($registry['coverage_base_commit'] ?? '');
$paths = changedPaths($root, $base, $target, $issues);

$rules = is_array($registry['classification_rules'] ?? null) ? $registry['classification_rules'] : [];
$knownClassifications = array_keys(is_array($registry['classifications'] ?? null) ? $registry['classifications'] : []);
$classified = [];
$counts = [];
foreach ($paths as $path) {
    $matches = [];
    foreach ($rules as $rule) {
        if (is_array($rule) && ruleMatches($rule, $path)) {
            $matches[] = $rule;
        }
    }
    if (count($matches) !== 1) {
        $issues[] = (count($matches) === 0 ? 'unclassified:' : 'ambiguous:') . $path;
        continue;
    }
    $classification = (string) ($matches[0]['classification'] ?? '');
    if (!in_array($classification, $knownClassifications, true)) {
        $issues[] = 'unknown_classification:' . $path;
        continue;
    }
    $classified[$path] = $classification;
    $counts[$classification] = ($counts[$classification] ?? 0) + 1;
}
ksort($classified, SORT_STRING);
ksort($counts, SORT_STRING);

$snapshot = is_array($registry['blocked_rc_snapshot'] ?? null) ? $registry['blocked_rc_snapshot'] : [];
if ($target === (string) ($snapshot['commit'] ?? '')) {
    if (count($paths) !== (int) ($snapshot['expected_changed_paths'] ?? -1)) {
        $issues[] = 'blocked_rc_changed_path_count_mismatch';
    }
    if (!hash_equals((string) ($snapshot['sorted_path_set_sha256'] ?? ''), hash('sha256', implode("\n", $paths)))) {
        $issues[] = 'blocked_rc_changed_path_set_mismatch';
    }
    $expected = is_array($snapshot['expected_class_counts'] ?? null) ? $snapshot['expected_class_counts'] : [];
    ksort($expected, SORT_STRING);
    if ($counts !== $expected) {
        $issues[] = 'blocked_rc_classification_count_mismatch';
    }
}

$manifestResult = runGit($root, ['show', $target . ':resources/runtime-manifest.json']);
$manifest = $manifestResult['exit'] === 0 ? json_decode($manifestResult['stdout'], true) : null;
if (!is_array($manifest)) {
    $issues[] = 'runtime_manifest_unavailable';
    $manifest = [];
}
$manifestPaths = manifestPaths($manifest);
if ($authority === $target
    && !hash_equals(
        (string) ($registry['runtime_manifest_paths_sha256'] ?? ''),
        hash('sha256', implode("\n", $manifestPaths) . "\n"),
    )
) {
    $issues[] = 'runtime_manifest_inventory_contract_mismatch';
}
$manifestRequiredClasses = ['RUNTIME_REQUIRED', 'MIGRATION_DEPLOY_REQUIRED', 'FRONTEND_STATIC'];
$runtimeMissing = [];
foreach ($classified as $path => $classification) {
    if (in_array($classification, $manifestRequiredClasses, true) && !in_array($path, $manifestPaths, true)) {
        $runtimeMissing[] = $path;
    }
}
$runtimeManifestOrphanedDelta = [];
$fullManagedRuntime = ($manifest['publication_policy']['authority_model'] ?? null) === 'FULL_MANAGED_RUNTIME';
foreach ($manifestPaths as $path) {
    if (!$fullManagedRuntime
        && isset($classified[$path])
        && !in_array($classified[$path], $manifestRequiredClasses, true)
    ) {
        $runtimeManifestOrphanedDelta[] = $path;
    }
}
sort($runtimeMissing, SORT_STRING);
sort($runtimeManifestOrphanedDelta, SORT_STRING);

$dependenciesByPath = [];
$consumerIssues = [];
foreach (is_array($registry['runtime_dependencies'] ?? null) ? $registry['runtime_dependencies'] : [] as $dependency) {
    if (!is_array($dependency) || !is_string($dependency['path'] ?? null)) {
        $issues[] = 'runtime_dependency_invalid';
        continue;
    }
    $dependencyPath = str_replace('\\', '/', $dependency['path']);
    if (runGit($root, ['show', $target . ':' . $dependencyPath])['exit'] !== 0) {
        continue;
    }
    $dependenciesByPath[$dependencyPath] = $dependency;
    if (($dependency['required_in_runtime_manifest'] ?? false) === true && !in_array($dependencyPath, $manifestPaths, true)) {
        $runtimeMissing[] = $dependencyPath;
    }
    foreach (is_array($dependency['consumers'] ?? null) ? $dependency['consumers'] : [] as $consumer) {
        if (!is_array($consumer)) {
            $consumerIssues[] = (string) ($dependency['id'] ?? 'unknown') . ':invalid_consumer';
            continue;
        }
        $sourcePath = (string) ($consumer['source_path'] ?? '');
        $literal = (string) ($consumer['path_literal'] ?? '');
        $source = runGit($root, ['show', $target . ':' . $sourcePath]);
        if ($source['exit'] !== 0 || $literal === '' || !str_contains($source['stdout'], $literal)) {
            $consumerIssues[] = (string) ($dependency['id'] ?? 'unknown') . ':' . $sourcePath;
        }
    }
    if (!isset($dependency['provenance'])
        || !is_array($dependency['provenance'])
        || $dependency['provenance'] === []) {
        $issues[] = 'runtime_dependency_provenance_missing:' . $dependencyPath;
    }
}
$runtimeMissing = array_values(array_unique($runtimeMissing));
sort($runtimeMissing, SORT_STRING);
foreach ($consumerIssues as $consumerIssue) {
    $issues[] = 'consumer_provenance_missing:' . $consumerIssue;
}

$sources = targetSources($root, $target, $classified, $registry, $issues);
$scan = is_array($registry['static_scan'] ?? null) ? $registry['static_scan'] : [];
$operations = is_array($scan['loader_operations'] ?? null) ? array_values($scan['loader_operations']) : [];
$staticDiscovered = discoverStaticRuntimeDependencies(
    $sources,
    $operations,
    array_fill_keys(array_keys($dependenciesByPath), true),
);
$staticUnregistered = [];
foreach ($staticDiscovered as $discovered) {
    if (!isset($dependenciesByPath[$discovered['path']])) {
        $staticUnregistered[] = $discovered;
    }
}
$dynamicPolicies = is_array($scan['dynamic_path_policies'] ?? null) ? $scan['dynamic_path_policies'] : [];
$dynamicUnbounded = unboundedDynamicRuntimePaths($sources, $operations, $dynamicPolicies);
foreach ($staticUnregistered as $dependency) {
    $issues[] = 'static_runtime_unregistered:' . $dependency['path'];
}
foreach ($dynamicUnbounded as $dependency) {
    $issues[] = 'dynamic_runtime_path_unbounded:' . $dependency['source_path'] . ':' . $dependency['line'];
}

if (!array_key_exists('classification-only', $options)) {
    foreach ($runtimeMissing as $path) {
        $issues[] = 'runtime_manifest_missing:' . $path;
    }
    foreach ($runtimeManifestOrphanedDelta as $path) {
        $issues[] = 'runtime_manifest_orphaned_delta:' . $path;
    }
}
$issues = array_values(array_unique($issues));
sort($issues, SORT_STRING);
$result = [
    'ok' => $issues === [],
    'authority_id' => (string) ($registry['authority_id'] ?? ''),
    'base' => $base,
    'target' => $target,
    'authority' => $authority,
    'changed_paths' => count($paths),
    'classification_counts' => $counts,
    'runtime_manifest_missing' => $runtimeMissing,
    'runtime_manifest_orphaned_delta' => $runtimeManifestOrphanedDelta,
    'STATIC_RUNTIME_DEPENDENCIES_DISCOVERED' => $staticDiscovered,
    'STATIC_RUNTIME_UNREGISTERED' => $staticUnregistered,
    'DYNAMIC_RUNTIME_PATHS_UNBOUNDED' => $dynamicUnbounded,
    'issues' => $issues,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($issues === [] ? 0 : 1);
