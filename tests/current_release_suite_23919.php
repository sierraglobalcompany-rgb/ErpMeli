<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tests = [
    'current_release_suite_23918.php',
    'queue_v4_bulk_parity_convergence_2399.php',
];

foreach ($tests as $test) {
    $command = [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', $root . '/tests/' . $test];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('current_release_test_process_unavailable:' . $test);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($stdout !== '') {
        fwrite(STDOUT, (string) $stdout);
    }
    if ($exit !== 0) {
        fwrite(STDERR, (string) $stderr);
        throw new RuntimeException('current_release_test_failed:' . $test . ':exit_' . $exit);
    }
}

fwrite(STDOUT, "CURRENT_RELEASE_SUITE_23919=PASS tests=2\n");
