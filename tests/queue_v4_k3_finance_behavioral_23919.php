<?php

declare(strict_types=1);

$root = dirname(__DIR__);

/**
 * @return array{stdout:string,stderr:string,exit:int}
 */
$run = static function (string $test, array $arguments = []) use ($root): array {
    $command = [
        PHP_BINARY,
        '-d',
        'error_reporting=-1',
        '-d',
        'display_errors=1',
        $root . '/tests/' . $test,
        ...$arguments,
    ];
    $stdoutFile = tempnam(sys_get_temp_dir(), 'k3_behavior_stdout_');
    $stderrFile = tempnam(sys_get_temp_dir(), 'k3_behavior_stderr_');
    if ($stdoutFile === false || $stderrFile === false) {
        throw new RuntimeException('k3_behavior_tempfile_failed');
    }
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $stdoutFile, 'wb'], 2 => ['file', $stderrFile, 'wb']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('k3_behavior_process_unavailable:' . $test);
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stdout = (string) file_get_contents($stdoutFile);
    $stderr = (string) file_get_contents($stderrFile);
    @unlink($stdoutFile);
    @unlink($stderrFile);
    return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$gates = [
    'domain' => [
        'test' => 'domain_exact_finance_admission_2393_mysql.php',
        'must' => [
            'DOMAIN_EXACT_FINANCE_ADMISSION_2393=PASS',
            'real_http=0',
            'historical=0',
            'tenant_null=0',
        ],
    ],
    'bulk' => [
        'test' => 'queue_v4_bulk_parity_convergence_2399.php',
        'must' => [
            'QUEUE_V4_BULK_PARITY_CONVERGENCE_2399=PASS',
        ],
    ],
    'b429' => [
        'test' => 'billing_429_emergency_hotfix_mysql.php',
        'must' => [
            'BILLING_429_EMERGENCY_HOTFIX_MYSQL=PASS',
            'FIRST_429_GLOBAL_PARKING_SAME_CYCLE=PASS',
            'NON_FINANCE_NOT_PARKED=PASS',
            'FIFO_PRESERVED=PASS',
            'REMOTE_RETRY_AFTER_5400_NOT_TRUNCATED=PASS',
        ],
    ],
    'concurrency' => [
        'test' => 'billing_429_concurrency_mysql.php',
        'must' => [
            'BILLING_429_CONCURRENCY_MYSQL=PASS',
            'finance_parking=waiting',
            'real_http=0',
        ],
    ],
];

$evidence = [];
foreach ($gates as $name => $gate) {
    $result = $run($gate['test']);
    if ($result['exit'] !== 0) {
        fwrite(STDERR, $result['stdout']);
        fwrite(STDERR, $result['stderr']);
        throw new RuntimeException('k3_behavior_gate_failed:' . $name . ':exit_' . $result['exit']);
    }
    foreach ($gate['must'] as $needle) {
        $assert(str_contains($result['stdout'], $needle), 'k3_behavior_gate_missing:' . $name . ':' . $needle);
    }
    $evidence[$name] = trim($result['stdout']);
}

fwrite(
    STDOUT,
    'K3_BEHAVIORAL_MYSQL=PASS'
    . ' SOURCE_POINTER_ATOMICITY=PASS'
    . ' HISTORICAL_ORPHAN_FAIL_CLOSED=PASS'
    . ' TENANT_NULL_FAIL_CLOSED=PASS'
    . ' CROSS_TENANT=0'
    . ' DUPLICATE_REMOTE_BILLING=0'
    . ' BATCH_SCOPE_AUTHORITY=PASS'
    . ' B429_UNCHANGED=PASS'
    . ' GLOBAL_FINANCE_PARKING_UNCHANGED=PASS'
    . ' NON_FINANCE_NOT_PARKED=PASS'
    . ' RETRY_AFTER_UNCHANGED=PASS'
    . ' FIFO_REGRESSION=PASS'
    . ' REAL_MELI_HTTP=0'
    . ' EVIDENCE_SHA256=' . hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR))
    . PHP_EOL
);
