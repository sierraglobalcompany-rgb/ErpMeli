<?php
declare(strict_types=1);

require __DIR__ . '/capacity_evidence.php';

function cap2EvidenceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cap2EvidenceRun(array $command, string $cwd): array
{
    if (($command[0] ?? null) === 'git') {
        array_splice($command, 1, 0, ['-c', 'safe.directory=' . str_replace('\\', '/', $cwd)]);
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
}

function cap2EvidenceWrite(string $path, string $bytes): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $bytes);
}

$tmp = str_replace('\\', '/', getenv('CAP2_PACKAGE_TMP') ?: 'D:/Codex/tmp/erp-meli/cap2-20260905/qa/package-prep');
$case = $tmp . '/evidence-' . bin2hex(random_bytes(4));
$workspace = $case . '/workspace';
$qa = $case . '/qa';
$artifact = $case . '/artifact';
mkdir($workspace, 0777, true);
mkdir($qa, 0777, true);
mkdir($artifact, 0777, true);
cap2EvidenceRun(['git', 'init', '-q'], $workspace);
cap2EvidenceRun(['git', 'config', 'user.name', 'CAP2 Fixture'], $workspace);
cap2EvidenceRun(['git', 'config', 'user.email', 'cap2@example.invalid'], $workspace);
cap2EvidenceWrite($workspace . '/tests/cap2_alpha.php', "<?php echo 'cap2';\n");
cap2EvidenceWrite($workspace . '/tests/capacity_artifact.php', "<?php echo 'helper';\n");
cap2EvidenceWrite($workspace . '/tests/unrelated.php', "<?php echo 'raw';\n");
cap2EvidenceWrite($workspace . '/docs/superpowers/plans/2026-09-05-cap2.md', "# Plan\n");
cap2EvidenceRun(['git', 'add', '.'], $workspace);
cap2EvidenceRun(['git', 'commit', '-qm', 'fixture'], $workspace);
[, $head] = cap2EvidenceRun(['git', 'rev-parse', 'HEAD'], $workspace);
$head = trim($head);

cap2EvidenceWrite($workspace . '/.superpowers/sdd/2026-09-05-cap2/task-7-review.md', "review\n");
cap2EvidenceWrite($workspace . '/.superpowers/sdd/2026-09-05-cap2/browser-prep-report.md', "browser prep\n");
cap2EvidenceWrite($workspace . '/.superpowers/sdd/2026-09-05-cap2/browser-prep-review.md', "browser review\n");
cap2EvidenceWrite($workspace . '/.superpowers/sdd/2026-09-05-cap2/package-prep-review.md', "package review\n");
cap2EvidenceWrite($workspace . '/.superpowers/sdd/2026-09-05-cap2/task-5-fix1-review.md', "fix review\n");
cap2EvidenceWrite($workspace . '/.superpowers/sdd/2026-09-05-cap2/private.env', "CLIENT_SECRET=do-not-package\n");
cap2EvidenceWrite($qa . '/MATRIX.md', "pending\n");
cap2EvidenceWrite($qa . '/task7-red.log', "expected failure\n");
cap2EvidenceWrite($qa . '/browser-mobile.png', "synthetic-png\n");
cap2EvidenceWrite($qa . '/secret.env', "PASSWORD=do-not-package\n");
cap2EvidenceWrite($qa . '/npm-cache/index.log', "cache\n");
cap2EvidenceWrite($qa . '/browser-profile/Cookies', "cookies\n");
cap2EvidenceWrite($qa . '/raw-source.php', "<?php echo 'raw';\n");
cap2EvidenceWrite($qa . '/browser-config/automatic-desktop.png', "desktop\n");
cap2EvidenceWrite($qa . '/browser-config/red-browser.log', "red\n");
cap2EvidenceWrite($qa . '/browser-config/final-browser.log', "green\n");
$browserEvidence = ['browser-config/review-rerun-browser.log', 'browser-step/setup.log', 'browser-step/final-browser.log', 'browser-step/php-server.log', 'browser-step/wire.jsonl', 'browser-step/normal-checkpoint-desktop.png', 'browser-step/protected-429-mobile.png', 'browser-step/post-checkpoint-failure-desktop.png', 'browser-step/continuation-result-mobile.png'];
foreach ($browserEvidence as $name) cap2EvidenceWrite($qa . '/' . $name, "fixture evidence\n");
cap2EvidenceWrite($qa . '/browser-step/fixture-meta.json', "private fixture\n");
cap2EvidenceWrite($qa . '/browser-config/.playwright-cli/console.log', "profile cache\n");
cap2EvidenceWrite($qa . '/package-prep/green-verification.log', "verified\n");
cap2EvidenceWrite($qa . '/package-prep/round1-green-verification.log', "round one verified\n");
cap2EvidenceWrite($qa . '/package-prep/artifact-private/target.json', "private fixture\n");

