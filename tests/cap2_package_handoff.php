<?php
declare(strict_types=1);

require __DIR__ . '/capacity_handoff.php';

function cap2HandoffAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cap2HandoffRun(array $command, string $cwd): array
{
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

$root = str_replace('\\', '/', getenv('CAP2_PACKAGE_TMP') ?: 'D:/Codex/tmp/erp-meli/cap2-20260905/qa/package-prep');
$case = $root . '/handoff-' . bin2hex(random_bytes(4));
mkdir($case, 0777, true);
$hash = hash('sha256', "head-a\n");
file_put_contents($case . '/target.json', json_encode([
    'base' => str_repeat('1', 40),
    'head' => str_repeat('2', 40),
    'tree' => str_repeat('3', 40),
    'deploy_files' => ['app/a.php' => $hash],
    'base_files' => ['app/a.php' => hash('sha256', "base-a\n")],
    'new_files' => ['jobs/new.php'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

cap2GenerateHandoff($case);
cap2HandoffAssert(is_file($case . '/verificar.ps1'), 'operator_created');
cap2HandoffAssert(is_file($case . '/README.md'), 'guide_created');
cap2HandoffAssert(str_contains(file_get_contents($case . '/README.md'), 'DEPLOY_APPROVED=NO'), 'guide_never_approves');

$powershell = 'C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe';
$cases = [
    ['name' => 'success', 'fixture' => $hash . "  app/a.php\n", 'fixture_exit' => 0, 'process_exit' => 0, 'status' => 'FIXTURE_PASS'],
    ['name' => 'nonzero', 'fixture' => $hash . "  app/a.php\n", 'fixture_exit' => 7, 'process_exit' => 1, 'status' => 'FAIL'],
    ['name' => 'malformed', 'fixture' => "unexpected diagnostic with token=hidden\n", 'fixture_exit' => 0, 'process_exit' => 1, 'status' => 'FAIL'],
    ['name' => 'bad-target', 'fixture' => '', 'fixture_exit' => 0, 'ssh_exit' => -1, 'process_exit' => 1, 'status' => 'FAIL', 'target' => "{\"deploy_files\":{\"../bad\":\"$hash\"}}\n"],
    ['name' => 'bad-port', 'fixture' => '', 'fixture_exit' => 0, 'ssh_exit' => -1, 'process_exit' => 1, 'status' => 'FAIL', 'port' => '0'],
    ['name' => 'bad-port-text', 'fixture' => '', 'fixture_exit' => 0, 'ssh_exit' => -1, 'process_exit' => 1, 'status' => 'FAIL', 'port' => 'not-a-number'],
    ['name' => 'bad-host', 'fixture' => '', 'fixture_exit' => 0, 'ssh_exit' => -1, 'process_exit' => 1, 'status' => 'FAIL', 'host' => 'invalid host'],
    ['name' => 'bad-root', 'fixture' => '', 'fixture_exit' => 0, 'ssh_exit' => -1, 'process_exit' => 1, 'status' => 'FAIL', 'remote_root' => 'relative/root'],
];
foreach ($cases as $spec) {
    $runRoot = $case . '/' . $spec['name'];
    mkdir($runRoot, 0777, true);
    copy($case . '/verificar.ps1', $runRoot . '/verificar.ps1');
    if (isset($spec['target'])) {
        file_put_contents($runRoot . '/target.json', $spec['target']);
    } else {
        copy($case . '/target.json', $runRoot . '/target.json');
    }
    file_put_contents($runRoot . '/fixture.txt', $spec['fixture']);
    [$exit, $stdout, $stderr] = cap2HandoffRun([
        $powershell, '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $runRoot . '/verificar.ps1',
        '-Port', $spec['port'] ?? '22', '-HostName', $spec['host'] ?? 'example.invalid', '-UserName', 'fixture', '-RemoteRoot', $spec['remote_root'] ?? '/fixture',
        '-FixtureOutput', $runRoot . '/fixture.txt', '-FixtureExitCode', (string) $spec['fixture_exit'],
    ], $runRoot);
    cap2HandoffAssert($exit === $spec['process_exit'], $spec['name'] . '_exit:' . $stderr);
    cap2HandoffAssert(preg_match('/CONTROL_FILE=(.+)/', $stdout, $match) === 1, $spec['name'] . '_control_path');
    $controlPath = trim($match[1]);
    $control = file_get_contents($controlPath);
    cap2HandoffAssert(str_contains($control, 'STATUS=' . $spec['status']), $spec['name'] . '_status');
    cap2HandoffAssert(str_contains($control, 'SSH_EXIT_CODE=' . ($spec['ssh_exit'] ?? $spec['fixture_exit'])), $spec['name'] . '_ssh_exit');
    cap2HandoffAssert(str_contains($control, 'DEPLOY_APPROVED=NO'), $spec['name'] . '_not_approved');
    $dir = dirname($controlPath);
    cap2HandoffAssert(is_file($dir . '/command.txt'), $spec['name'] . '_command_log');
    cap2HandoffAssert(is_file($dir . '/output.log'), $spec['name'] . '_output_log');
    cap2HandoffAssert(!str_contains(file_get_contents($dir . '/command.txt'), 'example.invalid'), $spec['name'] . '_command_redacted');
    cap2HandoffAssert(!str_contains(file_get_contents($dir . '/output.log'), 'token=hidden'), $spec['name'] . '_output_redacted');
}

echo "CAP2_PACKAGE_HANDOFF_OK\n";
