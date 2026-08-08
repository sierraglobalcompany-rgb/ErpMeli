<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class CronV3WorkRepository
{
    private ?bool $hasArrivalSeq = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<string> $acceptedTypes */
    public function claimOne(string $lane, array $acceptedTypes, int $leaseSeconds = 90): ?WorkEnvelope
    {
        $this->assertLane($lane);
        $acceptedTypes = array_values(array_unique(array_filter($acceptedTypes)));
        if ($acceptedTypes === []) {
            return null;
        }
        $leaseSeconds = max(30, min(600, $leaseSeconds));
        $ownerToken = bin2hex(random_bytes(32));

        $this->recoverExpired($lane);
        for ($transactionAttempt = 1; $transactionAttempt <= 5; $transactionAttempt++) {
        $this->pdo->beginTransaction();
        try {
            $snapshotKey = 'fairness:' . $lane;
            $this->pdo->prepare(
                'INSERT IGNORE INTO cron_v3_snapshots
                 (snapshot_key,snapshot_type,lane,payload_json,generation,observed_at)
                 VALUES (?,"fairness",?,"{}",0,UTC_TIMESTAMP(3))'
            )->execute([$snapshotKey, $lane]);
            $fairnessStatement = $this->pdo->prepare(
                'SELECT payload_json FROM cron_v3_snapshots WHERE snapshot_key=? FOR UPDATE'
            );
            $fairnessStatement->execute([$snapshotKey]);
            $fairness = $this->decode((string) $fairnessStatement->fetchColumn());
            $lastCompany = max(0, (int) ($fairness['company_id'] ?? 0));
            $lastAccount = max(0, (int) ($fairness['meli_account_id'] ?? 0));
            $consecutive = max(0, (int) ($fairness['consecutive'] ?? 0));
            $lastFamily = (string) ($fairness['work_family'] ?? '');
            $familyConsecutive = max(0, (int) ($fairness['family_consecutive'] ?? 0));

            $marks = implode(',', array_fill(0, count($acceptedTypes), '?'));
            $orderBy = $this->fifoOrderSql();
            $sql =
                'SELECT w.* FROM cron_v3_work w
                 INNER JOIN cron_v3_queue_ownership o
                   ON o.queue_key=w.work_type AND o.owner_engine="v3" AND o.enabled=1
                 WHERE w.lane=? AND w.status IN ("ready","deferred","waiting_rate","waiting_budget","waiting_api")
                   AND w.available_at<=UTC_TIMESTAMP(3) AND w.work_type IN (' . $marks . ')
                 ORDER BY
                   CASE WHEN ? >= 2 AND w.company_id=? AND w.meli_account_id=? THEN 1 ELSE 0 END,
                   CASE WHEN ? >= 3 AND w.work_family=? THEN 1 ELSE 0 END,
                   ' . $orderBy . '
                 LIMIT 1 FOR UPDATE';
            $candidate = $this->pdo->prepare($sql);
            $candidate->execute(array_merge(
                [$lane],
                $acceptedTypes,
                [
                    $consecutive,
                    $lastCompany,
                    $lastAccount,
                    $familyConsecutive,
                    $lastFamily,
                ],
            ));
            $row = $candidate->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }

            $generation = (int) $row['lease_generation'] + 1;
            $update = $this->pdo->prepare(
                'UPDATE cron_v3_work
                 SET status="leased",owner_token=?,lease_generation=?,
                     lease_until=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ' . $leaseSeconds . ' SECOND),
                     attempt_count=attempt_count+1,last_error_code=NULL,updated_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND status IN ("ready","deferred","waiting_rate","waiting_budget","waiting_api") AND lease_generation=?'
            );
            $update->execute([
                $ownerToken,
                $generation,
                (int) $row['id'],
                (int) $row['company_id'],
                (int) $row['meli_account_id'],
                (int) $row['lease_generation'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Cron V3 atomic claim was lost.');
            }

            $sameAccount = (int) $row['company_id'] === $lastCompany
                && (int) $row['meli_account_id'] === $lastAccount;
            $sameFamily = (string) $row['work_family'] === $lastFamily;
            $fairnessPayload = json_encode([
                'company_id' => (int) $row['company_id'],
                'meli_account_id' => (int) $row['meli_account_id'],
                'consecutive' => $sameAccount ? $consecutive + 1 : 1,
                'work_family' => (string) $row['work_family'],
                'family_consecutive' => $sameFamily ? $familyConsecutive + 1 : 1,
            ], JSON_UNESCAPED_SLASHES);
            $this->pdo->prepare(
                'UPDATE cron_v3_snapshots
                 SET payload_json=?,generation=generation+1,observed_at=UTC_TIMESTAMP(3)
                 WHERE snapshot_key=?'
            )->execute([$fairnessPayload ?: '{}', $snapshotKey]);

            $this->pdo->commit();

            $row['owner_token'] = $ownerToken;
            $row['lease_generation'] = $generation;
            $row['lease_until'] = gmdate('Y-m-d H:i:s', time() + $leaseSeconds);
            return WorkEnvelope::fromRow($row);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof PDOException
                && $transactionAttempt < 5
                && $this->isRetryableTransactionError($error)) {
                usleep(random_int(10000, 50000));
                continue;
            }
            throw $error;
        }
        }
        return null;
    }

    public function beginAttempt(WorkEnvelope $work): void
    {
        $this->assertClaim($work);
        $statement = $this->pdo->prepare(
            'INSERT INTO cron_v3_attempts
             (work_id,company_id,meli_account_id,owner_token,lease_generation,lane,outcome,
              logical_http_calls,started_at,updated_at)
             VALUES (?,?,?,?,?,?,"started",0,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))'
        );
        $statement->execute([
            $work->id,
            $work->companyId,
            $work->meliAccountId,
            $work->ownerToken,
            $work->leaseGeneration,
            $work->lane,
        ]);
    }

    public function finalize(
        WorkEnvelope $work,
        WorkResult $result,
        int $logicalHttpCalls,
        int $physicalHttpCalls = 0,
        int $knownResponses = 0,
        ?callable $afterFence = null,
    ): bool
    {
        $this->assertClaim($work);
        if ($logicalHttpCalls < 0 || $logicalHttpCalls > 1) {
            throw new RuntimeException('Cron V3 attempt exceeded its logical HTTP call budget.');
        }
        if ($physicalHttpCalls < 0 || $physicalHttpCalls > 1 || $knownResponses < 0 || $knownResponses > 1) {
            throw new RuntimeException('Cron V3 attempt exceeded its physical HTTP call budget.');
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                'UPDATE cron_v3_work
                 SET status=?,available_at=COALESCE(?,available_at),owner_token=NULL,lease_until=NULL,
                     last_error_code=?,completed_at=IF(?="completed",UTC_TIMESTAMP(3),completed_at),
                     updated_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND status="leased" AND owner_token=? AND lease_generation=?'
            );
            $update->execute([
                $this->storageStatus($result),
                $result->availableAt,
                $result->code,
                $result->status,
                $work->id,
                $work->companyId,
                $work->meliAccountId,
                $work->ownerToken,
                $work->leaseGeneration,
            ]);
            $wonFence = $update->rowCount() === 1;

            if ($wonFence && $afterFence !== null && $afterFence() !== true) {
                throw new RuntimeException('Cron V3 fenced side effects were not persisted.');
            }

            $attempt = $this->pdo->prepare(
                'UPDATE cron_v3_attempts
                 SET outcome=?,logical_http_calls=?,physical_http_calls=?,known_response_count=?,
                     result_json=?,finished_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE work_id=? AND company_id=? AND meli_account_id=?
                   AND owner_token=? AND lease_generation=? AND outcome="started"'
            );
            $attempt->execute([
                $wonFence ? $result->status : 'lease_lost',
                $logicalHttpCalls,
                $physicalHttpCalls,
                $knownResponses,
                $this->safeResultJson($result),
                $work->id,
                $work->companyId,
                $work->meliAccountId,
                $work->ownerToken,
                $work->leaseGeneration,
            ]);
            $this->pdo->commit();
            return $wonFence;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<string> $acceptedTypes @return list<array<string,mixed>> */
    public function preview(string $lane, array $acceptedTypes, int $limit = 50): array
    {
        $this->assertLane($lane);
        $acceptedTypes = array_values(array_unique(array_filter($acceptedTypes)));
        if ($acceptedTypes === []) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $marks = implode(',', array_fill(0, count($acceptedTypes), '?'));
        $arrivalSelect = $this->hasArrivalSeqColumn() ? 'COALESCE(w.arrival_seq,w.id)' : 'w.id';
        $orderBy = $this->fifoOrderSql();
        $statement = $this->pdo->prepare(
            'SELECT w.id,w.company_id,w.meli_account_id,w.work_type,w.lane,w.status,
                    w.priority,w.available_at,w.attempt_count,' . $arrivalSelect . ' arrival_seq,
                    COALESCE(o.owner_engine,"disabled") owner_engine,COALESCE(o.enabled,0) ownership_enabled
             FROM cron_v3_work w
             LEFT JOIN cron_v3_queue_ownership o ON o.queue_key=w.work_type
             WHERE w.lane=? AND w.status IN ("ready","deferred","waiting_rate","waiting_budget","waiting_api")
               AND w.available_at<=UTC_TIMESTAMP(3) AND w.work_type IN (' . $marks . ')
             ORDER BY ' . $orderBy . '
             LIMIT ' . $limit
        );
        $statement->execute(array_merge([$lane], $acceptedTypes));
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $summary */
    public function recordSnapshot(string $lane, array $summary): void
    {
        $this->assertLane($lane);
        $json = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $statement = $this->pdo->prepare(
            'INSERT INTO cron_v3_snapshots
             (snapshot_key,snapshot_type,lane,payload_json,generation,observed_at)
             VALUES (?,"run",?,?,1,UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),generation=generation+1,
                                     observed_at=UTC_TIMESTAMP(3)'
        );
        $statement->execute(['run:' . $lane, $lane, $json]);
    }

    private function safeResultJson(WorkResult $result): string
    {
        $safe = [];
        foreach ($result->metadata as $key => $value) {
            if (preg_match('/token|secret|password|authorization|credential/i', (string) $key) === 1) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[(string) $key] = $value;
            }
        }
        return json_encode(['code' => $result->code, 'metadata' => $safe], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function storageStatus(WorkResult $result): string
    {
        if ($result->status !== 'deferred') {
            return $result->status;
        }

        return match ($result->code) {
            'rate_limited', 'http_429', 'remote_backoff' => 'waiting_rate',
            'api_budget_deferred' => 'waiting_budget',
            'oauth_refresh_required', 'http_5xx' => 'waiting_api',
            default => 'deferred',
        };
    }

    private function fifoOrderSql(): string
    {
        return $this->hasArrivalSeqColumn()
            ? 'w.arrival_seq ASC,w.id ASC'
            : 'w.id ASC';
    }

    private function hasArrivalSeqColumn(): bool
    {
        if ($this->hasArrivalSeq !== null) {
            return $this->hasArrivalSeq;
        }
        try {
            $statement = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE()
                   AND table_name='cron_v3_work'
                   AND column_name='arrival_seq'"
            );
            $this->hasArrivalSeq = (int) $statement->fetchColumn() === 1;
        } catch (Throwable) {
            $this->hasArrivalSeq = false;
        }

        return $this->hasArrivalSeq;
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function assertLane(string $lane): void
    {
        if (!in_array($lane, ['local', 'remote'], true)) {
            throw new RuntimeException('Invalid Cron V3 lane.');
        }
    }

    private function assertClaim(WorkEnvelope $work): void
    {
        if ($work->id === null || $work->ownerToken === null || $work->leaseGeneration < 1) {
            throw new RuntimeException('Cron V3 operation requires a claimed envelope.');
        }
    }

    private function recoverExpired(string $lane): void
    {
        $this->pdo->prepare(
            'UPDATE cron_v3_work
             SET status="deferred",available_at=UTC_TIMESTAMP(3),owner_token=NULL,lease_until=NULL,
                 last_error_code="lease_expired",updated_at=UTC_TIMESTAMP(3)
             WHERE lane=? AND status="leased" AND lease_until<UTC_TIMESTAMP(3)'
        )->execute([$lane]);
    }

    private function isRetryableTransactionError(PDOException $error): bool
    {
        $driverCode = (string) ($error->errorInfo[1] ?? '');
        if (in_array($driverCode, ['1205', '1213'], true)) {
            return true;
        }
        $message = strtolower($error->getMessage());
        return str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout');
    }
}
