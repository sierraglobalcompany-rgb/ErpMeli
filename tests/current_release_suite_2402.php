<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tests = [
    'queue_v4_clean_contract_2402.php',
    'queue_v4_domain_source_stale_recheck_2401.php',
    'queue_v4_policy_delay_churn_2401.php',
    'settings_kiss_operational_navigation_2401.php',
    'api_health_remote_429_visibility_2401_mysql.php',
    'api_incident_materializer_visibility_23915.php',
    'cron_api_risks_2416.php',
    'operator_control_plane_2402.php',
    'manual_selection_execution_binding_2402.php',
    'manual_single_global_lease_2402_mysql.php',
    'k10_smart_manual_drain_contract.php',
    'k10_p1_repair_contract.php',
];

foreach ($tests as $test) {
    $command = [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'error_reporting=-1', '-d', 'display_errors=1', $root . '/tests/' . $test];
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
        throw new RuntimeException('current_release_2402_test_failed:' . $test . ':exit_' . $exit);
    }
}

fwrite(STDOUT, 'CURRENT_RELEASE_SUITE_2402=PASS tests=' . count($tests) . PHP_EOL);
