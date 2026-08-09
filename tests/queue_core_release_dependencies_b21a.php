<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (array $arguments) use ($root): array {
    $process = proc_open(array_merge([PHP_BINARY, $root . '/bin/queue_core_dependency_check.php'], $arguments), [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('dependency scanner unavailable');
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $json = json_decode((string) $stdout, true);

    return ['exit' => $exit, 'json' => $json, 'stderr' => (string) $stderr];
};

$authority = json_decode((string) file_get_contents(
    $root . '/resources/release/queue-core-runtime-dependencies.json'
), true, 512, JSON_THROW_ON_ERROR);
$snapshot = $authority['blocked_rc_snapshot'] ?? [];
$target = (string) ($snapshot['commit'] ?? '');
$assert(($snapshot['expected_changed_paths'] ?? null) === 142, 'audited path count drifted');
$assert(($snapshot['expected_class_counts']['RUNTIME_REQUIRED'] ?? null) === 113,
    'runtime classification count drifted');
$assert(($snapshot['expected_class_counts']['MIGRATION_DEPLOY_REQUIRED'] ?? null) === 14,
    'migration classification count drifted');
$assert(!array_key_exists('target_commit', $authority), 'final target must not be pinned in the registry');
$assert(array_keys($authority['classifications'] ?? []) === [
    'RUNTIME_REQUIRED',
    'RUNTIME_OPTIONAL_FAIL_CLOSED',
    'MIGRATION_DEPLOY_REQUIRED',
    'RELEASE_BUILD_INPUT',
    'TEST_ONLY',
    'DEVELOPMENT_TOOL',
    'DOCUMENTATION',
    'FRONTEND_STATIC',
    'OTHER_EXPLICIT',
], 'classification vocabulary is not contractual');

$dependencies = array_column($authority['runtime_dependencies'] ?? [], null, 'id');
$topics = $dependencies['notification-topics'] ?? null;
$assert(is_array($topics) && ($topics['classification'] ?? null) === 'RUNTIME_REQUIRED',
    'notification topics are not runtime-required');
$assert(!empty($topics['consumers']) && !empty($topics['provenance']),
    'notification topics lack consumer/provenance authority');

$classification = $run(['--json', '--classification-only', '--target=' . $target]);
$assert($classification['exit'] === 0 && ($classification['json']['ok'] ?? false) === true,
    'the 142-path classification authority is incomplete: ' . json_encode($classification));
$assert(($classification['json']['classification_counts'] ?? []) === [
    'MIGRATION_DEPLOY_REQUIRED' => 14,
    'RELEASE_BUILD_INPUT' => 4,
    'RUNTIME_REQUIRED' => 113,
    'TEST_ONLY' => 11,
], 'classification counts differ from the frozen RC delta');
$assert(($classification['json']['STATIC_RUNTIME_UNREGISTERED'] ?? null) === [],
    'static runtime dependency discovery found an unregistered path');
$assert(($classification['json']['DYNAMIC_RUNTIME_PATHS_UNBOUNDED'] ?? null) === [],
    'dynamic runtime path scanner found an unbounded loader');
$discovered = array_column($classification['json']['STATIC_RUNTIME_DEPENDENCIES_DISCOVERED'] ?? [], 'path');
$assert(in_array('resources/mercadolibre-api/generated/notification-topics.json', $discovered, true),
    'static scanner did not independently discover notification-topics.json');

$manifest = $run(['--json', '--target=' . $target]);
$assert($manifest['exit'] === 1 && ($manifest['json']['ok'] ?? true) === false,
    'the known runtime dependency blocker was not reproduced');
$assert(($manifest['json']['runtime_manifest_missing'] ?? []) ===
    ['resources/mercadolibre-api/generated/notification-topics.json'],
    'the blocker is not isolated to notification-topics.json');
$assert(($manifest['json']['runtime_manifest_orphaned_delta'] ?? null) === [],
    'the frozen manifest contains an orphaned changed component');

fwrite(STDOUT, "Queue Core B2.1A dependency authority: {$checks} checks passed\n");