$artifactFiles = [
    'CONTROL.txt' => "PACKAGE_STATUS=PREPARATORY_NOT_FOR_UPLOAD\nDEPLOY_APPROVED=NO\n",
    'README.md' => "guide\n",
    'target.json' => json_encode(['head' => $head], JSON_THROW_ON_ERROR) . "\n",
    'SHA256SUMS.txt' => "hashes\n",
    'integrity.json' => "{\"ok\":true}\n",
    'rollback.zip' => "synthetic rollback\n",
    'verificar.ps1' => "Write-Output 'fixture'\n",
];
foreach ($artifactFiles as $name => $bytes) {
    cap2EvidenceWrite($artifact . '/' . $name, $bytes);
}

$result = cap2BuildEvidenceZip($workspace, $qa, $artifact, '20260905');
cap2EvidenceAssert(basename($result['qa_zip']) === 'meli-cap2-qa-20260905.zip', 'short_qa_name');
$zip = new ZipArchive();
cap2EvidenceAssert($zip->open($result['qa_zip']) === true, 'qa_reopens');
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = $zip->getNameIndex($i);
}
cap2EvidenceAssert(in_array('tests/cap2_alpha.php', $names, true), 'cap2_test_included');
cap2EvidenceAssert(in_array('tests/capacity_artifact.php', $names, true), 'packaging_helper_included');
cap2EvidenceAssert(in_array('plan/2026-09-05-cap2.md', $names, true), 'plan_included');
cap2EvidenceAssert(in_array('review/task-7-review.md', $names, true), 'review_included');
cap2EvidenceAssert(in_array('review/browser-prep-report.md', $names, true), 'browser_report_included');
cap2EvidenceAssert(in_array('review/browser-prep-review.md', $names, true), 'browser_independent_review_included');
cap2EvidenceAssert(in_array('review/package-prep-review.md', $names, true), 'package_independent_review_included');
cap2EvidenceAssert(in_array('review/task-5-fix1-review.md', $names, true), 'fix_review_included');
cap2EvidenceAssert(in_array('qa/MATRIX.md', $names, true), 'matrix_included');
cap2EvidenceAssert(in_array('qa/task7-red.log', $names, true), 'red_log_included');
cap2EvidenceAssert(in_array('qa/browser-mobile.png', $names, true), 'screenshot_included');
cap2EvidenceAssert(in_array('qa/browser-config/automatic-desktop.png', $names, true), 'nested_browser_screenshot_included');
cap2EvidenceAssert(in_array('qa/browser-config/red-browser.log', $names, true), 'nested_browser_red_included');
cap2EvidenceAssert(in_array('qa/browser-config/final-browser.log', $names, true), 'nested_browser_log_included');
foreach ($browserEvidence as $name) cap2EvidenceAssert(in_array('qa/' . $name, $names, true), 'browser_evidence_included:' . $name);
cap2EvidenceAssert(!in_array('qa/browser-step/fixture-meta.json', $names, true), 'browser_private_meta_excluded');
cap2EvidenceAssert(in_array('qa/package-prep/green-verification.log', $names, true), 'package_verification_included');
cap2EvidenceAssert(in_array('qa/package-prep/round1-green-verification.log', $names, true), 'package_round_verification_included');
cap2EvidenceAssert(in_array('EVIDENCE_HASHES.json', $names, true), 'hash_inventory_included');
foreach (['tests/unrelated.php', 'review/private.env', 'qa/secret.env', 'qa/npm-cache/index.log', 'qa/browser-profile/Cookies', 'qa/raw-source.php', 'qa/browser-config/.playwright-cli/console.log', 'qa/package-prep/artifact-private/target.json'] as $forbidden) {
    cap2EvidenceAssert(!in_array($forbidden, $names, true), 'forbidden_evidence:' . $forbidden);
}
$hashInventory = json_decode($zip->getFromName('EVIDENCE_HASHES.json'), true, 64, JSON_THROW_ON_ERROR);
foreach ($hashInventory as $name => $expectedHash) {
    $bytes = $zip->getFromName($name);
    cap2EvidenceAssert(is_string($bytes) && hash_equals($expectedHash, hash('sha256', $bytes)), 'evidence_hash:' . $name);
}
$zip->close();
cap2EvidenceAssert(str_contains(file_get_contents($artifact . '/CONTROL.txt'), 'DEPLOY_APPROVED=NO'), 'evidence_never_approves');

echo "CAP2_PACKAGE_EVIDENCE_OK\n";
