<?php

declare(strict_types=1);

use App\Services\QueryPerformanceReportService;

require_once dirname(__DIR__) . '/bootstrap.php';

$path = tempnam(sys_get_temp_dir(), 'meli-query-report-');
if ($path === false) {
    throw new RuntimeException('No fue posible crear el fixture temporal.');
}

$hash = str_repeat('a', 64);
$rows = [];
foreach ([10, 20, 30, 40, 100] as $duration) {
    $rows[] = json_encode([
        'route_hash' => $hash,
        'duration_ms' => $duration,
        'statements' => 2,
        'rows_read' => 3,
        'temporary_tables' => 0,
        'disk_temporary_tables' => 0,
        'filesort_rows' => 1,
    ], JSON_THROW_ON_ERROR);
}
file_put_contents($path, implode(PHP_EOL, $rows) . PHP_EOL . '{invalid' . PHP_EOL);

try {
    $report = (new QueryPerformanceReportService())->report($path);
    $route = $report['routes'][0] ?? [];
    assert(($report['invalid_records'] ?? 0) === 1);
    assert(($route['requests'] ?? 0) === 5);
    assert(($route['p50_ms'] ?? 0) === 30);
    assert(($route['p95_ms'] ?? 0) === 100);
    assert(($route['p99_ms'] ?? 0) === 100);
    assert(($route['statements'] ?? 0) === 10);
    assert(($route['rows_read'] ?? 0) === 15);
    assert(($route['filesort_rows'] ?? 0) === 5);
} finally {
    @unlink($path);
}

echo "query_performance_report_ok\n";
