<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

$root = realpath(__DIR__ . '/..');
k1b_assert(is_string($root), 'repo_root_not_found');

$registryPath = 'resources/release/managed-runtime-dependencies-2.41.1.json';
$manifestPath = $root . '/resources/runtime-manifest.json';
$registryWorktreePath = $root . '/' . $registryPath;

/** @return array{0:int,1:string,2:string} */
function k1d_rel1_run(array $command, string $cwd): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('process_start_failed:' . implode(' ', $command));
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return [$exit, is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
}

/** @return array{text:string,eol:string} */
function k1d_rel1_git_attrs(string $root, string $path): array
{
    [$exit, $stdout, $stderr] = k1d_rel1_run(['git', 'check-attr', 'text', 'eol', '--', $path], $root);
    if ($exit !== 0) {
        throw new RuntimeException('git_check_attr_failed:' . trim($stderr));
    }
    $attrs = ['text' => '', 'eol' => ''];
    foreach (preg_split('/\R/', trim($stdout)) ?: [] as $line) {
        if (str_contains($line, ': text:')) {
            $attrs['text'] = trim(substr($line, strrpos($line, ':') + 1));
        }
        if (str_contains($line, ': eol:')) {
            $attrs['eol'] = trim(substr($line, strrpos($line, ':') + 1));
        }
    }

    return $attrs;
}

[$blobExit, $blobBytes, $blobErr] = k1d_rel1_run(['git', 'cat-file', 'blob', 'HEAD:' . $registryPath], $root);
if ($blobExit !== 0) {
    throw new RuntimeException('git_blob_read_failed:' . trim($blobErr));
}

[$shaExit, $blobSha1, $shaErr] = k1d_rel1_run(['git', 'rev-parse', 'HEAD:' . $registryPath], $root);
if ($shaExit !== 0) {
    throw new RuntimeException('git_blob_sha1_failed:' . trim($shaErr));
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
$registryBytes = (string) file_get_contents($registryWorktreePath);
$attrs = k1d_rel1_git_attrs($root, $registryPath);
$blobSha256 = hash('sha256', $blobBytes);
$worktreeRawSha256 = hash('sha256', $registryBytes);
$worktreeLfSha256 = hash('sha256', str_replace(["\r\n", "\r"], "\n", $registryBytes));
$syntheticCrLfSha256 = hash('sha256', str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $blobBytes)));

$component = null;
foreach (($manifest['components'] ?? []) as $definition) {
    if (is_array($definition) && ($definition['path'] ?? '') === $registryPath) {
        $component = $definition;
        break;
    }
}

$policyHash = (string) ($manifest['publication_policy']['dependency_registry_sha256'] ?? '');
$componentHash = is_array($component) ? (string) ($component['sha256'] ?? '') : '';
$componentLfHash = is_array($component) ? (string) ($component['sha256_lf'] ?? '') : '';
$failures = [];

if ($attrs['text'] !== 'set' || $attrs['eol'] !== 'lf') {
    $failures[] = 'dependency_registry_git_attributes_not_lf';
}
if ($policyHash !== $blobSha256) {
    $failures[] = 'dependency_registry_raw_git_hash_mismatch';
}
if ($componentHash !== $blobSha256) {
    $failures[] = 'dependency_registry_component_raw_hash_mismatch';
}
if ($componentLfHash !== $blobSha256) {
    $failures[] = 'dependency_registry_component_lf_hash_mismatch';
}
if ($worktreeLfSha256 !== $blobSha256) {
    $failures[] = 'dependency_registry_worktree_lf_hash_mismatch';
}
if ($syntheticCrLfSha256 === $blobSha256) {
    $failures[] = 'dependency_registry_crlf_probe_not_distinct';
}

echo 'REGISTRY_PATH=' . $registryPath . "\n";
echo 'GIT_BLOB_SHA1=' . trim($blobSha1) . "\n";
echo 'GIT_CHECK_ATTR_TEXT=' . $attrs['text'] . "\n";
echo 'GIT_CHECK_ATTR_EOL=' . $attrs['eol'] . "\n";
echo 'GIT_BLOB_SHA256=' . $blobSha256 . "\n";
echo 'WORKTREE_RAW_SHA256=' . $worktreeRawSha256 . "\n";
echo 'WORKTREE_LF_SHA256=' . $worktreeLfSha256 . "\n";
echo 'SYNTHESIZED_CRLF_SHA256=' . $syntheticCrLfSha256 . "\n";
echo 'MANIFEST_POLICY_REGISTRY_SHA256=' . $policyHash . "\n";
echo 'MANIFEST_COMPONENT_REGISTRY_SHA256=' . $componentHash . "\n";
echo 'MANIFEST_COMPONENT_REGISTRY_SHA256_LF=' . $componentLfHash . "\n";
echo 'FAILURES=' . implode(';', $failures) . "\n";

if ($failures !== []) {
    exit(20);
}

echo "STATUS=PASS_K1D_REL1_REGISTRY_HASH_AUTHORITY\n";
exit(0);
