<?php
declare(strict_types=1);

// Git-only mechanism tests. No ERP bootstrap, DB, network, signing or official writes.
require __DIR__ . '/capacity_artifact.php';
$repo = dirname(__DIR__);
$mode = $argv[1] ?? 'green';
$out = $repo . '/storage/codex-atomic-2411';
$base = 'fd07d148479360b6665ad8f04feef4a5efd32331';
$target = 'e0bca492cc78175d33ce5750562dba0d800520d5';
$authorityPath = 'resources/release/updater-authority-2.41.1.json';
$failures = [];
function lockedCheck(bool $condition, string $name): void {
    global $failures;
    echo ($condition ? 'PASS:' : 'FAIL:') . $name . "\n";
    if (!$condition) { $failures[] = $name; }
}
function lockedReject(callable $call, string $name, ?string $expectedError = null): void {
    try { $call(); lockedCheck(false, $name); }
    catch (RuntimeException $e) {
        lockedCheck($expectedError === null || str_starts_with($e->getMessage(), $expectedError), $name);
        echo 'REJECTION=' . $e->getMessage() . "\n";
    }
}
function lockedReasons(array $authority, bool $includeTarget): array {
    $reasons = [];
    foreach ($authority['intentional_locked_changes'] as $entry) {
        $reasons[] = ['path' => $entry['path'], 'reason' => $entry['reason']];
    }
    $map = [
        'app/Services/RuntimePublicationPolicy.php' => 'HISTORICAL_RUNTIME_PUBLICATION_ATTESTATION_EVOLUTION',
        'bin/build_update_package.php' => 'RELEASE_2411_MANAGED_OPERATOR_RUNTIME_QA_PACKAGING',
    ];
    foreach (['installer-responsive.css', 'installer.css', 'installer.js', 'update.css', 'update.js'] as $file) {
        $map['public/assets/' . $file] = 'HISTORICAL_EOL_SERIALIZATION_CRLF_TO_LF_NO_SEMANTIC_CHANGE';
    }
    if ($includeTarget) {
        // Fixture authorization derived from each actual diff and the accepted focal tests.
        $map['app/Services/SecureUpdateEngineService.php'] = 'Bind health promotion to authorized active release identity; promote installed metadata before DB integrity; fence and resume code/metadata rollback under the update lock.';
        $map['app/Services/UpdateReleaseService.php'] = 'Reject malformed VERSION and verify manifest-present health structurally before metadata promotion; preserve valid legacy compatibility and restrict recovery snapshots to verified operator runtime.';
        $map['app/Services/UpdateManifestService.php'] = 'Allow the exact root .htaccess file while retaining traversal and other root dotfile rejection.';
    }
    foreach ($map as $path => $reason) { $reasons[] = compact('path', 'reason'); }
    return $reasons;
}
$authorities = [];
foreach (['BASE' => $base, 'TARGET' => $target] as $name => $ref) {
    $authorities[$name] = json_decode(cap2GitBlob($repo, $ref, $authorityPath), true, 512, JSON_THROW_ON_ERROR);
}
if ($mode === 'red') {
    foreach (['BASE' => $base, 'TARGET' => $target] as $name => $ref) {
        $old = cap2UpdaterAuthority($repo, $ref);
        $unchanged = (array) $old['unchanged_locked'];
        $expected = $name === 'BASE' ? [20, 9] : [17, 12];
        echo $name . '_EXPECTED_RAW=' . implode('/', $expected) . ' OLD_GENERATOR=' . $unchanged['file_count'] . '/' . count($old['intentional_locked_changes']) . "\n";
        lockedCheck($unchanged['file_count'] === $expected[0] && count($old['intentional_locked_changes']) === $expected[1], 'RED_' . $name . '_RECONSTRUCTION');
    }
    // Current tool has no external authorization gate, so missing reasons succeed incorrectly.
    lockedReject(fn() => cap2UpdaterAuthority($repo, $base), 'RED_MISSING_REASON');
    exit($failures === [] ? 0 : 1);
}
$results = [];
foreach (['BASE' => $base, 'TARGET' => $target] as $name => $ref) {
    $a = $authorities[$name];
    $reasons = lockedReasons($a, $name === 'TARGET');
    $r = cap2ReconstructLockedAuthority($repo, $ref, $a['supersedes_inventory'], $reasons);
    $expected = $name === 'BASE' ? [20, 9] : [17, 12];
    lockedCheck($r['unchanged_locked']['file_count'] === $expected[0] && count($r['intentional_locked_changes']) === $expected[1], $name . '_RAW_RECONSTRUCTION');
    lockedCheck($r['diagnostics']['raw_changed_without_reason'] === 0, $name . '_ALL_REASONS_PRESENT');
    $inventory = json_decode(cap2GitBlob($repo, $ref, $a['supersedes_inventory']['path']), true, 512, JSON_THROW_ON_ERROR);
    $historic = array_column($inventory['files'], 'final_sha256', 'path');
    $inventoryOrder = array_column($inventory['files'], 'path');
    $actualOrder = [];
    foreach ($r['intentional_locked_changes'] as $entry) {
        $actualOrder[] = $entry['path'];
        lockedCheck($entry['previous_sha256'] === $historic[$entry['path']]
            && $entry['target_sha256'] === hash('sha256', cap2GitBlob($repo, $ref, $entry['path'])), $name . '_RAW_HASHES:' . $entry['path']);
    }
    lockedCheck($actualOrder === array_values(array_filter($inventoryOrder, fn($p) => in_array($p, $actualOrder, true))), $name . '_INVENTORY_ORDER');
    $reverse = array_reverse($reasons);
    lockedCheck(cap2Json($r) === cap2Json(cap2ReconstructLockedAuthority($repo, $ref, $a['supersedes_inventory'], $reverse)), 'N8_DETERMINISTIC_' . $name);
    foreach (['installer-responsive.css', 'installer.css', 'installer.js', 'update.css', 'update.js'] as $file) {
        $path = 'public/assets/' . $file;
        $raw = cap2GitBlob($repo, $ref, $path);
        lockedCheck(in_array($path, $actualOrder, true) && hash('sha256', $raw) !== $historic[$path]
            && hash('sha256', str_replace("\n", "\r\n", $raw)) === $historic[$path], 'N2_EOL_CHANGED_' . $name . ':' . $file);
    }
    $without = array_values(array_filter($reasons, fn($e) => $e['path'] !== 'bin/build_update_package.php'));
    lockedReject(fn() => cap2ReconstructLockedAuthority($repo, $ref, $a['supersedes_inventory'], $without), 'N1_MISSING_REASON_' . $name, 'locked_changed_without_reason:bin/build_update_package.php');
    $results[$name] = $r;
}
// Exact historical serialization; format proof is separate from actual RAW membership.
$a = $authorities['BASE'];
$inventory = json_decode(cap2GitBlob($repo, $base, $a['supersedes_inventory']['path']), true, 512, JSON_THROW_ON_ERROR);
$excluded = array_column($a['intentional_locked_changes'], 'path');
$lines = [];
foreach ($inventory['files'] as $entry) {
    if (!in_array($entry['path'], $excluded, true)) { $lines[$entry['path']] = $entry['path'] . "\t" . $entry['final_sha256']; }
}
ksort($lines, SORT_STRING);
lockedCheck(hash('sha256', implode("\n", $lines) . "\n") === '76d38e632333da994447234dc809557150bdf7ed09f1c9643aa12056c21a6f5a', 'HISTORICAL_SERIALIZATION_HASH');

