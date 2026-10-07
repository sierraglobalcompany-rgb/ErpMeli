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
$raw = ['app/Synthetic.php' => "<?php\n// Raw LF Git blob.\n", 'VERSION' => "2.41.1\n"];
$source = [
    'schema_version' => 1,
    'target_version' => '2.41.1',
    'contract' => 'Synthetic metadata remains unchanged.',
    'supersedes_inventory' => ['path' => 'original.json', 'sha256' => str_repeat('a', 64), 'file_count' => 29],
    'unchanged_locked' => ['file_count' => 27, 'sorted_path_hash_lines_sha256' => str_repeat('b', 64)],
    'intentional_locked_changes' => [['path' => 'app/Locked.php', 'target_sha256' => str_repeat('c', 64)]],
    'extra_metadata' => (object) ['empty_object' => new stdClass(), 'empty_list' => [], 'unicode' => 'sin cambios'],
    'new_runtime_dependencies' => [
        ['path' => 'app/Synthetic.php', 'sha256' => str_repeat('0', 64), 'reason' => 'Preserve entry metadata.'],
        ['path' => 'VERSION', 'sha256' => str_repeat('0', 64)],
    ],
];
foreach ($raw as $path => $bytes) {
    cap2WriteFile($root . '/' . $path, $bytes);
}
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
echo "UNSAFE_NONRUNTIME_MISSING_PATHS=PASS\nNO_PARTIAL_WRITES=PASS\nERP_BOOTSTRAP=NO\nDB_CALLS=0\nDEPLOY=NO\nAUTHORITY_GENERATOR=PASS\n";
