<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$backup = $argv[1] ?? 'C:/codex/meli backup';
$output = $argv[2] ?? '';

require $root . '/app/Services/RuntimePublicationPolicy.php';
require $root . '/app/Services/MeliNotificationTopicRegistry.php';
require $root . '/app/Services/ManagedRuntimePublicationPolicy.php';
require $root . '/app/Services/ReleaseIntegrityService.php';

use App\Services\ManagedRuntimePublicationPolicy;
use App\Services\ReleaseIntegrityService;
use App\Services\RuntimePublicationPolicy;

$prefix = 'erp-meli-2363-mix-';
$temporary = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
$forbidden = [
    str_replace('\\', '/', rtrim($backup, '/\\') . '/storage/raw'),
    str_replace('\\', '/', rtrim($backup, '/\\') . '/shared/storage/raw'),
];
$assertAllowed = static function (string $path) use ($forbidden): void {
    $normalized = str_replace('\\', '/', $path);
    foreach ($forbidden as $raw) {
        if ($normalized === $raw || str_starts_with($normalized, $raw . '/')) {
            throw new RuntimeException('forbidden_raw_path_access');
        }
    }
};
$write = static function (string $directory, string $path, string $bytes): void {
    $target = $directory . '/' . $path;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0770, true) && !is_dir(dirname($target))) {
        throw new RuntimeException('matrix_directory_create_failed');
    }
    if (file_put_contents($target, $bytes, LOCK_EX) !== strlen($bytes)) {
        throw new RuntimeException('matrix_file_write_failed');
    }
};
$remove = static function (string $directory) use ($temporary): void {
    $safe = str_replace('\\', '/', $directory);
    $prefixPath = str_replace('\\', '/', $temporary) . '/';
    if (!str_starts_with($safe . '/', $prefixPath)) {
        throw new RuntimeException('unsafe_matrix_cleanup');
    }
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
};

if (!is_dir($backup)) {
    throw new RuntimeException('backup_root_missing');
}
mkdir($temporary, 0770, true);
$entries = ManagedRuntimePublicationPolicy::packageEntries($root, 'prod-2.36.2');
$packagePaths = array_column($entries, 'path');
$packageLookup = array_fill_keys($packagePaths, true);
$materializeClean = static function (string $directory) use ($entries, $root, $write): void {
    foreach ($entries as $entry) {
        $path = (string) $entry['path'];
        $write($directory, $path, ManagedRuntimePublicationPolicy::gitBlob($root, 'prod-2.36.2', $path));
    }
};
$inspect = static fn (string $directory): array => (new ReleaseIntegrityService())->inspectDirectory($directory, false, false);
$variants = [];

