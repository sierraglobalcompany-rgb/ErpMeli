<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\QueryPerformanceReportService;

$path = isset($argv[1]) && trim((string) $argv[1]) !== '' ? (string) $argv[1] : null;
$report = (new QueryPerformanceReportService())->report($path);

fwrite(
    STDOUT,
    (json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')
    . PHP_EOL
);
