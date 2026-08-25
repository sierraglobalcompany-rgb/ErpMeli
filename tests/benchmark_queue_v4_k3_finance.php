<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$rawCsvPath = trim((string) getenv('K3_FINANCE_BENCHMARK_RAW_CSV'));
if ($rawCsvPath === '') {
    $rawCsvPath = $root . '/artifacts-local/k3-finance-benchmark/13_DUE_PACK_BENCHMARK_RAW.csv';
}
if ($rawCsvPath !== '') {
    $dir = dirname($rawCsvPath);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('k3_benchmark_raw_csv_dir_failed');
    }
}

$env = array_merge($_ENV, [
    'ERP_MIGRATOR_TEST_DSN' => (string) getenv('ERP_MIGRATOR_TEST_DSN'),
    'ERP_MIGRATOR_TEST_USER' => (string) getenv('ERP_MIGRATOR_TEST_USER'),
    'ERP_MIGRATOR_TEST_PASS' => (string) getenv('ERP_MIGRATOR_TEST_PASS'),
    'PATH' => (string) getenv('PATH'),
    'SystemRoot' => (string) getenv('SystemRoot'),
    'TEMP' => (string) getenv('TEMP'),
    'TMP' => (string) getenv('TMP'),
]);
$env['K1_SCAN_MODES'] = 'PACK_INCOMPLETE';
$env['K1_SCAN_PREFIXES'] = '3,10,30,59';
$env['K1_SCAN_ITERATIONS'] = (string) max(1, (int) ((string) getenv('K3_FINANCE_BENCHMARK_ITERATIONS') ?: 20));
$env['K1_SCAN_PACK_DUE'] = '1';
unset($env['K1_SCAN_PROGRESS']);
if ($rawCsvPath !== '') {
    $env['K1_SCAN_RAW_CSV'] = $rawCsvPath;
}

$command = [
    PHP_BINARY,
    '-d',
    'error_reporting=-1',
    '-d',
    'display_errors=1',
    $root . '/tests/benchmark_queue_v4_k1_scan.php',
];
$childOut = tempnam(sys_get_temp_dir(), 'k3_due_pack_out_');
$childErr = tempnam(sys_get_temp_dir(), 'k3_due_pack_err_');
if ($childOut === false || $childErr === false) {
    throw new RuntimeException('k3_benchmark_tempfile_failed');
}
$process = proc_open(
    $command,
    [0 => ['pipe', 'r'], 1 => ['file', $childOut, 'wb'], 2 => ['file', $childErr, 'wb']],
    $pipes,
    $root,
    $env,
    ['bypass_shell' => true]
);
if (!is_resource($process)) {
    throw new RuntimeException('k3_benchmark_subprocess_unavailable');
}
fclose($pipes[0]);
$exit = proc_close($process);
$stdout = (string) file_get_contents($childOut);
$stderr = (string) file_get_contents($childErr);
@unlink($childOut);
@unlink($childErr);
if ($stdout !== '' && (string) getenv('K3_DEBUG_CHILD_BENCHMARK') === '1') {
    fwrite(STDOUT, $stdout);
}
if ($exit !== 0) {
    if ($stdout !== '') {
        fwrite(STDERR, $stdout);
    }
    fwrite(STDERR, $stderr);
    throw new RuntimeException('k3_due_pack_child_failed:exit_' . $exit);
}
if ($rawCsvPath === '' || !is_file($rawCsvPath)) {
    throw new RuntimeException('k3_due_pack_raw_csv_missing');
}
$handle = fopen($rawCsvPath, 'rb');
if ($handle === false) {
    throw new RuntimeException('k3_due_pack_raw_csv_open_failed');
}
$header = fgetcsv($handle, 0, ',', '"', '\\');
if (!is_array($header)) {
    throw new RuntimeException('k3_due_pack_raw_csv_header_missing');
}
$rows = [];
while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $row = [];
    foreach ($header as $index => $key) {
        $row[(string) $key] = $data[$index] ?? '';
    }
    $rows[] = $row;
}
fclose($handle);

$byPrefix = [];
$elapsed = [];
$required = [3, 10, 30, 59];
foreach ($rows as $row) {
    $prefix = (int) ($row['prefix'] ?? -1);
    $byPrefix[$prefix] = true;
    $elapsed[] = (float) ($row['elapsed_ms'] ?? 0);
    if (($row['mode'] ?? '') !== 'PACK_INCOMPLETE' || !in_array($prefix, $required, true)) {
        throw new RuntimeException('k3_due_pack_raw_unexpected_row');
    }
    if ((int) ($row['effective'] ?? -1) !== 1
        || (int) ($row['downstream_source_processed'] ?? -1) < 1
        || (int) ($row['downstream_pointer_completed'] ?? -1) !== 1
        || (int) ($row['fake_billing_call_count'] ?? -1) !== 0
        || (int) ($row['pack_sources_deferred'] ?? -1) !== $prefix
        || (int) ($row['pack_attempt_penalty'] ?? -1) !== 0) {
        throw new RuntimeException('k3_due_pack_behavior_failed:' . json_encode($row, JSON_THROW_ON_ERROR));
    }
}

foreach ($required as $prefix) {
    if (!isset($byPrefix[$prefix])) {
        throw new RuntimeException('k3_due_pack_prefix_missing_' . $prefix);
    }
}
if (count($rows) < 80) {
    throw new RuntimeException('k3_due_pack_observations_insufficient:' . count($rows));
}

$percentile = static function (array $values, float $p): float {
    sort($values);
    $n = count($values);
    return $values[(int) min($n - 1, max(0, floor(($n - 1) * $p)))];
};
$p50 = $percentile($elapsed, 0.50);
$p95 = $percentile($elapsed, 0.95);
$p99 = $percentile($elapsed, 0.99);
$max = max($elapsed);
fwrite(
    STDOUT,
    'QUEUE_V4_K3_FINANCE_BENCHMARK=PASS'
    . ' DUE_PACK_OBSERVATIONS=' . count($rows)
    . ' DUE_PACK_PREFIX_3=PASS'
    . ' DUE_PACK_PREFIX_10=PASS'
    . ' DUE_PACK_PREFIX_30=PASS'
    . ' DUE_PACK_PREFIX_59=PASS'
    . ' DOWNSTREAM_RECALC_SOURCE_PROCESSED=PASS'
    . ' DOWNSTREAM_RECALC_POINTER_COMPLETED=PASS'
    . ' WORKER_EFFECTIVE=1'
    . ' PACK_EFFECTIVE_CONSUMED=0'
    . ' PACK_REMOTE_CALLS=0'
    . ' PACK_ATTEMPT_PENALTY=0'
    . ' DUE_PACK_P50_MS=' . round($p50, 3)
    . ' DUE_PACK_P95_MS=' . round($p95, 3)
    . ' DUE_PACK_P99_MS=' . round($p99, 3)
    . ' DUE_PACK_MAX_MS=' . round($max, 3)
    . ' PACK_INCOMPLETE_HTTP=0'
    . ' REAL_MELI_HTTP=0'
    . ($rawCsvPath !== '' ? ' RAW_CSV_FILE=' . $rawCsvPath : '')
    . PHP_EOL
);
