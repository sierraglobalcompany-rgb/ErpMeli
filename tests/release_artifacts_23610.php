<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/MeliNotificationTopicRegistry.php';
require dirname(__DIR__) . '/app/Services/ManagedRuntimePublicationPolicy.php';
require dirname(__DIR__) . '/app/Services/UpdateFilesystemService.php';
require dirname(__DIR__) . '/app/Services/UpdateManifestService.php';

use App\Services\ManagedRuntimePublicationPolicy;
use App\Services\UpdateManifestService;

$directory = $argv[1] ?? '';
if (!is_dir($directory) || !class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Release artifacts 2.36.10: FAIL arguments\n");
    exit(2);
}

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($label);
    }
};
$readZip = static function (string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('zip_open_failed:' . basename($path));
    }
    $files = [];
    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            $bytes = $zip->getFromIndex($index);
            if ($name === '' || !is_string($bytes) || isset($files[strtolower($name)])) {
                throw new RuntimeException('zip_inventory_invalid:' . basename($path));
            }
            $files[$name] = $bytes;
        }
    } finally {
        $zip->close();
    }
    return $files;
};

try {
    $fullPath = $directory . '/ERP_MELI_2.36.10_GIT_EXACT.zip';
    $overlayPath = $directory . '/ERP_MELI_2.36.10_FTP_REPAIR_OVERLAY.zip';
    $updatePath = $directory . '/ERP_MELI_2.36.10_UPDATE_PACKAGE.erpupd';
    $inventoryPath = $directory . '/ERP_MELI_2.36.10_FTP_REPAIR_OVERLAY_INVENTORY.json';
    $authorityPath = $directory . '/ERP_MELI_2.36.10_ARTIFACT_MANIFEST.json';
    $sumsPath = $directory . '/ERP_MELI_2.36.10_SHA256SUMS.txt';
    foreach ([$fullPath, $overlayPath, $updatePath, $inventoryPath, $authorityPath, $sumsPath] as $path) {
        $assert(is_file($path), 'artifact_missing:' . basename($path));
    }

    $entries = ManagedRuntimePublicationPolicy::packageEntries($root, 'HEAD');
    $expected = [];
    foreach ($entries as $entry) {
        $expected[$entry['path']] = ManagedRuntimePublicationPolicy::gitBlob($root, 'HEAD', $entry['path']);
    }
    $full = $readZip($fullPath);
    $assert(count($full) === count($expected), 'full_count_invalid');
    $assert(array_keys($full) === array_keys($expected), 'full_paths_invalid');
    foreach ($expected as $path => $bytes) {
        $assert(hash_equals(hash('sha256', $bytes), hash('sha256', $full[$path])), 'full_blob_invalid:' . $path);
    }
    $runtimeManifest = json_decode($full['resources/runtime-manifest.json'], true, 512, JSON_THROW_ON_ERROR);
    $assert(ManagedRuntimePublicationPolicy::packageIssues($root, $runtimeManifest, $full, 'HEAD') === [], 'full_policy_invalid');

    $inventory = json_decode((string) file_get_contents($inventoryPath), true, 512, JSON_THROW_ON_ERROR);
    $overlay = $readZip($overlayPath);
    $overlayRows = is_array($inventory['files'] ?? null) ? $inventory['files'] : [];
    $assert((int) ($inventory['file_count'] ?? -1) === 6, 'overlay_count_authority_invalid');
    $assert(count($overlay) === count($overlayRows), 'overlay_count_invalid');
    foreach ($overlayRows as $row) {
        $path = is_array($row) ? (string) ($row['path'] ?? '') : '';
        $assert(isset($overlay[$path]), 'overlay_path_missing:' . $path);
        $assert(hash_equals((string) $row['sha256'], hash('sha256', $overlay[$path])), 'overlay_hash_invalid:' . $path);
    }

    $update = $readZip($updatePath);
    $manifestBytes = $update['update-manifest.json'] ?? null;
    $assert(is_string($manifestBytes), 'update_manifest_missing');
    $manifestService = new UpdateManifestService();
    $manifest = $manifestService->decode($manifestBytes);
    $assert(($manifest['source_trust'] ?? null) === 'local_admin', 'update_source_trust_invalid');
    $assert(!isset($manifest['signature']), 'unexpected_update_signature');
    $assert($manifestService->verifySignature($manifest) === 'local_unsigned', 'local_admin_signature_status_invalid');
    unset($update['update-manifest.json']);
    $assert(array_keys($update) === array_keys($expected), 'update_paths_invalid');
    foreach ((array) ($manifest['files'] ?? []) as $row) {
        $path = is_array($row) ? (string) ($row['path'] ?? '') : '';
        $assert(isset($update[$path]), 'update_file_missing:' . $path);
        $assert(hash_equals((string) $row['sha256'], hash('sha256', $update[$path])), 'update_file_hash_invalid:' . $path);
    }

    $authority = json_decode((string) file_get_contents($authorityPath), true, 64, JSON_THROW_ON_ERROR);
    foreach ((array) ($authority['artifacts'] ?? []) as $name => $definition) {
        $path = $directory . '/' . $name;
        $assert(is_file($path), 'authority_artifact_missing:' . $name);
        $assert(hash_equals((string) $definition['sha256'], hash_file('sha256', $path)), 'authority_hash_invalid:' . $name);
    }
    $sumLines = file($sumsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($sumLines as $line) {
        $assert(preg_match('~^([a-f0-9]{64})  ([^/\\\\]+)$~', $line, $match) === 1, 'sha_line_invalid');
        $assert(is_file($directory . '/' . $match[2]), 'sha_target_missing');
        $assert(hash_equals($match[1], hash_file('sha256', $directory . '/' . $match[2])), 'sha_target_invalid:' . $match[2]);
    }
    fwrite(STDOUT, 'Release artifacts 2.36.10: PASS checks=' . $checks . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Release artifacts 2.36.10: FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
