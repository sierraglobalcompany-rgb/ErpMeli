<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/** Registra entradas declaradas por el productor; nunca las deduce del backlog. */
final class CronProducerMetricService
{
    public function recordForRunId(
        int $runId,
        string $queueKey,
        ?int $newlyDiscovered,
        ?int $deduplicated,
        string $producerKey = 'callback'
    ): void {
        if ($runId <= 0 || $queueKey === '' || ($newlyDiscovered === null && $deduplicated === null)
            || !(new SchemaInspectorService())->hasTable('system_cron_producer_metrics')) {
            return;
        }
        try {
            $pdo = Database::connectionFresh();
            $stmt = $pdo->prepare('SELECT run_token FROM system_work_queue_runs WHERE id=? LIMIT 1');
            $stmt->execute([$runId]);
            $runToken = trim((string) $stmt->fetchColumn());
            if ($runToken === '') {
                return;
            }
            $pdo->prepare(
                'INSERT INTO system_cron_producer_metrics
                 (run_token,queue_key,producer_key,newly_discovered,deduplicated,created_at)
                 VALUES (?,?,?,?,?,UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE
                    newly_discovered=IF(VALUES(newly_discovered) IS NULL,newly_discovered,VALUES(newly_discovered)),
                    deduplicated=IF(VALUES(deduplicated) IS NULL,deduplicated,VALUES(deduplicated)),
                    created_at=VALUES(created_at)'
            )->execute([
                $runToken,
                mb_substr($queueKey, 0, 80),
                mb_substr($producerKey, 0, 80),
                $newlyDiscovered !== null ? max(0, $newlyDiscovered) : null,
                $deduplicated !== null ? max(0, $deduplicated) : null,
            ]);
        } catch (Throwable) {
            // La medición nunca altera el resultado del productor.
        }
    }

    /** @return array<string,array{newly_discovered:?int,deduplicated:?int}> */
    public function forRun(PDO $pdo, string $runToken): array
    {
        if ($runToken === '' || !(new SchemaInspectorService())->hasTable('system_cron_producer_metrics')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT queue_key,
                    SUM(newly_discovered) newly_discovered,
                    SUM(deduplicated) deduplicated
             FROM system_cron_producer_metrics
             WHERE run_token=? GROUP BY queue_key'
        );
        $stmt->execute([$runToken]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['queue_key']] = [
                'newly_discovered' => $row['newly_discovered'] !== null
                    ? max(0, (int) $row['newly_discovered'])
                    : null,
                'deduplicated' => $row['deduplicated'] !== null
                    ? max(0, (int) $row['deduplicated'])
                    : null,
            ];
        }
        return $result;
    }
}