try {
    $clean = $temporary . '/clean-2362';
    $materializeClean($clean);
    $cleanInspection = $inspect($clean);
    $manifest2362 = json_decode((string) file_get_contents($clean . '/resources/runtime-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    $frozenIssues = RuntimePublicationPolicy::installedManifestIssues($clean, $manifest2362);
    sort($frozenIssues, SORT_STRING);
    $variants['clean_2362'] = [
        'current_selector_ok' => (bool) ($cleanInspection['ok'] ?? false),
        'legacy_false_positive' => $frozenIssues,
    ];

    $overwrite = $temporary . '/old_files_overwritten';
    $materializeClean($overwrite);
    $overwritten = [];
    foreach ($packagePaths as $path) {
        if (in_array($path, ['VERSION', 'resources/runtime-manifest.json'], true)) {
            continue;
        }
        $candidate = rtrim($backup, '/\\') . '/' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $assertAllowed($candidate);
        if (!is_file($candidate) || is_link($candidate)) {
            continue;
        }
        $bytes = file_get_contents($candidate);
        $expected = ManagedRuntimePublicationPolicy::gitBlob($root, 'prod-2.36.2', $path);
        if (is_string($bytes)
            && !hash_equals(hash('sha256', str_replace(["\r\n", "\r"], "\n", $expected)), hash('sha256', str_replace(["\r\n", "\r"], "\n", $bytes)))
        ) {
            $write($overwrite, $path, $bytes);
            $overwritten[] = $path;
        }
    }
    $overwriteInspection = $inspect($overwrite);
    $variants['old_files_overwritten'] = [
        'ok' => (bool) ($overwriteInspection['ok'] ?? false),
        'overwritten_count' => count($overwritten),
        'error_codes' => array_values(array_unique(array_column((array) ($overwriteInspection['errors'] ?? []), 'code'))),
    ];

    $extras = $temporary . '/clean_plus_historical_extras';
    $materializeClean($extras);
    $extraPath = 'database/migrations/001_initial_schema.sql';
    $extraSource = rtrim($backup, '/\\') . '/' . str_replace('/', DIRECTORY_SEPARATOR, $extraPath);
    $assertAllowed($extraSource);
    $extraBytes = file_get_contents($extraSource);
    if (!is_string($extraBytes)) {
        throw new RuntimeException('historical_extra_missing');
    }
    $write($extras, $extraPath, $extraBytes);
    $extraInspection = $inspect($extras);
    $currentManifest = json_decode(
        (string) file_get_contents($root . '/resources/runtime-manifest.json'),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    $packageFiles = [];
    foreach (ManagedRuntimePublicationPolicy::packageEntries($root, 'HEAD') as $entry) {
        $path = (string) $entry['path'];
        $packageFiles[$path] = ManagedRuntimePublicationPolicy::gitBlob($root, 'HEAD', $path);
    }
    $packageFiles[$extraPath] = $extraBytes;
    $extraPackageIssues = ManagedRuntimePublicationPolicy::packageIssues($root, $currentManifest, $packageFiles, 'HEAD');
    $variants['clean_plus_historical_extras'] = [
        'installed_runtime_ok' => (bool) ($extraInspection['ok'] ?? false),
        'package_rejected' => in_array('package_unsafe_extra:' . $extraPath, $extraPackageIssues, true),
        'policy' => 'Historical files outside the managed component set are tolerated in-place but forbidden inside a Git-exact package.',
    ];

    $derived = $temporary . '/backup_equivalent_package_scope';
    foreach ($packagePaths as $path) {
        $candidate = rtrim($backup, '/\\') . '/' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $assertAllowed($candidate);
        if (!is_file($candidate) || is_link($candidate)) {
            continue;
        }
        $bytes = file_get_contents($candidate);
        if (is_string($bytes)) {
            $write($derived, $path, $bytes);
        }
    }
    $derivedInspection = $inspect($derived);
    $variants['backup_equivalent_package_scope'] = [
        'ok' => (bool) ($derivedInspection['ok'] ?? false),
        'error_count' => count((array) ($derivedInspection['errors'] ?? [])),
        'raw_storage_touched' => false,
    ];

    $mixedManifest = $manifest2362;
    $mixedManifest['version'] = '2.36.3';
    $variants['crossed_manifest_identity'] = [
        'recognized' => ManagedRuntimePublicationPolicy::recognizesInstalledManifest($mixedManifest),
    ];

    $pass = $variants['clean_2362']['current_selector_ok'] === true
        && $variants['clean_2362']['legacy_false_positive'] === [
            'manifest_installed_inventory_mismatch',
            'manifest_publication_policy_mismatch',
            'manifest_release_identity_mismatch',
        ]
        && $variants['old_files_overwritten']['ok'] === false
        && $variants['clean_plus_historical_extras']['installed_runtime_ok'] === true
        && $variants['clean_plus_historical_extras']['package_rejected'] === true
        && $variants['backup_equivalent_package_scope']['ok'] === false
        && $variants['crossed_manifest_identity']['recognized'] === false;
    if (!$pass) {
        throw new RuntimeException('release_mix_matrix_failed');
    }
    $receipt = [
        'schema' => 'erp-meli-release-mix-matrix-2363-v1',
        'status' => 'PASS',
        'variants' => $variants,
        'raw_storage_touched' => false,
        'real_meli_http_calls' => 0,
    ];
    $json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if ($output !== '') {
        if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0770, true) && !is_dir(dirname($output))) {
            throw new RuntimeException('matrix_output_directory_failed');
        }
        file_put_contents($output, $json, LOCK_EX);
    }
    fwrite(STDOUT, 'Release mix matrix 2.36.3: PASS variants=' . count($variants) . PHP_EOL);
} finally {
    foreach (array_reverse(glob($temporary . '/*', GLOB_ONLYDIR) ?: []) as $directory) {
        $remove($directory);
    }
    rmdir($temporary);
}
