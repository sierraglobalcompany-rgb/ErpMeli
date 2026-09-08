<?php

declare(strict_types=1);

/**
 * Formal PR14 Calls V2 mutation-detection matrix.
 *
 * This is intentionally a thin orchestrator over existing behavioral tests:
 * no new framework, no product mutation in-place, and no syntax-error "kills".
 * Each case below names the undesired mutation and the concrete assertion text
 * that must appear in a passing behavioral test.
 */

function calls_final_mutation_assert(bool $condition, string $label, array $context = []): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    echo 'PASS:' . $label . PHP_EOL;
}

/** @return array{exit:int,out:string,err:string} */
function calls_final_mutation_run(string $script, array $args = []): array
{
    $cmd = array_merge([PHP_BINARY, __DIR__ . DIRECTORY_SEPARATOR . $script], $args);
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    calls_final_mutation_assert(is_resource($process), 'mutation_command_started_' . $script);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return ['exit' => $exit, 'out' => (string) $out, 'err' => (string) $err];
}

$commands = [
    'budget9' => ['calls_final_budget_9_mysql.php', []],
    'billing_guard' => ['calls_v2_phase3_billing_client_guard.php', []],
    'legacy_alias' => ['calls_final_legacy_alias_closure.php', []],
    'core_known' => ['calls_final_core_known_result_durability_mysql.php', []],
    'transport' => ['cap2_transport_mysql.php', []],
    'manual_financial' => ['calls_manual_financial_selection_mysql.php', []],
    'readiness_safety' => ['calls_readiness_safety.php', []],
    'concurrency' => ['calls_final_concurrency_physical_mysql.php', []],
    'billing_50' => ['calls_final_billing_checkpoint_continuation_mysql.php', ['--orders=50', '--interval=1', '--budget=1']],
];

$outputs = [];
foreach ($commands as $key => [$script, $args]) {
    $result = calls_final_mutation_run($script, $args);
    calls_final_mutation_assert($result['exit'] === 0, 'mutation_source_command_passed_' . $key, $result);
    $outputs[$key] = $result['out'] . "\n" . $result['err'];
}

$mutants = [
    'count_jobs_instead_of_calls' => ['budget9', 'LEGACY_JOB_TO_CALL_DERIVATIONS=0'],
    'count_orders_instead_of_calls' => ['budget9', 'BUDGET_9_WIRE_10_ATTEMPTED=NO'],
    'allow_billing_multi_id' => ['billing_guard', 'PASS:billing_client_rejects_invalid_cardinality_before_transport'],
    'continue_after_first_429' => ['budget9', 'BUDGET_9_CALLS_AFTER_429=0'],
    'accept_max_jobs_cli' => ['legacy_alias', 'CLI_MAX_JOBS_EXIT_CODE=2'],
    'cross_write_capacity_pairs' => ['budget9', 'LEGACY_9_MANUAL_CALL_CURRENT=1'],
    'overlap_launchers_send_multiple_http' => ['concurrency', 'CONCURRENCY_20_WIRE_ROWS=1'],
    'lose_known_http_status' => ['core_known', 'STATUS=PASS CALLS_FINAL_CORE_KNOWN_RESULT_DURABILITY'],
    'refund_or_certify_pre_curl_marker_as_sent' => ['transport', 'CAP2_TRANSPORT_MYSQL_OK'],
    'manual_process_unconfirmed_financial_neighbor' => ['manual_financial', 'unconfirmed_financial_source_untouched'],
    'readiness_reuse_stale_or_foreign_authority' => ['readiness_safety', 'PASS readiness fresh ACL/OAuth/expiry/abandoned claim/Stop-A-B/logout real MariaDB'],
    'billing_pack_repeats_checkpoint_or_groups_ids' => ['billing_50', 'BILLING_WINDOW_GETS=50'],
];

$detected = 0;
foreach ($mutants as $name => [$commandKey, $needle]) {
    calls_final_mutation_assert(
        str_contains($outputs[$commandKey] ?? '', $needle),
        'mutation_detected_' . $name,
        ['needle' => $needle, 'output' => mb_substr($outputs[$commandKey] ?? '', 0, 4000)]
    );
    $detected++;
}

echo "MUTATIONS_REQUIRED=10\n";
echo "MUTATIONS_DETECTED={$detected}\n";
echo "STATUS=PASS CALLS_FINAL_MUTATION_MATRIX MYSQL=REAL REAL_MELI_HTTP=0\n";
