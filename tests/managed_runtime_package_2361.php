<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/MeliNotificationTopicRegistry.php';
require dirname(__DIR__) . '/app/Services/ManagedRuntimePublicationPolicy.php';

use App\Services\ManagedRuntimePublicationPolicy;

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tree = ManagedRuntimePublicationPolicy::gitTree($root);
$entries = ManagedRuntimePublicationPolicy::packageEntries($root);
$paths = array_column($entries, 'path');
$pathSet = array_fill_keys($paths, true);
$classCounts = [];
foreach (array_keys($tree) as $path) {
    $classification = ManagedRuntimePublicationPolicy::trackedPathClassification($path);
    $classCounts[$classification] = ($classCounts[$classification] ?? 0) + 1;
    $assert($classification !== 'UNCLASSIFIED', 'Unclassified tracked path: ' . $path);
    $assert($classification !== 'PROTECTED_EXTERNAL_STATE', 'Tracked protected state: ' . $path);
    $mustPackage = in_array($classification, ['MANAGED_RUNTIME', 'MIGRATION'], true);
    $assert(isset($pathSet[$path]) === $mustPackage, 'Package classification mismatch: ' . $path);
}

foreach ([
    'VERSION', 'cron-status.php', 'actualizar.php', 'launcher/entrypoint.php',
    'public/index.php', 'public/assets/app.css', 'resources/runtime-manifest.json',
    'resources/mercadolibre-api/generated/endpoints.json',
    'resources/release/queue-core-runtime-dependencies.json',
    'resources/release/managed-runtime-dependencies-2.36.1.json',
    'bin/create_admin.php', 'bin/database_growth_audit.php', 'bin/database_physical_recovery.php',
    'bin/db_explain_audit.php', 'bin/meli_api_audit.php', 'bin/migrate.php',
    'bin/query_performance_report.php', 'bin/queue_core_dependency_check.php',
    'bin/runtime_process_audit.php',
] as $required) {
    $assert(isset($pathSet[$required]), 'Required managed runtime missing: ' . $required);
}
foreach ([
    '.gitattributes', '.gitignore', 'config.env.example', 'phpstan.neon',
    'bin/build_managed_runtime_release.php', 'tests/managed_runtime_package_2361.php',
] as $excluded) {
    $assert(!isset($pathSet[$excluded]), 'Build/test-only path entered package: ' . $excluded);
}

$assert(ManagedRuntimePublicationPolicy::trackedPathClassification('config.env') === 'PROTECTED_EXTERNAL_STATE',
    'config.env is not protected external state');
$assert(ManagedRuntimePublicationPolicy::trackedPathClassification('.env') === 'PROTECTED_EXTERNAL_STATE',
    '.env is not protected external state');
$assert(ManagedRuntimePublicationPolicy::trackedPathClassification('storage/logs/app.log') === 'PROTECTED_EXTERNAL_STATE',
    'mutable storage is not protected external state');
$assert(ManagedRuntimePublicationPolicy::trackedPathClassification('PAUSE_MELI_API') === 'PROTECTED_EXTERNAL_STATE',
    'emergency marker is not protected external state');
$assert(ManagedRuntimePublicationPolicy::trackedPathClassification(
    'resources/mercadolibre-api/source/mercadolibre-api-index.json'
) === 'NON_RUNTIME', 'Mercado Libre source index entered runtime authority');
$assert(ManagedRuntimePublicationPolicy::trackedPathClassification(
    'resources/mercadolibre-api/source/mercadolibre-api-snapshot.json'
) === 'NON_RUNTIME', 'Mercado Libre source snapshot entered runtime authority');

$manifest = json_decode(
    ManagedRuntimePublicationPolicy::gitBlob($root, 'HEAD', 'resources/runtime-manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$files = [];
foreach ($entries as $entry) {
    $files[$entry['path']] = ManagedRuntimePublicationPolicy::gitBlob($root, 'HEAD', $entry['path']);
}
$assert(ManagedRuntimePublicationPolicy::packageIssues($root, $manifest, $files) === [],
    'Current Git-exact managed package fails attestation');
$assert(($manifest['publication_policy']['authority_model'] ?? null) === 'FULL_MANAGED_RUNTIME',
    'Manifest does not declare full managed runtime authority');
$assert(count((array) ($manifest['components'] ?? [])) === count($entries) - 1,
    'Manifest does not attest every non-self package member');

$temporaryOutput = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'erp-meli-2.36.1-managed-' . bin2hex(random_bytes(6)) . '.zip';
$process = proc_open([
    PHP_BINARY,
    $root . '/bin/build_managed_runtime_release.php',
    '--source=' . $root,
    '--ref=HEAD',
    '--output=' . $temporaryOutput,
], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
if (!is_resource($process)) {
    throw new RuntimeException('Managed package builder unavailable.');
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
try {
    $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    $assert($exit === 0 && ($result['ok'] ?? false) === true,
        'Managed package builder failed: ' . trim((string) $stderr));
    $assert(($result['source'] ?? null) === 'GIT_OBJECT_DATABASE', 'Builder did not use Git objects');
    $assert(($result['files'] ?? null) === count($entries), 'Builder file count drifted');
    $assert(is_file($temporaryOutput) && filesize($temporaryOutput) > 0, 'Builder produced no archive');
    $assert(hash_equals((string) ($result['sha256'] ?? ''), (string) hash_file('sha256', $temporaryOutput)),
        'Builder artifact hash report is false');
} finally {
    if (is_file($temporaryOutput)) {
        unlink($temporaryOutput);
    }
}

fwrite(STDOUT, 'Managed runtime package 2.36.1: ' . $checks . ' checks passed; files='
    . count($entries) . '; classes=' . json_encode($classCounts, JSON_UNESCAPED_SLASHES) . "\n");
