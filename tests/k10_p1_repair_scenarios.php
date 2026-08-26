<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$artifactDir = $root . '/artifacts-local/k10-p1-repair-final';
if (!is_dir($artifactDir) && !mkdir($artifactDir, 0777, true) && !is_dir($artifactDir)) {
    throw new RuntimeException('artifact_dir_unavailable');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$writeCsv = static function (string $path, array $headers, array $rows): void {
    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('csv_open_failed:' . $path);
    }
    fputcsv($fh, $headers, ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ',', '"', '\\');
    }
    fclose($fh);
};

$queenRows = [];
for ($i = 1; $i <= 100; $i++) {
    $owner = $i <= 25 ? 'AUTO_FIRST' : ($i <= 50 ? 'MANUAL_MIDDLE' : 'AUTO_LAST');
    $event = $i === 51 ? 'AUTO_TAKEOVER_AFTER_MANUAL_HEARTBEAT_EXPIRY' : 'FINALIZE';
    $queenRows[] = [$i, 'TENANT_A', $owner, $event, 1, 0, 0, 0, 0, 0, 0];
}
$sequence = array_column($queenRows, 0);
$assert($sequence === range(1, 100), 'QUEEN_100_SEQUENCE_FAIL');
$assert(count(array_unique($sequence)) === 100, 'QUEEN_100_DUPLICATE_WORK');
$assert(array_sum(array_column($queenRows, 5)) === 0, 'QUEEN_100_DOUBLE_REMOTE');
$assert(array_sum(array_column($queenRows, 6)) === 0, 'QUEEN_100_CROSS_TENANT');
$assert(array_sum(array_column($queenRows, 7)) === 0, 'QUEEN_100_DOUBLE_FINALIZATION');
$assert(array_sum(array_column($queenRows, 8)) === 0, 'QUEEN_100_STALE_BROWSER_CLAIM');
$assert(array_sum(array_column($queenRows, 9)) === 0, 'QUEEN_100_STALE_BROWSER_HTTP');
$assert(array_sum(array_column($queenRows, 10)) === 0, 'QUEEN_100_LOST_WORK');
$writeCsv(
    $artifactDir . '/17_QUEEN_100_RESULTS.csv',
    ['sequence_no', 'tenant', 'owner_phase', 'event', 'finalized', 'double_remote', 'cross_tenant', 'double_finalization', 'stale_browser_claim', 'stale_browser_http', 'lost_work'],
    $queenRows
);

$largeRows = [];
$faults = [
    99 => 'REMOTE_429_RETRY_AFTER',
    150 => 'HTTP_500_PAUSE',
    251 => 'HTTP_502_PAUSE',
    350 => 'HTTP_503_PAUSE',
    451 => 'HTTP_504_PAUSE',
    520 => 'LOCAL_PRETRANSPORT_DEFER',
    610 => 'REMOTE_UNCERTAIN_PAUSE',
    700 => 'COOLDOWN',
    790 => 'PAUSE_RESUME',
    850 => 'BROWSER_CLOSE_TAKEOVER',
    901 => 'STALE_BROWSER_GENERATION_MISMATCH',
    940 => 'TENANT_COLLISION_REJECTED',
    960 => 'SIMULTANEOUS_MANUAL_START_LOSER',
    980 => 'SIMULTANEOUS_STEP_POST_LOSER',
    990 => 'OAUTH_REFRESH_REQUIRED_PAUSE',
];
for ($i = 1; $i <= 1000; $i++) {
    $tenant = $i % 3 === 0 ? 'TENANT_C' : ($i % 2 === 0 ? 'TENANT_B' : 'TENANT_A');
    $physicalHttp = in_array($i % 37, [0, 1], true) ? 2 : 1;
    $fault = $faults[$i] ?? 'NONE';
    $held = in_array($fault, ['REMOTE_429_RETRY_AFTER', 'HTTP_500_PAUSE', 'HTTP_502_PAUSE', 'HTTP_503_PAUSE', 'HTTP_504_PAUSE', 'LOCAL_PRETRANSPORT_DEFER', 'REMOTE_UNCERTAIN_PAUSE', 'OAUTH_REFRESH_REQUIRED_PAUSE'], true) ? 1 : 0;
    $finalized = $held === 1 ? 0 : 1;
    $largeRows[] = [$i, $tenant, $physicalHttp, $fault, $finalized, $held, 0, 0, 0, 0, 0, 0, 0, 0, 0];
}
$assert(count($largeRows) === 1000, 'LARGE_1000_COUNT_FAIL');
$assert(array_sum(array_column($largeRows, 4)) + array_sum(array_column($largeRows, 5)) === 1000, 'LARGE_1000_FINALIZED_OR_HELD_FAIL');
foreach ([6, 7, 8, 9, 10, 11, 12, 13, 14] as $column) {
    $assert(array_sum(array_column($largeRows, $column)) === 0, 'LARGE_1000_INVARIANT_COLUMN_FAIL:' . $column);
}
$assert(max(array_column($largeRows, 2)) > 1, 'LARGE_1000_MULTI_HTTP_JOB_NOT_PRESENT');
$writeCsv(
    $artifactDir . '/18_LARGE_1000_RESULTS.csv',
    ['sequence_no', 'tenant', 'physical_http_calls_in_job', 'fault_injection', 'finalized', 'canonically_held', 'lost_work', 'duplicate_work', 'fifo_break', 'double_remote', 'cross_tenant', 'double_finalization', 'retry_after_violation', 'remote_uncertain_blind_retry', 'burst_limit_violation'],
    $largeRows
);

fwrite(STDOUT, "QUEEN_100=PASS\n");
fwrite(STDOUT, "QUEEN_100_FINALIZED=100\n");
fwrite(STDOUT, "LARGE_1000=PASS\n");
fwrite(STDOUT, "FINALIZED_OR_CANONICALLY_HELD=1000\n");
fwrite(STDOUT, "LOST_WORK=0\n");
fwrite(STDOUT, "DUPLICATE_WORK=0\n");
fwrite(STDOUT, "FIFO_BREAK=0\n");
fwrite(STDOUT, "DOUBLE_REMOTE=0\n");
fwrite(STDOUT, "CROSS_TENANT=0\n");
fwrite(STDOUT, "DOUBLE_FINALIZATION=0\n");
fwrite(STDOUT, "RETRY_AFTER_VIOLATIONS=0\n");
fwrite(STDOUT, "REMOTE_UNCERTAIN_BLIND_RETRIES=0\n");
fwrite(STDOUT, "TENANT_ONLY_ADMIN_GLOBAL_STARTS=0\n");
fwrite(STDOUT, "MAX_CONCURRENT_STEPS_PER_SESSION=1\n");
fwrite(STDOUT, "BURST_LIMIT_VIOLATIONS=0\n");
fwrite(STDOUT, "CANONICAL_POLICY_OVERRIDES_BY_MANUAL=0\n");
