<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tests = [
    'orphan_admission_fail_closed_2391.php',
    'cron_v3_producers_2290.php',
    'legacy_reactivation_fail_closed_2391.php',
    'legacy_cron_fail_closed_2390.php',
    'legacy_authority_v4_23914.php',
    'single_launcher_local_maintenance_2265.php',
    'technical_retention_fencing_22839.php',
    'inventory_contract_23917.php',
    'queue_v4_clean_contract_23917.php',
    'queue_v4_oauth_ecosystem_2387.php',
    'oauth_refresh_isolated_2290.php',
    'notification_topic_registry_fail_closed_b21.php',
    'cron_api_read_performance_qa.php',
    'private_path_authority_2386.php',
    'query_tenant_scope_adversarial_22812.php',
    'tenant_scope_core_22818.php',
    'queue_v4_f2b_backpressure_fresh_gate_2393.php',
    'billing_429_configurable_kiss_23911.php',
    'queue_v4_rhythm_authority_23912.php',
    'settings_b429_normalization_23912_mysql.php',
    'billing_429_emergency_hotfix_mysql.php',
    'billing_429_concurrency_mysql.php',
    'billing_remote_safety_containment_2393_mysql.php',
    ['queue_v4_order_exact_execution_context_2394_mysql.php', 'post'],
    ['billing_terminal_hy093_convergence_2395_mysql.php', 'post'],
    'financial_nonfailure_defer_h3_mysql.php',
    'h4_annual_sales_pack_mysql.php',
    'h4_pack_integrity_backfill_mysql.php',
    'api_health_transport_truth_23913.php',
    'api_log_transport_truth_23914.php',
    'alert_429_transport_truth_23917.php',
    'api_incident_materializer_visibility_23915.php',
    'cron_api_risks_2416.php',
    'operational_surface_contract_23917.php',
    'queue_v4_bulk_parity_convergence_2399.php',
    'domain_exact_finance_admission_2393_mysql.php',
    'queue_v4_update_transition_23917.php',
    'runtime_publication_policy_2380.php',
    'managed_runtime_package_2361.php',
    'runtime_package_attestation_b21a.php',
];

$passed = 0;
foreach ($tests as $name) {
    $arguments = is_array($name) ? $name : [$name];
    $testName = array_shift($arguments);
    $command = [PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', $root . '/tests/' . $testName];
    foreach ($arguments as $argument) {
        $command[] = $argument;
    }
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('current_release_test_process_unavailable:' . $testName);
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
        fwrite(STDERR, (string) $stderr);
        throw new RuntimeException('current_release_test_failed:' . $testName . ':exit_' . $exit);
    }
    $passed++;
}

fwrite(STDOUT, 'CURRENT_RELEASE_SUITE_23917=PASS tests=' . $passed . PHP_EOL);
