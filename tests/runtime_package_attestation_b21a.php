<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/MeliNotificationTopicRegistry.php';
require dirname(__DIR__) . '/app/Services/ManagedRuntimePublicationPolicy.php';

use App\Services\ManagedRuntimePublicationPolicy;

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$has = static fn (array $issues, string $prefix): bool => (bool) array_filter(
    $issues,
    static fn (string $issue): bool => str_starts_with($issue, $prefix),
);

$manifest = json_decode(
    ManagedRuntimePublicationPolicy::gitBlob($root, 'HEAD', 'resources/runtime-manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$files = [];
foreach (ManagedRuntimePublicationPolicy::packageEntries($root) as $entry) {
    $files[$entry['path']] = ManagedRuntimePublicationPolicy::gitBlob($root, 'HEAD', $entry['path']);
}
$assert(ManagedRuntimePublicationPolicy::packageIssues($root, $manifest, $files) === [], 'The Git-exact package was rejected.');

$topicsPath = 'resources/mercadolibre-api/generated/notification-topics.json';
$topicsKey = null;
foreach ($manifest['components'] as $key => $component) {
    if (is_array($component) && ($component['path'] ?? null) === $topicsPath) {
        $topicsKey = $key;
        break;
    }
}
$assert(is_string($topicsKey), 'The notification topic component is absent.');

$missing = $files;
unset($missing[$topicsPath]);
$issues = ManagedRuntimePublicationPolicy::packageIssues($root, $manifest, $missing);
$assert($has($issues, 'package_required_missing:' . $topicsPath), 'Missing topics did not fail.');

$stale = $files;
$stale[$topicsPath] = ManagedRuntimePublicationPolicy::gitBlob(
    $root,
    ManagedRuntimePublicationPolicy::INSTALLED_BASE_COMMIT,
    $topicsPath,
);
$issues = ManagedRuntimePublicationPolicy::packageIssues($root, $manifest, $stale);
$assert($has($issues, 'package_git_blob_mismatch:' . $topicsPath), 'Stale topics did not fail raw identity.');
$assert($has($issues, 'package_notification_topics_semantic_invalid'), 'Stale topics did not fail pack semantics.');

$malformed = $files;
$malformed[$topicsPath] = "{\"rules\":[";
$issues = ManagedRuntimePublicationPolicy::packageIssues($root, $manifest, $malformed);
$assert($has($issues, 'package_git_blob_mismatch:' . $topicsPath), 'Malformed topics with stale manifest did not fail.');
$assert($has($issues, 'package_notification_topics_semantic_invalid'), 'Malformed topics did not fail semantic validation.');

$matchingMalformedManifest = $manifest;
$matchingMalformedManifest['components'][$topicsKey]['sha256'] = hash('sha256', $malformed[$topicsPath]);
$matchingMalformedManifest['components'][$topicsKey]['sha256_lf'] = hash('sha256', $malformed[$topicsPath]);
$issues = ManagedRuntimePublicationPolicy::packageIssues($root, $matchingMalformedManifest, $malformed);
$assert($has($issues, 'package_notification_topics_semantic_invalid'), 'A matching malformed manifest bypassed semantics.');

$caseChanged = $files;
unset($caseChanged[$topicsPath]);
$caseChanged['Resources/mercadolibre-api/generated/notification-topics.json'] = $files[$topicsPath];
$issues = ManagedRuntimePublicationPolicy::packageIssues($root, $manifest, $caseChanged);
$assert($has($issues, 'package_unsafe_extra:'), 'Case-changed path did not fail.');

$shadow = $files;
$shadow['Resources/mercadolibre-api/generated/notification-topics.json'] = $files[$topicsPath];
$issues = ManagedRuntimePublicationPolicy::packageIssues($root, $manifest, $shadow);
$assert($has($issues, 'package_path_case_collision:'), 'Case shadow did not fail.');

$missingComponent = $manifest;
$missingComponent['components'][$topicsKey]['path'] = 'resources/mercadolibre-api/generated/missing.json';
$issues = ManagedRuntimePublicationPolicy::packageIssues($root, $missingComponent, $files);
$assert($has($issues, 'manifest_path_not_in_git:'), 'Manifest missing-file pointer did not fail.');

$unmanifested = $manifest;
unset($unmanifested['components'][$topicsKey]);
$issues = ManagedRuntimePublicationPolicy::packageIssues($root, $unmanifested, $files);
$assert($has($issues, 'manifest_component_missing:' . $topicsPath), 'Required unmanifested runtime did not fail.');

$different = $files;
$different['app/Services/MeliNotificationTopicRegistry.php'] .= "\n";
$issues = ManagedRuntimePublicationPolicy::packageIssues($root, $manifest, $different);
$assert($has($issues, 'package_git_blob_mismatch:app/Services/MeliNotificationTopicRegistry.php'), 'Git blob mismatch did not fail.');

foreach (['../escape.json', 'C:\\escape.json', 'resources\\escape.json'] as $unsafePath) {
    $unsafe = $files;
    $unsafe[$unsafePath] = '{}';
    $assert($has(ManagedRuntimePublicationPolicy::packageIssues($root, $manifest, $unsafe), 'package_path_unsafe'), 'Unsafe path passed: ' . $unsafePath);
}

$run = static function (array $command, ?string $cwd = null): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start package-builder test process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $stdout, (string) $stderr];
};
$removeTree = static function (string $path): void {
    $resolved = realpath($path);
    $temp = realpath(sys_get_temp_dir());
    if ($resolved === false || $temp === false || !str_starts_with(strtolower($resolved), strtolower($temp . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Refusing to remove a path outside the temporary directory.');
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($resolved);
};
$clone = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-meli-b21a-package-' . bin2hex(random_bytes(6));
try {
    [$cloneExit, , $cloneError] = $run(['git', 'clone', '--quiet', '--no-hardlinks', $root, $clone]);
    $assert($cloneExit === 0, 'Unable to create isolated Git-exact package fixture: ' . trim($cloneError));
    file_put_contents($clone . DIRECTORY_SEPARATOR . 'VERSION', "dirty-working-tree-version\n");
    [$dirtyExit, $dirtyOutput] = $run([
        PHP_BINARY,
        $clone . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'build_managed_runtime_release.php',
        '--source=' . $clone,
        '--dry-run=1',
        '--ref=HEAD',
    ], $clone);
    $assert($dirtyExit === 0 && str_contains($dirtyOutput, '"ok":true'), 'Dirty working-tree VERSION influenced the Git-exact builder.');
    $export = $clone . DIRECTORY_SEPARATOR . 'non-git-export';
    mkdir($export);
    file_put_contents($export . DIRECTORY_SEPARATOR . 'VERSION', "2.36.1\n");
    [$exportExit, , $exportError] = $run([
        PHP_BINARY,
        $clone . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'build_managed_runtime_release.php',
        '--source=' . $export,
        '--dry-run=1',
    ], $clone);
    $assert($exportExit !== 0 && str_contains($exportError, 'exact Git toplevel'),
        'A non-Git 2.36.1 export bypassed mandatory Git-exact publication.');
    [$subdirExit, , $subdirError] = $run([
        PHP_BINARY,
        $clone . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'build_managed_runtime_release.php',
        '--source=' . $clone . DIRECTORY_SEPARATOR . 'app',
        '--dry-run=1',
    ], $clone);
    $assert($subdirExit !== 0 && str_contains($subdirError, 'exact Git toplevel'), 'A Git subdirectory was accepted as the release source.');
} finally {
    if (is_dir($clone)) {
        $removeTree($clone);
    }
}

fwrite(STDOUT, 'Runtime package attestation: ' . $checks . " checks passed\n");
