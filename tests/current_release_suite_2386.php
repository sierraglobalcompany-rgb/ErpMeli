<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tests = [
    'inventory_contract_2386.php',
    'queue_v4_clean_contract_2386.php',
    'oauth_refresh_isolated_2290.php',
    'notification_topic_registry_fail_closed_b21.php',
    'cron_api_read_performance_qa.php',
    'private_path_authority_2386.php',
    'runtime_publication_policy_2380.php',
    'runtime_package_attestation_b21a.php',
    'updater_authority_2386.php',
];

$passed = 0;
foreach ($tests as $test) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', $root . '/tests/' . $test],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('current_release_test_process_unavailable:' . $test);
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if (is_string($stdout) && $stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($exit !== 0) {
        if (is_string($stderr) && $stderr !== '') {
            fwrite(STDERR, $stderr);
        }
        throw new RuntimeException('current_release_test_failed:' . $test . ':exit_' . $exit);
    }
    $passed++;
}

fwrite(STDOUT, 'CURRENT_RELEASE_SUITE_2386=PASS tests=' . $passed . PHP_EOL);
