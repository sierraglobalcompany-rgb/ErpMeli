<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/** Persiste mediciones reales; nunca infiere backlog a partir de completados. */
final class CronBacklogSnapshotService
{
    /** @var list<string> */
    private const NON_BACKLOG_TASKS = [
        'operational_maintenance',
        'monthly_report_maintenance',
    ];

    public static function countsTowardBacklog(string $queueKey): bool
    {
        return $queueKey !== '' && !in_array($queueKey, self::NON_BACKLOG_TASKS, true);
    }

    /** @param array<string,array<string,mixed>> $availability */
    public function record(string $runToken, array $availability): void
    {
        if ($runToken === '' || !(new SchemaInspectorService())->hasTable('system_cron_backlog_snapshots')) {
            return;
        }
        try {
            $pdo = Database::connectionFresh();
            $runMetrics = $this->runMetrics($pdo, $runToken);
            $snapshotRows = [];
            $stmt = $pdo->prepare(
                'INSERT INTO system_cron_backlog_snapshots
                 (run_token,queue_key,measurement_state,coverage,total_pending,eligible_now,waiting_schedule,running_count,attention_count,
                  previous_pending,newly_discovered,deduplicated,finalized,current_pending,http_dispatched,known_responses,resources_received,equation_state,measured_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE
                    measurement_state=VALUES(measurement_state),coverage=VALUES(coverage),total_pending=VALUES(total_pending),
                    eligible_now=VALUES(eligible_now),waiting_schedule=VALUES(waiting_schedule),
                    running_count=VALUES(running_count),attention_count=VALUES(attention_count),
                    previous_pending=VALUES(previous_pending),newly_discovered=VALUES(newly_discovered),
                    deduplicated=VALUES(deduplicated),finalized=VALUES(finalized),current_pending=VALUES(current_pending),
                    http_dispatched=VALUES(http_dispatched),known_responses=VALUES(known_responses),
                    resources_received=VALUES(resources_received),equation_state=VALUES(equation_state),
                    measured_at=VALUES(measured_at)'
            );
            foreach ($availability as $queueKey => $row) {
                if (!self::countsTowardBacklog((string) $queueKey)) {
                    continue;
                }
                $known = !empty($row['known']);
                $state = $known ? (string) ($row['measurement_state'] ?? 'complete') : 'unavailable';
                $coverage = $state === 'complete' ? 'total' : ($state === 'partial' ? 'eligible_only' : 'none');
                $current = $known ? max(0, (int) ($row['total_pending'] ?? $row['work_count'] ?? 0)) : null;
                $previous = $this->previousPending($pdo, (string) $queueKey, $runToken);
                $metrics = $runMetrics[(string) $queueKey] ?? [
                    // Si el ciclo no midió entradas o deduplicación no se
                    // inventa un cero: la ecuación queda explícitamente no
                    // disponible hasta contar con ambos extremos.
                    'newly_discovered' => null,
                    'deduplicated' => null,
                    'finalized' => 0,
                    'http_dispatched' => 0,
                    'known_responses' => 0,
                    'resources_received' => 0,
                ];
                $equationState = self::equationState(
                    $previous,
                    $metrics['newly_discovered'],
                    $metrics['finalized'],
                    $current
                );
                $stmt->execute([
                    $runToken,
                    (string) $queueKey,
                    $state,
                    $coverage,
                    $current,
                    $known ? max(0, (int) ($row['eligible_count'] ?? $row['work_count'] ?? 0)) : null,
                    $known ? max(0, (int) ($row['waiting_schedule'] ?? 0)) : null,
                    $known ? max(0, (int) ($row['running_count'] ?? 0)) : null,
                    $known ? max(0, (int) ($row['attention_count'] ?? 0)) : null,
                    $previous,
                    $metrics['newly_discovered'],
                    $metrics['deduplicated'],
                    $metrics['finalized'],
                    $current,
                    $metrics['http_dispatched'],
                    $metrics['known_responses'],
                    $metrics['resources_received'],
                    $equationState,
                ]);
                $snapshotRows[] = [
                    'queue_key' => (string) $queueKey,
                    'measurement_state' => $state,
                    'coverage' => $coverage,
                    'total_pending' => $current,
                    'previous_pending' => $previous,
                    'newly_discovered' => $metrics['newly_discovered'],
                    'deduplicated' => $metrics['deduplicated'],
                    'finalized' => $metrics['finalized'],
                    'current_pending' => $current,
                    'http_dispatched' => $metrics['http_dispatched'],
                    'known_responses' => $metrics['known_responses'],
                    'resources_received' => $metrics['resources_received'],
                    'equation_state' => $equationState,
                ];
            }
            $this->recordRunTotal($pdo, $runToken, $snapshotRows);
        } catch (Throwable) {
            // La telemetría nunca interrumpe el trabajo de negocio.
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function recordRunTotal(PDO $pdo, string $runToken, array $rows): void
    {
        if ($rows === [] || !(new SchemaInspectorService())->hasTable('system_cron_backlog_run_totals')) {
            return;
        }
        usort($rows, static fn (array $left, array $right): int => strcmp(
            (string) ($left['queue_key'] ?? ''),
            (string) ($right['queue_key'] ?? '')
        ));
        $unavailable = count(array_filter($rows, static fn (array $row): bool =>
            ($row['measurement_state'] ?? '') === 'unavailable' || ($row['coverage'] ?? '') === 'none'
        ));
        $partial = count(array_filter($rows, static fn (array $row): bool =>
            ($row['measurement_state'] ?? '') === 'partial' || ($row['coverage'] ?? '') === 'eligible_only'
        ));
        $complete = count(array_filter($rows, static fn (array $row): bool =>
            ($row['measurement_state'] ?? '') === 'complete' && ($row['coverage'] ?? '') === 'total'
        ));
        $signature = implode('|', array_map(static fn (array $row): string => implode(':', [
            (string) ($row['queue_key'] ?? ''),
            (string) ($row['measurement_state'] ?? 'unavailable'),
            (string) ($row['coverage'] ?? 'none'),
        ]), $rows));
        $sum = static function (array $source, string $key): ?int {
            $value = 0;
            $found = false;
            foreach ($source as $row) {
                if (!array_key_exists($key, $row) || $row[$key] === null) {
                    continue;
                }
                $value += max(0, (int) $row[$key]);
                $found = true;
            }
            return $found ? $value : null;
        };
        $totalPending = array_sum(array_map(static fn (array $row): int =>
            ($row['measurement_state'] ?? '') === 'complete' && ($row['coverage'] ?? '') === 'total'
                ? max(0, (int) ($row['total_pending'] ?? 0))
                : 0,
            $rows
        ));
        $equationComplete = count(array_filter($rows, static fn (array $row): bool => ($row['equation_state'] ?? '') === 'complete'));
        $equationPartial = count(array_filter($rows, static fn (array $row): bool => ($row['equation_state'] ?? '') === 'partial'));
        $stmt = $pdo->prepare(
            'INSERT INTO system_cron_backlog_run_totals
             (run_token,measurement_state,coverage_signature,total_pending,measured_queues,partial_queues,unavailable_queues,
              previous_pending,current_pending,newly_discovered,deduplicated,finalized,http_dispatched,known_responses,
              resources_received,equation_complete_queues,equation_partial_queues,measured_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
                measurement_state=VALUES(measurement_state),coverage_signature=VALUES(coverage_signature),
                total_pending=VALUES(total_pending),measured_queues=VALUES(measured_queues),partial_queues=VALUES(partial_queues),
                unavailable_queues=VALUES(unavailable_queues),previous_pending=VALUES(previous_pending),
                current_pending=VALUES(current_pending),newly_discovered=VALUES(newly_discovered),deduplicated=VALUES(deduplicated),
                finalized=VALUES(finalized),http_dispatched=VALUES(http_dispatched),known_responses=VALUES(known_responses),
                resources_received=VALUES(resources_received),equation_complete_queues=VALUES(equation_complete_queues),
                equation_partial_queues=VALUES(equation_partial_queues),measured_at=VALUES(measured_at)'
        );
        $stmt->execute([
            $runToken,
            $unavailable > 0 ? 'unavailable' : ($partial > 0 ? 'partial' : 'complete'),
            $signature,
            $totalPending,
            $complete,
            $partial,
            $unavailable,
            $sum($rows, 'previous_pending'),
            $sum($rows, 'current_pending'),
            $sum($rows, 'newly_discovered'),
            $sum($rows, 'deduplicated'),
            $sum($rows, 'finalized'),
            $sum($rows, 'http_dispatched'),
            $sum($rows, 'known_responses'),
            $sum($rows, 'resources_received'),
            $equationComplete,
            $equationPartial,
        ]);
    }

    /** @return array<string,array<string,int|null>> */
    private function runMetrics(PDO $pdo, string $runToken): array
    {
        $stmt = $pdo->prepare(
            'SELECT i.queue_key,
                    COALESCE(SUM(i.completed_count),0) finalized,
                    COALESCE(SUM(i.actual_api_calls),0) http_dispatched,
                    COALESCE(SUM(i.known_response_count),0) known_responses,
                    COALESCE(SUM(i.resources_received_count),0) resources_received
             FROM system_work_queue_runs r
             JOIN system_work_queue_run_items i ON i.work_queue_run_id=r.id
             WHERE r.run_token=?
             GROUP BY i.queue_key'
        );
        $stmt->execute([$runToken]);
        $producerMetrics = (new CronProducerMetricService())->forRun($pdo, $runToken);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $producer = $producerMetrics[(string) $row['queue_key']] ?? [
                'newly_discovered' => null,
                'deduplicated' => null,
            ];
            $result[(string) $row['queue_key']] = [
                'newly_discovered' => $producer['newly_discovered'],
                'deduplicated' => $producer['deduplicated'],
                'finalized' => max(0, (int) $row['finalized']),
                'http_dispatched' => max(0, (int) $row['http_dispatched']),
                'known_responses' => max(0, (int) $row['known_responses']),
                'resources_received' => max(0, (int) $row['resources_received']),
            ];
        }
        return $result;
    }

    private function previousPending(PDO $pdo, string $queueKey, string $runToken): ?int
    {
        $stmt = $pdo->prepare(
            'SELECT current_pending
             FROM system_cron_backlog_snapshots
             WHERE queue_key=? AND run_token<>? AND measurement_state="complete" AND current_pending IS NOT NULL
             ORDER BY measured_at DESC,id DESC LIMIT 1'
        );
        $stmt->execute([$queueKey, $runToken]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : max(0, (int) $value);
    }

    /** @return list<array<string,mixed>> */
    public function recentTotals(int $limit = 2): array
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('system_cron_backlog_snapshots')) {
            return [];
        }
        $limit = max(2, min(10, $limit));
        try {
            if ($schema->hasTable('system_cron_backlog_run_totals')) {
                $stmt = Database::connectionFresh()->prepare(
                    'SELECT run_token,total_pending,measured_queues,partial_queues,unavailable_queues,
                            coverage_signature,previous_pending,current_pending,newly_discovered,deduplicated,
                            finalized,http_dispatched,known_responses,resources_received,equation_complete_queues,
                            equation_partial_queues,measurement_state,measured_at
                     FROM system_cron_backlog_run_totals
                     ORDER BY measured_at DESC,run_token DESC
                     LIMIT :limit'
                );
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($rows !== []) {
                    return array_map(fn (array $row): array => $this->presentTotal($row), $rows);
                }
            }
            return $this->recentTotalsLegacy($limit);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function recentTotalsLegacy(int $limit): array
    {
        try {
            // La compatibilidad anterior queda acotada a los IDs más recientes;
            // nunca vuelve a agrupar el historial completo de snapshots.
            $sampleLimit = max(100, min(1000, $limit * 100));
            $tokenStmt = Database::connectionFresh()->prepare(
                'SELECT run_token
                 FROM system_cron_backlog_snapshots
                 ORDER BY id DESC LIMIT :sample_limit'
            );
            $tokenStmt->bindValue(':sample_limit', $sampleLimit, PDO::PARAM_INT);
            $tokenStmt->execute();
            $tokens = [];
            foreach ($tokenStmt->fetchAll(PDO::FETCH_COLUMN) as $token) {
                $token = (string) $token;
                if ($token !== '' && !isset($tokens[$token])) {
                    $tokens[$token] = true;
                    if (count($tokens) >= $limit) {
                        break;
                    }
                }
            }
            if ($tokens === []) {
                return [];
            }
            $stmt = Database::connectionFresh()->prepare(
                'SELECT run_token,
                        COALESCE(SUM(CASE WHEN measurement_state="complete" AND coverage="total" THEN total_pending ELSE 0 END),0) total_pending,
                        SUM(measurement_state="complete" AND coverage="total") measured_queues,
                        SUM(measurement_state="partial" OR coverage="eligible_only") partial_queues,
                        SUM(measurement_state="unavailable" OR coverage="none") unavailable_queues,
                        GROUP_CONCAT(CONCAT(queue_key,":",measurement_state,":",coverage) ORDER BY queue_key SEPARATOR "|") coverage_signature,
                        SUM(previous_pending) previous_pending,
                        SUM(current_pending) current_pending,
                        SUM(newly_discovered) newly_discovered,
                        SUM(deduplicated) deduplicated,
                        SUM(finalized) finalized,
                        SUM(http_dispatched) http_dispatched,
                        SUM(known_responses) known_responses,
                        SUM(resources_received) resources_received,
                        SUM(equation_state="complete") equation_complete_queues,
                        SUM(equation_state="partial") equation_partial_queues,
                        MAX(measured_at) measured_at
                 FROM system_cron_backlog_snapshots
                 WHERE queue_key NOT IN ("operational_maintenance","monthly_report_maintenance")
                   AND run_token IN (' . implode(',', array_fill(0, count($tokens), '?')) . ')
                 GROUP BY run_token
                 ORDER BY MAX(measured_at) DESC
                 LIMIT ' . $limit
            );
            $stmt->execute(array_keys($tokens));
            return array_map(fn (array $row): array => $this->presentTotal($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function presentTotal(array $row): array
    {
        $unavailable = max(0, (int) ($row['unavailable_queues'] ?? 0));
        $partial = max(0, (int) ($row['partial_queues'] ?? 0));
        return [
            'run_token' => (string) $row['run_token'],
            'total_pending' => max(0, (int) $row['total_pending']),
            'measured_queues' => max(0, (int) $row['measured_queues']),
            'partial_queues' => $partial,
            'unavailable_queues' => $unavailable,
            'coverage_signature' => (string) ($row['coverage_signature'] ?? ''),
            'previous_pending' => $row['previous_pending'] !== null ? max(0, (int) $row['previous_pending']) : null,
            'current_pending' => $row['current_pending'] !== null ? max(0, (int) $row['current_pending']) : null,
            'newly_discovered' => $row['newly_discovered'] !== null ? max(0, (int) $row['newly_discovered']) : null,
            'deduplicated' => $row['deduplicated'] !== null ? max(0, (int) $row['deduplicated']) : null,
            'finalized' => $row['finalized'] !== null ? max(0, (int) $row['finalized']) : null,
            'http_dispatched' => $row['http_dispatched'] !== null ? max(0, (int) $row['http_dispatched']) : null,
            'known_responses' => $row['known_responses'] !== null ? max(0, (int) $row['known_responses']) : null,
            'resources_received' => $row['resources_received'] !== null ? max(0, (int) $row['resources_received']) : null,
            'equation_complete_queues' => max(0, (int) ($row['equation_complete_queues'] ?? 0)),
            'equation_partial_queues' => max(0, (int) ($row['equation_partial_queues'] ?? 0)),
            'measurement_state' => (string) ($row['measurement_state'] ?? ($unavailable > 0
                ? 'unavailable'
                : ($partial > 0 ? 'partial' : 'complete'))),
            'measured_at' => !empty($row['measured_at']) ? (string) $row['measured_at'] : null,
        ];
    }

    public static function trend(int $before, int $after): string
    {
        return match (true) {
            $after === 0 => 'empty',
            $after < $before => 'draining',
            $after > $before => 'growing',
            default => 'stable',
        };
    }

    public static function equationState(?int $previous, ?int $newlyDiscovered, ?int $finalized, ?int $current): string
    {
        if ($previous === null || $newlyDiscovered === null || $finalized === null || $current === null) {
            return 'unavailable';
        }
        return $previous + $newlyDiscovered - $finalized === $current ? 'complete' : 'partial';
    }

    /** @return array{trend:string,delta:?int,comparable:bool} */
    public static function windowTrend(?array $older, ?array $newer): array
    {
        $comparable = is_array($older) && is_array($newer)
            && ($older['measurement_state'] ?? '') === 'complete'
            && ($newer['measurement_state'] ?? '') === 'complete'
            && ($older['coverage_signature'] ?? '') !== ''
            && ($older['coverage_signature'] ?? '') === ($newer['coverage_signature'] ?? '');
        if (!$comparable) {
            return ['trend' => 'unknown', 'delta' => null, 'comparable' => false];
        }
        $before = max(0, (int) ($older['total_pending'] ?? 0));
        $after = max(0, (int) ($newer['total_pending'] ?? 0));
        return ['trend' => self::trend($before, $after), 'delta' => $after - $before, 'comparable' => true];
    }

    /** @return array{minutes_15:array<string,mixed>,minutes_60:array<string,mixed>} */
    public function trendWindows(): array
    {
        $samples = $this->recentTotals(10);
        $latest = $samples[0] ?? null;
        return [
            'minutes_15' => $this->trendAtMinutes($latest, 15),
            'minutes_60' => $this->trendAtMinutes($latest, 60),
        ];
    }

    /** @return array<string,mixed> */
    private function trendAtMinutes(?array $latest, int $minutes): array
    {
        if (!is_array($latest) || empty($latest['measured_at'])) {
            return ['trend' => 'unknown', 'delta' => null, 'comparable' => false, 'minutes' => $minutes];
        }
        try {
            if ((new SchemaInspectorService())->hasTable('system_cron_backlog_run_totals')) {
                $stmt = Database::connectionFresh()->prepare(
                    'SELECT run_token,total_pending,measured_queues,partial_queues,unavailable_queues,
                            coverage_signature,previous_pending,current_pending,newly_discovered,deduplicated,
                            finalized,http_dispatched,known_responses,resources_received,equation_complete_queues,
                            equation_partial_queues,measurement_state,measured_at
                     FROM system_cron_backlog_run_totals
                     WHERE measured_at<=DATE_SUB(?,INTERVAL ' . max(1, $minutes) . ' MINUTE)
                     ORDER BY measured_at DESC,run_token DESC LIMIT 1'
                );
                $stmt->execute([(string) $latest['measured_at']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $older = is_array($row) ? $this->presentTotal($row) : null;
                return array_merge(self::windowTrend($older, $latest), [
                    'minutes' => $minutes,
                    'from_at' => $older['measured_at'] ?? null,
                    'to_at' => $latest['measured_at'],
                ]);
            }
            $stmt = Database::connectionFresh()->prepare(
                'SELECT run_token,
                        COALESCE(SUM(CASE WHEN measurement_state="complete" AND coverage="total" THEN total_pending ELSE 0 END),0) total_pending,
                        SUM(measurement_state="complete" AND coverage="total") measured_queues,
                        SUM(measurement_state="partial" OR coverage="eligible_only") partial_queues,
                        SUM(measurement_state="unavailable" OR coverage="none") unavailable_queues,
                        GROUP_CONCAT(CONCAT(queue_key,":",measurement_state,":",coverage) ORDER BY queue_key SEPARATOR "|") coverage_signature,
                        MAX(measured_at) measured_at
                 FROM system_cron_backlog_snapshots
                 WHERE queue_key NOT IN ("operational_maintenance","monthly_report_maintenance")
                 GROUP BY run_token
                 HAVING MAX(measured_at)<=DATE_SUB(?,INTERVAL ' . max(1, $minutes) . ' MINUTE)
                 ORDER BY MAX(measured_at) DESC LIMIT 1'
            );
            $stmt->execute([(string) $latest['measured_at']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $older = is_array($row) ? [
                'total_pending' => max(0, (int) $row['total_pending']),
                'coverage_signature' => (string) $row['coverage_signature'],
                'measurement_state' => ((int) $row['unavailable_queues']) > 0
                    ? 'unavailable'
                    : (((int) $row['partial_queues']) > 0 ? 'partial' : 'complete'),
                'measured_at' => (string) $row['measured_at'],
            ] : null;
            return array_merge(self::windowTrend($older, $latest), [
                'minutes' => $minutes,
                'from_at' => $older['measured_at'] ?? null,
                'to_at' => $latest['measured_at'],
            ]);
        } catch (Throwable) {
            return ['trend' => 'unknown', 'delta' => null, 'comparable' => false, 'minutes' => $minutes];
        }
    }
}
