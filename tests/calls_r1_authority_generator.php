<?php
declare(strict_types=1);

// Standalone, synthetic Git-only regression. Never bootstraps the ERP or a database.
require __DIR__ . '/capacity_artifact.php';

function r1AuthorityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{exit:int,stdout:string,stderr:string} */
function r1AuthorityRun(string $root, array $arguments): array
{
    $process = proc_open(array_merge([PHP_BINARY, $root . '/tests/capacity_artifact.php'], $arguments), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('authority_process_start_failed');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$scratch = str_replace('\\', '/', $argv[1] ?? '');
$projectScratch = str_replace('\\', '/', dirname(__DIR__) . '/storage/codex-');
r1AuthorityAssert((preg_match('#^D:/[A-Za-z0-9._/-]+$#D', $scratch) === 1
        || str_starts_with($scratch, $projectScratch))
    && !in_array('..', explode('/', $scratch), true), 'explicit_safe_D_scratch_required');
$root = rtrim($scratch, '/') . '/run-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
cap2WriteFile($root . '/tests/capacity_artifact.php', (string) file_get_contents(__DIR__ . '/capacity_artifact.php'));
cap2WriteFile($root . '/tests/k1b_bootstrap.php', "<?php // Synthetic CLI bootstrap: no ERP, no DB.\n");
cap2Git($root, ['init', '--quiet']);
cap2Git($root, ['config', 'core.autocrlf', 'false']);
cap2Git($root, ['config', 'user.name', 'Synthetic Authority Test']);
cap2Git($root, ['config', 'user.email', 'authority-test@example.invalid']);

$authorityPath = 'resources/release/updater-authority-2.41.1.json';
$raw = ['app/Synthetic.php' => "<?php\n// Raw LF Git blob.\n", 'VERSION' => "2.41.1\n",
    'app/Locked.php' => "<?php\n// Authorized target.\n", 'app/Unchanged.php' => "<?php\n// Historical intact.\n"];
$previousLockedHash = hash('sha256', "<?php\n// Historical locked baseline.\n");
$unchangedHash = hash('sha256', $raw['app/Unchanged.php']);
$inventoryBytes = cap2Json(['files' => [
    ['path' => 'app/Locked.php', 'final_sha256' => $previousLockedHash],
    ['path' => 'app/Unchanged.php', 'final_sha256' => $unchangedHash],
]]);
$source = [
    'schema_version' => 1,
    'target_version' => '2.41.1',
    'contract' => 'Synthetic metadata remains unchanged.',
    'supersedes_inventory' => ['path' => 'original.json', 'sha256' => hash('sha256', $inventoryBytes), 'file_count' => 2],
    'unchanged_locked' => ['file_count' => 1, 'sorted_path_hash_lines_sha256' => hash('sha256', 'app/Unchanged.php' . "\t" . $unchangedHash . "\n")],
    'intentional_locked_changes' => [['path' => 'app/Locked.php', 'previous_sha256' => $previousLockedHash,
        'target_sha256' => hash('sha256', $raw['app/Locked.php']), 'reason' => 'Explicit synthetic locked authorization.']],
    'extra_metadata' => (object) ['empty_object' => new stdClass(), 'empty_list' => [], 'unicode' => 'sin cambios'],
    'new_runtime_dependencies' => [
        ['path' => 'app/Synthetic.php', 'sha256' => str_repeat('0', 64), 'reason' => 'Preserve entry metadata.'],
        ['path' => 'VERSION', 'sha256' => str_repeat('0', 64)],
    ],
];
foreach ($raw as $path => $bytes) {
    cap2WriteFile($root . '/' . $path, $bytes);
}
cap2WriteFile($root . '/original.json', $inventoryBytes);
cap2WriteFile($root . '/public/assets/probe.txt', "Synthetic tree-path rejection fixture.\n");
cap2WriteFile($root . '/' . $authorityPath, cap2Json($source));
cap2Git($root, ['add', '--all']);
cap2Git($root, ['commit', '--quiet', '-m', 'Synthetic immutable authority fixture']);
$sha = trim(cap2Git($root, ['rev-parse', 'HEAD'])['stdout']);

// Deliberately disagree with Git, both in newlines and authority metadata.
foreach ($raw as $path => $bytes) {
    cap2WriteFile($root . '/' . $path, str_replace("\n", "\r\n", $bytes));
}
cap2WriteFile($root . '/' . $authorityPath, "{\"worktree_only\":true}\r\n");
$first = r1AuthorityRun($root, ['updater-authority', $sha]);
echo 'SYNTHETIC_ROOT=' . $root . "\n";
echo 'GENERATOR_EXIT=' . $first['exit'] . "\n" . $first['stdout'] . $first['stderr'];
r1AuthorityAssert($first['exit'] === 0, 'updater_authority_mode_must_succeed');
$actual = (string) file_get_contents($root . '/' . $authorityPath);
$expected = $source;
foreach ($expected['new_runtime_dependencies'] as &$entry) {
    $entry['sha256'] = hash('sha256', $raw[$entry['path']]);
}
unset($entry);
usort(
    $expected['new_runtime_dependencies'],
    static fn (array $left, array $right): int => strcmp($left['path'], $right['path'])
);
r1AuthorityAssert($actual === cap2Json($expected), 'raw_blob_hashes_and_all_metadata_structure_order_preserved');
foreach ($raw as $path => $bytes) {
    r1AuthorityAssert(hash_file('sha256', $root . '/' . $path) !== hash('sha256', $bytes), 'CRLF_fixture_must_differ:' . $path);
}
$second = r1AuthorityRun($root, ['updater-authority', $sha]);
r1AuthorityAssert($second['exit'] === 0 && file_get_contents($root . '/' . $authorityPath) === $actual, 'deterministic_repeated_generation');
echo "RAW_LF_NOT_CRLF=PASS\nMETADATA_STRUCTURE_PATHSET=PASS\nDETERMINISM=PASS\n";

$invalidRefs = [null, 'HEAD', substr($sha, 0, 12), str_repeat('g', 40), str_repeat('0', 40), $sha . ':VERSION',
    trim(cap2Git($root, ['rev-parse', 'HEAD^{tree}'])['stdout'])];
foreach ($invalidRefs as $ref) {
    $result = r1AuthorityRun($root, $ref === null ? ['updater-authority'] : ['updater-authority', $ref]);
    r1AuthorityAssert($result['exit'] !== 0, 'invalid_ref_rejected:' . ($ref ?? '(missing)'));
    r1AuthorityAssert(file_get_contents($root . '/' . $authorityPath) === $actual, 'invalid_ref_no_write');
}
echo "EXPLICIT_COMMIT_SHA_VALIDATION=PASS\n";

foreach (['../outside.php', '/outside.php', 'D:/outside.php', 'app\\Synthetic.php', 'app//Synthetic.php', '.env', 'tests/not-runtime.php', 'app/Missing.php', 'public/assets'] as $badPath) {
    $invalid = $source;
    $invalid['new_runtime_dependencies'][1]['path'] = $badPath;
    cap2WriteFile($root . '/' . $authorityPath, cap2Json($invalid));
    cap2Git($root, ['add', '--', $authorityPath]);
    cap2Git($root, ['commit', '--quiet', '-m', 'Synthetic invalid dependency']);
    $badSha = trim(cap2Git($root, ['rev-parse', 'HEAD'])['stdout']);
    $before = (string) file_get_contents($root . '/' . $authorityPath);
    $result = r1AuthorityRun($root, ['updater-authority', $badSha]);
    r1AuthorityAssert($result['exit'] !== 0, 'invalid_path_rejected:' . $badPath);
    r1AuthorityAssert(file_get_contents($root . '/' . $authorityPath) === $before, 'invalid_path_no_partial_write');
}
echo "UNSAFE_NONRUNTIME_MISSING_PATHS=PASS\nNO_PARTIAL_WRITES=PASS\n";

// Independent synthetic target requiring an explicit supplementary authorization.
foreach ($raw as $path => $bytes) { cap2WriteFile($root . '/' . $path, $bytes); }
cap2WriteFile($root . '/app/Unchanged.php', "<?php\n// Newly authorized target.\n");
cap2WriteFile($root . '/app/Additional.php', "<?php\n// Additional dependency.\n");
cap2WriteFile($root . '/' . $authorityPath, cap2Json($source));
cap2Git($root, ['add', '--all']);
cap2Git($root, ['commit', '--quiet', '-m', 'Synthetic supplementary reason target']);
$cliSha = trim(cap2Git($root, ['rev-parse', 'HEAD'])['stdout']);
$reason = 'Explicit supplemental authorization; bytes preserved.';
$reasonFile = $root . '/storage/reasons.json';
cap2WriteFile($reasonFile, cap2Json([['path' => 'app/Unchanged.php', 'reason' => $reason]]));
$before = (string) file_get_contents($root . '/' . $authorityPath);
$without = r1AuthorityRun($root, ['updater-authority', $cliSha]);
r1AuthorityAssert($without['exit'] !== 0 && file_get_contents($root . '/' . $authorityPath) === $before, 'RED_CLI_1_without_reason_fail_closed');
echo "RED_CLI_1=FAIL_CLOSED_NO_WRITE\n";
$args = ['updater-authority', $cliSha, '--locked-reasons-file=' . $reasonFile, 'app/Additional.php'];
$with = r1AuthorityRun($root, $args);
echo 'CLI_REASON_EXIT=' . $with['exit'] . "\n" . $with['stdout'] . $with['stderr'];
if ($with['exit'] !== 0) {
    r1AuthorityAssert(file_get_contents($root . '/' . $authorityPath) === $before, 'RED_CLI_2_no_write');
    echo "CURRENT_CLI_REASON_CHANNEL=ABSENT\nRED_CLI_2_AUTHORITY_UNCHANGED=YES\n";
}
r1AuthorityAssert($with['exit'] === 0, 'canonical_cli_reason_channel_required');
$actualCli = (string) file_get_contents($root . '/' . $authorityPath);
$decoded = json_decode($actualCli, true, 512, JSON_THROW_ON_ERROR);
$changes = array_column($decoded['intentional_locked_changes'], null, 'path');
r1AuthorityAssert($changes['app/Unchanged.php']['previous_sha256'] === $unchangedHash, 'CLI_previous_RAW');
r1AuthorityAssert($changes['app/Unchanged.php']['target_sha256'] === hash('sha256', cap2GitBlob($root, $cliSha, 'app/Unchanged.php')), 'CLI_target_RAW');
r1AuthorityAssert($changes['app/Unchanged.php']['reason'] === $reason, 'CLI_reason_bytes');
r1AuthorityAssert($decoded['unchanged_locked']['file_count'] === 0, 'CLI_unchanged_locked');
foreach ($decoded['new_runtime_dependencies'] as $entry) {
    r1AuthorityAssert($entry['sha256'] === hash('sha256', cap2GitBlob($root, $cliSha, $entry['path'])), 'CLI_dependency_RAW');
}
r1AuthorityAssert(in_array('app/Additional.php', array_column($decoded['new_runtime_dependencies'], 'path'), true), 'CLI_additional_path');
$repeat = r1AuthorityRun($root, $args);
r1AuthorityAssert($repeat['exit'] === 0 && file_get_contents($root . '/' . $authorityPath) === $actualCli, 'CLI_determinism');
echo "CLI_WITH_REASON_FILE=PASS\nCLI_DETERMINISTIC=PASS\n";
cap2WriteFile($root . '/outside.json', '[]');
cap2WriteFile($root . '/storage/malformed.json', '{');
cap2WriteFile($root . '/storage/object.json', '{}');
cap2WriteFile($root . '/storage/large.json', str_repeat(' ', 65537));
$negative = [
    'C1_MISSING_FILE' => [$root . '/storage/missing.json'],
    'C2_RELATIVE_FILE' => ['storage/reasons.json'],
    'C3_OUTSIDE_STORAGE' => [$root . '/outside.json'],
    'C4_DIRECTORY' => [$root . '/storage'],
    'C5_MALFORMED_JSON' => [$root . '/storage/malformed.json'],
    'C6_NOT_LIST' => [$root . '/storage/object.json'],
    'C7_DUPLICATE_FLAG' => [$reasonFile, $reasonFile],
    'C8_TOO_LARGE' => [$root . '/storage/large.json'],
    'C10_DOTDOT' => [$root . '/storage/../storage/reasons.json'],
];
$link = $root . '/storage/link.json';
if (@symlink($reasonFile, $link)) { $negative['C9_SYMLINK'] = [$link]; }
else {
    echo "C9_SYMLINK=SKIP_ENVIRONMENT_UNSUPPORTED; loader is_link rejection inspected\n";
    echo 'C9_ENVIRONMENT=' . json_encode(error_get_last(), JSON_UNESCAPED_SLASHES) . "\n";
}
foreach ($negative as $name => $files) {
    $badArgs = ['updater-authority', $cliSha];
    foreach ($files as $file) { $badArgs[] = '--locked-reasons-file=' . $file; }
    $bad = r1AuthorityRun($root, $badArgs);
    r1AuthorityAssert($bad['exit'] !== 0 && file_get_contents($root . '/' . $authorityPath) === $actualCli, $name . '_reject_no_write');
    echo $name . "=PASS\n";
}
echo "ERP_BOOTSTRAP=NO\nDB_CALLS=0\nDEPLOY=NO\nAUTHORITY_GENERATOR=PASS\n";
