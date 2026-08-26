<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tests = [
    'queue_v4_clean_contract_2400.php',
    'manual_single_step_2290.php',
    'billing_429_emergency_hotfix_mysql.php',
    'billing_429_concurrency_mysql.php',
    'queue_v4_bulk_parity_convergence_2399.php',
    'k10_smart_manual_drain_contract.php',
    'k10_p1_repair_contract.php',
    'k10_schema_300_transition_mysql.php',
    'migration_284_drift_recovery_2400_mysql.php',
    'k10_runtime_qualification_mysql.php',
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
        throw new RuntimeException('current_release_2400_test_failed:' . $test . ':exit_' . $exit);
    }
}

fwrite(STDOUT, 'CURRENT_RELEASE_SUITE_2400=PASS tests=' . count($tests) . PHP_EOL);
