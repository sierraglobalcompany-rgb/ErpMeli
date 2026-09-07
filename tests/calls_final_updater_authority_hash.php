<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if (!is_string($root)) {
    throw new RuntimeException('FAIL:repo_root_not_found');
}

$authorityPath = 'resources/release/updater-authority-2.40.1.json';
$migrationPath = 'database/migrations/301_k1d_api_safety_2_40_1.sql';

/** @return string */
function calls_final_git_blob(string $root, string $path, string $revision = 'HEAD'): string
{
    $object = $revision === ':' ? ':' . $path : $revision . ':' . $path;
    $process = proc_open(
        ['git', 'cat-file', 'blob', $object],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('FAIL:git_blob_process_start:' . $path);
    }
    fclose($pipes[0]);
    $blob = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($blob)) {
        throw new RuntimeException('FAIL:git_blob_read:' . $path . ':' . trim((string) $stderr));
    }

    return $blob;
}

$authority = json_decode((string) file_get_contents($root . '/' . $authorityPath), true, 512, JSON_THROW_ON_ERROR);
$dependency = null;
foreach ((array) ($authority['new_runtime_dependencies'] ?? []) as $candidate) {
    if (is_array($candidate) && ($candidate['path'] ?? null) === $migrationPath) {
        $dependency = $candidate;
        break;
    }
}
if (!is_array($dependency)) {
    throw new RuntimeException('FAIL:updater_authority_missing_migration301');
}

$migrationGitBlob = calls_final_git_blob($root, $migrationPath);
$gitBlobSha256 = hash('sha256', $migrationGitBlob);
$crlfConvertedSha256 = hash('sha256', str_replace("\n", "\r\n", $migrationGitBlob));
$worktreeSha256 = hash_file('sha256', $root . '/' . $migrationPath);
$recordedSha256 = (string) ($dependency['sha256'] ?? '');
$manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$manifestComponent = null;
foreach ((array) ($manifest['components'] ?? []) as $candidate) {
    if (is_array($candidate) && ($candidate['path'] ?? null) === $migrationPath) {
        $manifestComponent = $candidate;
        break;
    }
}
if (!is_array($manifestComponent)) {
    throw new RuntimeException('FAIL:runtime_manifest_missing_migration301_component');
}
$manifestSha256 = (string) ($manifestComponent['sha256'] ?? '');
$manifestLfSha256 = (string) ($manifestComponent['sha256_lf'] ?? '');

echo 'RAW_GIT_SHA256=' . $gitBlobSha256 . PHP_EOL;
echo 'RUNTIME_MANIFEST_SHA256=' . $manifestSha256 . PHP_EOL;
echo 'UPDATER_AUTHORITY_SHA256=' . $recordedSha256 . PHP_EOL;
echo 'CRLF_CONVERTED_SHA256=' . $crlfConvertedSha256 . PHP_EOL;

if (!hash_equals($gitBlobSha256, $manifestSha256)
    || !hash_equals($gitBlobSha256, $manifestLfSha256)
    || !hash_equals($gitBlobSha256, $recordedSha256)) {
    throw new RuntimeException('FAIL:migration301_authority_must_use_raw_git_blob_sha256');
}
if (!hash_equals($crlfConvertedSha256, $worktreeSha256)
    || !hash_equals($crlfConvertedSha256, '8170b7a8fe1f5c7a3d734a4af8f3032f2d83400abc7575e3b909014ac525465c')
    || hash_equals($gitBlobSha256, $crlfConvertedSha256)) {
    throw new RuntimeException('FAIL:migration301_crlf_probe_must_remain_distinct');
}

echo "RAW_EQUALS_RUNTIME_MANIFEST=YES\n";
echo "RAW_EQUALS_UPDATER_AUTHORITY=YES\n";
echo "CRLF_PROBE_DISTINCT=YES\n";
echo "STATUS=PASS CALLS_FINAL_UPDATER_AUTHORITY_HASH\n";