// Small independent Git fixture, not a clone of private/runtime data.
$scratch = $out . '/locked-v4-fixture-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
cap2WriteFile($scratch . '/app/Unchanged.php', "<?php\n// unchanged\n");
cap2WriteFile($scratch . '/app/Changed.php', "<?php\n// current\n");
$mini = ['files' => [
    ['path' => 'app/Unchanged.php', 'final_sha256' => hash('sha256', "<?php\n// unchanged\n")],
    ['path' => 'app/Changed.php', 'final_sha256' => hash('sha256', "<?php\n// historical\n")],
]];
$ip = 'resources/locked.json';
$ib = cap2Json($mini);
cap2WriteFile($scratch . '/' . $ip, $ib);
cap2Git($scratch, ['init', '--quiet']);
cap2Git($scratch, ['config', 'user.name', 'Synthetic Locked Test']);
cap2Git($scratch, ['config', 'user.email', 'locked-test@example.invalid']);
cap2Git($scratch, ['config', 'core.autocrlf', 'false']);
cap2Git($scratch, ['add', '--all']);
cap2Git($scratch, ['commit', '--quiet', '-m', 'Synthetic Git RAW fixture']);
$ref = trim(cap2Git($scratch, ['rev-parse', 'HEAD'])['stdout']);
$reference = ['path' => $ip, 'sha256' => hash('sha256', $ib), 'file_count' => 2];
$reasons = [['path' => 'app/Changed.php', 'reason' => 'Explicit synthetic authorization']];
$call = fn($iref, $why = null, $commit = null) => cap2ReconstructLockedAuthority($scratch, $commit ?? $ref, $iref, $why ?? $reasons);
$positive = $call($reference);
lockedCheck($positive['unchanged_locked']['file_count'] === 1 && count($positive['intentional_locked_changes']) === 1, 'GENERIC_NON_2411_INVENTORY');
$bad = $reference; $bad['sha256'] = str_repeat('0', 64);
lockedReject(fn() => $call($bad), 'N4_INVENTORY_HASH_MISMATCH', 'locked_inventory_hash_mismatch');
$bad = $reference; $bad['file_count'] = 3;
lockedReject(fn() => $call($bad), 'N5_INVENTORY_COUNT_MISMATCH', 'locked_inventory_count_or_structure_mismatch');
lockedReject(fn() => $call($reference, array_merge($reasons, [['path' => 'app/Orphan.php', 'reason' => 'No authority']])), 'N9_ORPHAN_REASON', 'locked_reason_orphan:app/Orphan.php');
lockedReject(fn() => $call($reference, array_merge($reasons, [['path' => 'app/Unchanged.php', 'reason' => 'Unchanged']])), 'UNCHANGED_REASON_REJECTED', 'locked_reason_for_unchanged_path');
lockedReject(fn() => $call($reference, [['path' => 'app/Changed.php', 'reason' => '  ']]), 'EMPTY_REASON_REJECTED', 'locked_reason_invalid');
lockedReject(fn() => $call($reference, array_merge($reasons, $reasons)), 'DUPLICATE_REASON_REJECTED', 'locked_reason_duplicate');
lockedReject(fn() => $call($reference, [['path' => '../bad.php', 'reason' => 'Unsafe']]), 'UNSAFE_REASON_REJECTED', 'unsafe_package_path');
cap2WriteFile($scratch . '/app/Unchanged.php', "<?php\n// unauthorized mutation\n");
cap2Git($scratch, ['add', '--all']); cap2Git($scratch, ['commit', '--quiet', '-m', 'Synthetic mutation']);
$changedRef = trim(cap2Git($scratch, ['rev-parse', 'HEAD'])['stdout']);
lockedReject(fn() => $call($reference, null, $changedRef), 'N3_UNAUTHORIZED_RAW_MUTATION', 'locked_changed_without_reason:app/Unchanged.php');
// Deliberate working-tree disagreement must never affect reconstruction.
cap2WriteFile($scratch . '/app/Changed.php', "working tree only\r\n");
lockedCheck(cap2Json($positive) === cap2Json($call($reference)), 'WORKTREE_BYTES_IGNORED');
$dup = $mini; $dup['files'][] = $mini['files'][0];
$dupBytes = cap2Json($dup); cap2WriteFile($scratch . '/' . $ip, $dupBytes);
cap2Git($scratch, ['add', '--all']); cap2Git($scratch, ['commit', '--quiet', '-m', 'Synthetic duplicate inventory']);
$dupRef = trim(cap2Git($scratch, ['rev-parse', 'HEAD'])['stdout']);
$dupReference = ['path' => $ip, 'sha256' => hash('sha256', $dupBytes), 'file_count' => 3];
lockedReject(fn() => $call($dupReference, null, $dupRef), 'N6_DUPLICATE_PATH', 'locked_inventory_duplicate_path');
cap2Git($scratch, ['rm', '--quiet', '--', 'app/Changed.php']);
cap2Git($scratch, ['commit', '--quiet', '-m', 'Synthetic missing locked target']);
// Restore valid inventory and intact sibling so only the missing path can cause rejection.
cap2WriteFile($scratch . '/' . $ip, $ib);
cap2WriteFile($scratch . '/app/Unchanged.php', "<?php\n// unchanged\n");
cap2Git($scratch, ['add', '--all']); cap2Git($scratch, ['commit', '--quiet', '-m', 'Restore valid synthetic inventory']);
$missingRef = trim(cap2Git($scratch, ['rev-parse', 'HEAD'])['stdout']);
lockedReject(fn() => $call($reference, null, $missingRef), 'N7_MISSING_TARGET_PATH', 'locked_regular_blob_required:app/Changed.php');
cap2WriteFile($out . '/locked-v4-results.json', cap2Json($results));
echo 'FAILURES=' . count($failures) . "\nDB_CALLS=0\nOFFICIAL_WRITES=0\n";
exit($failures === [] ? 0 : 1);
