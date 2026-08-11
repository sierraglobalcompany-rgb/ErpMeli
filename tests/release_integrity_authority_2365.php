<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Services/RuntimePublicationPolicy.php';
require $root . '/app/Services/ManagedRuntimePublicationPolicy.php';
require $root . '/app/Services/ReleaseIntegrityService.php';

use App\Services\ManagedRuntimePublicationPolicy;
use App\Services\ReleaseIntegrityService;
use App\Services\RuntimePublicationPolicy;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$git = static function (string $spec) use ($root): string {
    $command = ['git', '-C', $root, 'show', $spec];
    $pipes = [];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('git_process_unavailable');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($stdout)) {
        throw new RuntimeException('git_show_failed:' . trim((string) $stderr));
    }
    return $stdout;
};

$manifest2362 = json_decode($git('prod-2.36.2:resources/runtime-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
$assert(ManagedRuntimePublicationPolicy::recognizesInstalledManifest($manifest2362), 'Managed 2.36.2 identity was not recognized.');

$tampered = $manifest2362;
$tampered['build_id'] = 'tampered-build';
$assert(!ManagedRuntimePublicationPolicy::recognizesInstalledManifest($tampered), 'Tampered managed identity was accepted.');

$temporary = sys_get_temp_dir() . '/erp-meli-2365-authority-' . bin2hex(random_bytes(6));
if (!mkdir($temporary . '/resources/release', 0770, true) && !is_dir($temporary . '/resources/release')) {
    throw new RuntimeException('temporary_create_failed');
}
try {
    file_put_contents(
        $temporary . '/resources/release/managed-runtime-dependencies-2.36.2.json',
        $git('prod-2.36.2:resources/release/managed-runtime-dependencies-2.36.2.json')
    );
    file_put_contents(
        $temporary . '/resources/release/queue-core-runtime-dependencies.json',
        $git('prod-2.36.2:resources/release/queue-core-runtime-dependencies.json')
    );
    $assert(ManagedRuntimePublicationPolicy::installedManifestIssues($temporary, $manifest2362) === [], 'Clean managed 2.36.2 manifest was rejected.');
    $frozen = RuntimePublicationPolicy::installedManifestIssues($temporary, $manifest2362);
    sort($frozen, SORT_STRING);
    $expected = [
        'manifest_installed_inventory_mismatch',
        'manifest_publication_policy_mismatch',
        'manifest_release_identity_mismatch',
    ];
    sort($expected, SORT_STRING);
    $assert($frozen === $expected, 'The screenshot errors were not reproduced exactly on clean 2.36.2.');
} finally {
    @unlink($temporary . '/resources/release/managed-runtime-dependencies-2.36.2.json');
    @unlink($temporary . '/resources/release/queue-core-runtime-dependencies.json');
    @rmdir($temporary . '/resources/release');
    @rmdir($temporary . '/resources');
    @rmdir($temporary);
}

$currentManifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
$assert(ManagedRuntimePublicationPolicy::recognizesInstalledManifest($currentManifest), 'Current managed identity was not recognized.');
$integrity = (new ReleaseIntegrityService())->inspectDirectory($root, false, false);
$assert((bool) ($integrity['ok'] ?? false), 'Current Git release integrity failed: ' . json_encode($integrity['errors'] ?? []));

fwrite(STDOUT, 'Release integrity authority 2.36.5: PASS checks=' . $checks . PHP_EOL);
