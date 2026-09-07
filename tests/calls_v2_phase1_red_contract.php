<?php

declare(strict_types=1);

/*
 * Phase 1 RED contract for the Calls V2 follow-up phases.
 *
 * This is intentionally a RED contract. A non-zero exit in --red mode is
 * the expected result until the production phases close every listed gap.
 */

$redMode = in_array('--red', array_slice($argv, 1), true);
if (!$redMode) {
    fwrite(STDERR, "usage: php tests/calls_v2_phase1_red_contract.php --red\n");
    exit(64);
}

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('required_source_missing:' . $relative);
    }

    return (string) file_get_contents($path);
};

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if ($condition) {
        echo "PASS={$label}\n";
        return;
    }

    $failures[] = $label;
    echo "RED={$label}\n";
};

/** @return array{exit:int,output:string} */
$run = static function (string $script) use ($root): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/' . $script) . ' 2>&1';
    $output = [];
    exec($command, $output, $exit);
    return ['exit' => $exit, 'output' => implode("\n", $output)];
};

// Runs actual Queue V4 worker + disposable MySQL + test-only cURL wire. The
// existing regression currently observes one GET carrying 50 order IDs.
$automatic = $run('calls_final_budget_9_mysql.php');
$harnessFailures = [];
if ($automatic['exit'] !== 0 || !str_contains($automatic['output'], 'STATUS=PASS CALLS_FINAL_BUDGET_9')) {
    $harnessFailures[] = 'automatic_behavior_probe_incomplete';
}
$assert(
    $automatic['exit'] === 0 && str_contains($automatic['output'], 'STATUS=PASS CALLS_FINAL_BUDGET_9')
        && !str_contains($automatic['output'], 'BILLING_50_ORDER_IDS=50'),
    'automatic_billing_get_has_exactly_one_order_id'
);

// This behavioral probe is isolated in its own disposable DB and invokes the
// pack/domain route, pre-curl receipt and known-response fault scenarios.
$behavior = $run('calls_v2_phase1_red_behavior_mysql.php');
$expectedBehaviorLabels = [
    'pack_billing_get_has_exactly_one_order_id',
    'f1_pre_curl_marker_is_unknown_not_exact_physical_call',
    'f2_known_http_status_survives_local_journal_failure',
    'schema_301_persists_one_order_billing_order_v2_checkpoint_evidence',
];
if ($behavior['exit'] !== 0 || !str_contains($behavior['output'], 'STATUS=COMPLETE CALLS_V2_PHASE1_RED_BEHAVIOR')) {
    $harnessFailures[] = 'behavior_probe_incomplete';
}
foreach ($expectedBehaviorLabels as $label) {
    if (!str_contains($behavior['output'], 'RED=' . $label)) {
        $harnessFailures[] = 'missing_expected_red:' . $label;
    }
}
$assert(!str_contains($behavior['output'], 'RED=pack_billing_get_has_exactly_one_order_id'), 'pack_billing_get_has_exactly_one_order_id');
$assert(!str_contains($behavior['output'], 'RED=f1_pre_curl_marker_is_unknown_not_exact_physical_call'), 'f1_pre_curl_marker_is_unknown_not_exact_physical_call');
$assert(!str_contains($behavior['output'], 'RED=f2_known_http_status_survives_local_journal_failure'), 'f2_known_http_status_survives_local_journal_failure');
$schemaViable = !str_contains($behavior['output'], 'RED=schema_301_persists_one_order_billing_order_v2_checkpoint_evidence');
$assert($schemaViable, 'schema_301_persists_one_order_billing_order_v2_checkpoint_evidence');

if ($harnessFailures !== []) {
    fwrite(STDERR, "HARNESS_FAILURES=" . implode(',', $harnessFailures) . "\n");
    exit(2);
}

if ($failures !== []) {
    fwrite(STDERR, "EXPECTED_RED_FAILURES=" . implode(',', $failures) . "\n");
    fwrite(STDERR, "SCHEMA_301_VIABILITY=" . ($schemaViable ? 'PASS' : 'FAIL_CLOSED') . "\n");
    exit(1);
}

echo "STATUS=PASS CALLS_V2_PHASE1_CONTRACT\n";
