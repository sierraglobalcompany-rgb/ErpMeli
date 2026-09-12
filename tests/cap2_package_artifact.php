<?php
declare(strict_types=1);

require __DIR__ . '/capacity_artifact.php';

function cap2PkgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cap2PkgRun(array $command, ?string $cwd = null): array
{
    if (($command[0] ?? null) === 'git' && $cwd !== null) {
        array_splice($command, 1, 0, ['-c', 'safe.directory=' . str_replace('\\', '/', $cwd)]);
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('process_start_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
}

function cap2PkgWrite(string $path, string $bytes): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('mkdir_failed');
    }
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('write_failed');
    }
}

$tmpRoot = str_replace('\\', '/', getenv('CAP2_PACKAGE_TMP') ?: (getenv('CALLS_VERIFY_QA_ROOT') ? rtrim((string) getenv('CALLS_VERIFY_QA_ROOT'), '/\\') . '/package-tests' : 'D:/Codex/tmp/erp-meli/cap2-20260905/qa/package-prep'));
if (!(str_starts_with($tmpRoot, 'D:/Codex/') || str_starts_with($tmpRoot, 'C:/codex/capacity-save-kiss/')) || in_array('..', explode('/', $tmpRoot), true)) throw new RuntimeException('EXPLICIT_LOCAL_QA_ROOT_REQUIRED');
$case = $tmpRoot . '/artifact-' . bin2hex(random_bytes(4));
$repo = $case . '/repo';
$out = $case . '/out';
mkdir($repo, 0777, true);

[$code, , $err] = cap2PkgRun(['git', 'init', '-q'], $repo);
cap2PkgAssert($code === 0, 'git_init:' . $err);
cap2PkgRun(['git', 'config', 'user.name', 'CAP2 Fixture'], $repo);
cap2PkgRun(['git', 'config', 'user.email', 'cap2@example.invalid'], $repo);
cap2PkgWrite($repo . '/app/a.php', "base-a\n");
cap2PkgWrite($repo . '/app/unchanged.php', "same\n");
cap2PkgWrite($repo . '/tests/not-runtime.php', "base-test\n");
cap2PkgRun(['git', 'add', '.'], $repo);
cap2PkgRun(['git', 'commit', '-qm', 'base'], $repo);
[, $base] = cap2PkgRun(['git', 'rev-parse', 'HEAD'], $repo);
$base = trim($base);

cap2PkgWrite($repo . '/app/a.php', "head-a\n");
cap2PkgWrite($repo . '/jobs/new.php', "new-runtime\n");
cap2PkgWrite($repo . '/tests/not-runtime.php', "changed-test\n");
cap2PkgRun(['git', 'add', '.'], $repo);
cap2PkgRun(['git', 'commit', '-qm', 'head'], $repo);
[, $head] = cap2PkgRun(['git', 'rev-parse', 'HEAD'], $repo);
$head = trim($head);

$result = cap2BuildRawArtifact(
    $repo,
    $base,
    $head,
    $out,
    ['app/a.php', 'app/unchanged.php'],
    ['app/a.php', 'app/unchanged.php', 'jobs/new.php'],
    '20260905'
);

cap2PkgAssert(basename($result['deploy_zip']) === 'meli-cap2-deploy-20260905.zip', 'short_deploy_name');
cap2PkgAssert($result['deploy_count'] === 2, 'dynamic_delta_count');
cap2PkgAssert($result['deploy_paths'] === ['app/a.php', 'jobs/new.php'], 'runtime_delta_only');
cap2PkgAssert($result['added_paths'] === ['jobs/new.php'], 'added_path_recorded');
cap2PkgAssert($result['replaced_paths'] === ['app/a.php'], 'replaced_path_recorded');

$zip = new ZipArchive();
cap2PkgAssert($zip->open($result['deploy_zip']) === true, 'deploy_zip_reopens');
cap2PkgAssert($zip->numFiles === 2, 'deploy_zip_dynamic_count');
cap2PkgAssert($zip->getFromName('app/a.php') === "head-a\n", 'deploy_uses_raw_head_blob');
cap2PkgAssert($zip->getFromName('jobs/new.php') === "new-runtime\n", 'deploy_contains_added_runtime');
cap2PkgAssert($zip->getFromName('tests/not-runtime.php') === false, 'deploy_excludes_tests');
$zip->close();

$rollback = new ZipArchive();
cap2PkgAssert($rollback->open($result['rollback_zip']) === true, 'rollback_zip_reopens');
cap2PkgAssert($rollback->getFromName('app/a.php') === "base-a\n", 'rollback_uses_raw_base_blob');
cap2PkgAssert($rollback->getFromName('REMOVE_ADDED_FILES.txt') === "jobs/new.php\n", 'rollback_lists_added_removal');
$rollback->close();

cap2PkgAssert(file_get_contents($result['applied_tree'] . '/app/a.php') === "head-a\n", 'applied_tree_modified');
cap2PkgAssert(file_get_contents($result['applied_tree'] . '/app/unchanged.php') === "same\n", 'applied_tree_unchanged');
cap2PkgAssert(file_get_contents($result['applied_tree'] . '/jobs/new.php') === "new-runtime\n", 'applied_tree_added');
$control = file_get_contents($out . '/CONTROL.txt');
cap2PkgAssert(str_contains($control, 'DEPLOY_APPROVED=NO'), 'never_approve_from_structure');
cap2PkgAssert(str_contains($control, 'PACKAGE_STATUS=PREPARATORY_NOT_FOR_UPLOAD'), 'preparatory_marker');

$tampered = $case . '/tampered.zip';
copy($result['deploy_zip'], $tampered);
$zip = new ZipArchive();
$zip->open($tampered);
$zip->addFromString('app/a.php', "tampered\n");
$zip->close();
$tamperRejected = false;
try {
    cap2VerifyZip($tampered, $result['deploy_hashes']);
} catch (RuntimeException) {
    $tamperRejected = true;
}
cap2PkgAssert($tamperRejected, 'tampered_zip_must_fail');

$unsafeRejected = false;
try {
    cap2AssertSafePackagePath('../private.env');
} catch (RuntimeException) {
    $unsafeRejected = true;
}
cap2PkgAssert($unsafeRejected, 'unsafe_path_must_fail');

$nonRuntimeRejected = false;
try {
    cap2AssertRuntimePackagePath('tests/looks-valid.php');
} catch (RuntimeException) {
    $nonRuntimeRejected = true;
}
cap2PkgAssert($nonRuntimeRejected, 'test_path_must_never_be_runtime');

foreach (['public/.env', 'public/config.env', 'public/pause_meli_api', 'public/pause_erp_automation'] as $protectedPath) {
    $protectedRejected = false;
    try {
        cap2AssertRuntimePackagePath($protectedPath);
    } catch (RuntimeException) {
        $protectedRejected = true;
    }
    cap2PkgAssert($protectedRejected, 'protected_basename_must_fail:' . $protectedPath);
}
foreach (['public/config.environment.js', 'public/pause_meli_api_status.js'] as $legitimatePath) {
    cap2AssertRuntimePackagePath($legitimatePath);
}

echo "CAP2_PACKAGE_ARTIFACT_OK\n";
