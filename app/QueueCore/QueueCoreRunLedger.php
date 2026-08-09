<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;

/** Registro acotado de una invocación V4; nunca contiene payloads ni identidades. */
final class QueueCoreRunLedger
{
    public function __construct(private readonly PDO $pdo) {}

    public function begin(int $generation, string $launcher, string $workerId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_core_runs
             (engine_generation,launcher,worker_ref,status,phase)
             VALUES (?,?,?,"running","bootstrap")'
        );
        $statement->execute([
            max(0, $generation),
            mb_substr($launcher, 0, 40),
            hash('sha256', $workerId),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id < 1) {
            throw new RuntimeException('Queue Core run receipt could not be created.');
        }
        return $id;
    }

    public function phase(int $runId, string $phase): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE queue_core_runs SET phase=?,last_heartbeat_at=UTC_TIMESTAMP(3)
             WHERE id=? AND status="running"'
        );
        $statement->execute([mb_substr($phase, 0, 60), $runId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Queue Core run receipt lost ownership.');
        }
    }

    /** @param array<string,mixed> $summary */
    public function finish(int $runId, string $status, string $reason, array $summary): void
    {
        $allowed = ['completed', 'stopped', 'failed', 'lease_lost'];
        $status = in_array($status, $allowed, true) ? $status : 'failed';
        $run = is_array($summary['run'] ?? null) ? $summary['run'] : $summary;
        $attempts = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT job_id) measured_claimed,
                    COALESCE(SUM(physical_http_calls),0) physical_http,
                    COALESCE(SUM(response_known_at IS NOT NULL),0) known_responses,
                    COALESCE(SUM(resources_persisted),0) resources_persisted
             FROM queue_core_attempts WHERE run_id=?'
        );
        $attempts->execute([$runId]);
        $measured = $attempts->fetch(PDO::FETCH_ASSOC) ?: [];
        $statement = $this->pdo->prepare(
            'UPDATE queue_core_runs
             SET status=?,close_reason=?,phase="finished",jobs_claimed=?,
                 physical_http_calls=?,known_responses=?,resources_persisted=?,
                 finished_at=UTC_TIMESTAMP(3),last_heartbeat_at=UTC_TIMESTAMP(3)
             WHERE id=? AND status="running"'
        );
        $statement->execute([
            $status,
            mb_substr($reason, 0, 100),
            max(
                0,
                (int) ($run['claimed'] ?? 0),
                (int) ($measured['measured_claimed'] ?? 0),
            ),
            max(0, (int) ($measured['physical_http'] ?? 0)),
            max(0, (int) ($measured['known_responses'] ?? 0)),
            max(0, (int) ($measured['resources_persisted'] ?? 0)),
            $runId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Queue Core run receipt could not be finalized.');
        }
    }
}
