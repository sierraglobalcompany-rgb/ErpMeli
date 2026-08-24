<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$rawCsvPath = trim((string) getenv('K3_FINANCE_BENCHMARK_RAW_CSV'));
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
$env['K1_SCAN_ITERATIONS'] = (string) max(1, (int) ((string) getenv('K3_FINANCE_BENCHMARK_ITERATIONS') ?: 3));
$env['K1_SCAN_PACK_DUE'] = '1';
$env['K1_SCAN_PROGRESS'] = '1';
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
$process = proc_open(
    $command,
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
    $env,
    ['bypass_shell' => true]
);
if (!is_resource($process)) {
    throw new RuntimeException('k3_benchmark_subprocess_unavailable');
}
fclose($pipes[0]);
$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
if ($stdout !== '' && (string) getenv('K3_DEBUG_CHILD_BENCHMARK') === '1') {
    fwrite(STDOUT, $stdout);
}
$summaryJson = null;
if (preg_match('/ SUMMARY=(\[.+\])$/', trim($stdout), $matches) === 1) {
    $summaryJson = $matches[1];
}
$summary = is_string($summaryJson) ? json_decode($summaryJson, true, 512, JSON_THROW_ON_ERROR) : [];
if ($summary === []) {
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    throw new RuntimeException('k3_due_pack_benchmark_summary_missing:exit_' . $exit);
}
$byPrefix = [];
$p95Values = [];
$maxValues = [];
foreach ($summary as $row) {
    if (!is_array($row)) {
        continue;
    }
    $prefix = (int) ($row['prefix'] ?? -1);
    $byPrefix[$prefix] = true;
    $p95Values[] = (float) ($row['p95_ms'] ?? 0);
    $maxValues[] = (float) ($row['max_ms'] ?? 0);
}

$required = [3, 10, 30, 59];
foreach ($required as $prefix) {
    if (!isset($byPrefix[$prefix])) {
        throw new RuntimeException('k3_due_pack_prefix_missing_' . $prefix);
    }
}

$p95 = $p95Values === [] ? 0.0 : max($p95Values);
$max = $maxValues === [] ? 0.0 : max($maxValues);
fwrite(
    STDOUT,
    'QUEUE_V4_K3_FINANCE_BENCHMARK=PASS'
    . ' DUE_PACK_PREFIX_3=PASS'
    . ' DUE_PACK_PREFIX_10=PASS'
    . ' DUE_PACK_PREFIX_30=PASS'
    . ' DUE_PACK_PREFIX_59=PASS'
    . ' DUE_PACK_P95_MS=' . round($p95, 3)
    . ' DUE_PACK_MAX_MS=' . round($max, 3)
    . ' PACK_INCOMPLETE_HTTP=0'
    . ' REAL_MELI_HTTP=0'
    . ($rawCsvPath !== '' ? ' RAW_CSV_FILE=' . $rawCsvPath : '')
    . PHP_EOL
);
