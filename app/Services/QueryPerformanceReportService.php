<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;

/**
 * Resume el perfil local sin conservar SQL, parámetros ni datos comerciales.
 * La ruta se normaliza al capturarla (IDs numéricos se convierten en :id).
 */
final class QueryPerformanceReportService
{
    /**
     * @return array{
     *   generated_at:string,
     *   source:string,
     *   invalid_records:int,
     *   routes:list<array<string,int|string|float>>
     * }
     */
    public function report(?string $path = null): array
    {
        $path ??= AppPaths::storage('logs/query-profile.jsonl');
        $groups = [];
        $invalid = 0;
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [
                'generated_at' => gmdate(DATE_ATOM),
                'source' => basename($path),
                'invalid_records' => 0,
                'routes' => [],
            ];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $record = json_decode(trim($line), true);
                if (!is_array($record) || !isset($record['route_hash'], $record['duration_ms'])) {
                    ++$invalid;
                    continue;
                }
                $hash = (string) $record['route_hash'];
                if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
                    ++$invalid;
                    continue;
                }
                $groups[$hash][] = $record;
            }
        } finally {
            fclose($handle);
        }

        $routes = [];
        foreach ($groups as $hash => $records) {
            $durations = array_map(
                static fn (array $row): int => max(0, (int) $row['duration_ms']),
                $records
            );
            sort($durations, SORT_NUMERIC);
            $routeKey = '/desconocida';
            foreach ($records as $record) {
                $candidate = (string) ($record['route_key'] ?? '');
                if (preg_match('#^/[A-Za-z0-9_/.:-]{0,159}$#', $candidate) === 1) {
                    $routeKey = $candidate;
                    break;
                }
            }
            $routes[] = [
                'route_key' => $routeKey,
                'route_hash' => $hash,
                'requests' => count($durations),
                'p50_ms' => $this->percentile($durations, 0.50),
                'p95_ms' => $this->percentile($durations, 0.95),
                'p99_ms' => $this->percentile($durations, 0.99),
                'max_ms' => $durations[array_key_last($durations)] ?? 0,
                'average_ms' => round(array_sum($durations) / count($durations), 2),
                'statements' => $this->sum($records, 'statements'),
                'rows_read' => $this->sum($records, 'rows_read'),
                'temporary_tables' => $this->sum($records, 'temporary_tables'),
                'disk_temporary_tables' => $this->sum($records, 'disk_temporary_tables'),
                'filesort_rows' => $this->sum($records, 'filesort_rows'),
            ];
        }
        usort(
            $routes,
            static fn (array $left, array $right): int =>
                [$right['p95_ms'], $right['requests']] <=> [$left['p95_ms'], $left['requests']]
        );

        return [
            'generated_at' => gmdate(DATE_ATOM),
            'source' => basename($path),
            'invalid_records' => $invalid,
            'routes' => $routes,
        ];
    }

    /** @param list<int> $values */
    private function percentile(array $values, float $percentile): int
    {
        $count = count($values);
        if ($count === 0) {
            return 0;
        }
        $position = (int) ceil($percentile * $count) - 1;
        return $values[max(0, min($count - 1, $position))];
    }

    /** @param list<array<string,mixed>> $records */
    private function sum(array $records, string $field): int
    {
        return array_sum(array_map(
            static fn (array $row): int => max(0, (int) ($row[$field] ?? 0)),
            $records
        ));
    }
}
