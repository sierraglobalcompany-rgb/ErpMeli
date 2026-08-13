<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function control(bool $forUpdate = false): array
    {
        $sql = "SELECT * FROM queue_v4_clean_control WHERE control_key='primary'";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('queue_v4_clean_control_missing');
        }
        return $row;
    }

    /** @return array{ready:int,running:int,waiting:int,review:int,dead:int,completed:int,total:int} */
    public function counts(): array
    {
        $counts = ['ready' => 0, 'running' => 0, 'waiting' => 0, 'review' => 0, 'dead' => 0, 'completed' => 0];
        $rows = $this->pdo->query(
            'SELECT state,COUNT(*) row_count FROM queue_v4_clean_jobs GROUP BY state'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $state = (string) ($row['state'] ?? '');
            if (array_key_exists($state, $counts)) {
                $counts[$state] = (int) $row['row_count'];
            }
        }
        return $counts + ['total' => array_sum($counts)];
    }

    /** @return array<string,mixed> */
    public function operationalObservability(): array
    {
        $heartbeat = $this->pdo->query(
            "SELECT COALESCE(finished_at,started_at)
             FROM queue_v4_clean_runs
             WHERE launcher='scheduler'
             ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $heartbeat = $heartbeat === false ? null : (string) $heartbeat;
        $timestamp = $heartbeat === null ? false : strtotime($heartbeat . ' UTC');
        $physical = $timestamp === false
            ? 'UNKNOWN'
            : (time() - $timestamp <= 180 ? 'RECENT' : 'STALE');
        return [
            'last_scheduler_heartbeat' => $heartbeat,
            'physical_cron_observed' => $physical,
        ];
    }

    public function hasOutstandingOperationalWork(int $companyId, int $accountId): bool
    {
        $this->assertTenant($companyId, $accountId);
        $statement = $this->pdo->prepare(
            "SELECT EXISTS(
                SELECT 1 FROM queue_v4_clean_jobs
                WHERE company_id=? AND meli_account_id=?
                  AND state IN ('ready','running','waiting')
             )"
        );
        $statement->execute([$companyId, $accountId]);
        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function enqueue(
        int $companyId,
        int $accountId,
        string $jobType,
        ?string $resourceId,
        string $idempotencyKey,
        array $payload,
        int $maxAttempts = 3,
    ): int {
        $this->assertTenant($companyId, $accountId);
        if (!in_array($jobType, ['fresh_orders_discovery', 'order_exact'], true)) {
            throw new RuntimeException('queue_v4_clean_job_type_invalid');
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,max_attempts)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               id=LAST_INSERT_ID(id),
               completed_at=IF(state IN ("completed","review"),NULL,completed_at),
               available_at=IF(state IN ("completed","review"),UTC_TIMESTAMP(3),available_at),
               attempt_count=IF(state IN ("completed","review"),0,attempt_count),
               last_error_class=IF(state IN ("completed","review"),NULL,last_error_class),
               state=IF(state IN ("completed","review"),"ready",state)'
        );
        $statement->execute([
            $companyId,
            $accountId,
            $jobType,
            $resourceId,
            $idempotencyKey,
            $json,
            max(1, min(10, $maxAttempts)),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function claim(int $runId, string $owner, int $leaseSeconds = 60): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->pdo->query(
                "SELECT * FROM queue_v4_clean_jobs
                 WHERE state='ready' AND available_at<=UTC_TIMESTAMP(3)
                 ORDER BY available_at ASC,id ASC LIMIT 1 FOR UPDATE SKIP LOCKED"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }
            $companyId = (int) $row['company_id'];
            $accountId = (int) $row['meli_account_id'];
            $this->assertTenant($companyId, $accountId);
            $update = $this->pdo->prepare(
                "UPDATE queue_v4_clean_jobs
                 SET state='running',lease_owner=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND),
                     attempt_count=attempt_count+1
                 WHERE id=? AND company_id=? AND meli_account_id=? AND state='ready'"
            );
            $update->execute([$owner, max(10, min(300, $leaseSeconds)), (int) $row['id'], $companyId, $accountId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_claim_lost');
            }
            $attempt = $this->pdo->prepare(
                'INSERT INTO queue_v4_clean_attempts
                 (job_id,run_id,company_id,meli_account_id,lease_owner)
                 VALUES (?,?,?,?,?)'
            );
            $attempt->execute([(int) $row['id'], $runId, $companyId, $accountId, $owner]);
            $attemptId = (int) $this->pdo->lastInsertId();
            $run = $this->pdo->prepare(
                "UPDATE queue_v4_clean_runs SET jobs_claimed=jobs_claimed+1
                 WHERE id=? AND status='running'"
            );
            $run->execute([$runId]);
            if ($run->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_run_not_running');
            }
            $this->pdo->commit();
            $row['attempt_id'] = $attemptId;
            $row['attempt_count'] = (int) $row['attempt_count'] + 1;
            $row['lease_owner'] = $owner;
            $row['payload'] = json_decode((string) $row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
            return $row;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function complete(array $job, int $runId): void
    {
        $this->finish($job, $runId, 'completed', 'completed', null, null);
    }

    public function wait(array $job, int $runId, string $errorClass, int $delaySeconds = 30): void
    {
        $this->finish($job, $runId, 'waiting', 'waiting', $errorClass, max(1, min(3600, $delaySeconds)));
    }

    /**
     * Aplaza una condición de capacidad/pacing sin convertirla en intento
     * funcional. El CAS revierte exactamente el incremento hecho por claim().
     *
     * @param array<string,mixed> $job
     */
    public function deferWithoutAttemptPenalty(
        array $job,
        int $runId,
        string $classification,
        ?string $nextSafeAt,
    ): void {
        $companyId = (int) ($job['company_id'] ?? 0);
        $accountId = (int) ($job['meli_account_id'] ?? 0);
        $this->assertTenant($companyId, $accountId);
        $availableAt = $this->safeUtcDateTime($nextSafeAt);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "UPDATE queue_v4_clean_jobs
                 SET state='waiting',available_at=?,lease_owner=NULL,lease_expires_at=NULL,
                     attempt_count=GREATEST(attempt_count-1,0),last_error_class=?,completed_at=NULL
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND state='running' AND lease_owner=? AND attempt_count=?"
            );
            $statement->execute([
                $availableAt,
                $classification,
                (int) $job['id'],
                $companyId,
                $accountId,
                (string) $job['lease_owner'],
                (int) $job['attempt_count'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_non_failure_defer_cas_lost');
            }
            $attempt = $this->pdo->prepare(
                "UPDATE queue_v4_clean_attempts
                 SET outcome='waiting',error_class=?,finished_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=?
                   AND lease_owner=? AND outcome='running'"
            );
            $attempt->execute([
                $classification,
                (int) $job['attempt_id'],
                (int) $job['id'],
                $companyId,
                $accountId,
                (string) $job['lease_owner'],
            ]);
            if ($attempt->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_non_failure_attempt_cas_lost');
            }
            $run = $this->pdo->prepare(
                "UPDATE queue_v4_clean_runs SET jobs_deferred=jobs_deferred+1
                 WHERE id=? AND status='running'"
            );
            $run->execute([$runId]);
            if ($run->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_non_failure_run_cas_lost');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function review(array $job, int $runId, string $errorClass): void
    {
        $this->finish($job, $runId, 'review', 'review', $errorClass, null);
    }

    public function dead(array $job, int $runId, string $errorClass): void
    {
        $this->finish($job, $runId, 'dead', 'dead', $errorClass, null);
    }

    public function releaseDueWaiting(): int
    {
        return $this->pdo->exec(
            "UPDATE queue_v4_clean_jobs SET state='ready',last_error_class=NULL
             WHERE state='waiting' AND available_at<=UTC_TIMESTAMP(3)"
        );
    }

    public function expireLeases(): int
    {
        $this->pdo->beginTransaction();
        try {
            $expired = $this->pdo->query(
                "SELECT id,company_id,meli_account_id,lease_owner
                 FROM queue_v4_clean_jobs
                 WHERE state='running' AND lease_expires_at<UTC_TIMESTAMP(3)
                 ORDER BY id FOR UPDATE"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($expired as $row) {
                $attempt = $this->pdo->prepare(
                    "UPDATE queue_v4_clean_attempts SET outcome='lease_expired',finished_at=UTC_TIMESTAMP(3)
                     WHERE job_id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND outcome='running'"
                );
                $attempt->execute([(int) $row['id'], (int) $row['company_id'], (int) $row['meli_account_id'], (string) $row['lease_owner']]);
                $job = $this->pdo->prepare(
                    "UPDATE queue_v4_clean_jobs
                     SET state=IF(attempt_count<max_attempts,'ready','review'),lease_owner=NULL,lease_expires_at=NULL,
                         last_error_class='lease_expired'
                     WHERE id=? AND company_id=? AND meli_account_id=? AND state='running'"
                );
                $job->execute([(int) $row['id'], (int) $row['company_id'], (int) $row['meli_account_id']]);
            }
            $this->pdo->commit();
            return count($expired);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function beginRun(string $launcher, string $owner): int
    {
        if (!in_array($launcher, ['scheduler', 'manual', 'test'], true)) {
            throw new RuntimeException('queue_v4_clean_launcher_invalid');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_v4_clean_runs(launcher,worker_ref) VALUES (?,?)'
        );
        $statement->execute([$launcher, hash('sha256', $owner)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function finishRun(int $runId, string $status): void
    {
        if (!in_array($status, ['completed', 'failed', 'stopped'], true)) {
            throw new RuntimeException('queue_v4_clean_run_status_invalid');
        }
        $statement = $this->pdo->prepare(
            "UPDATE queue_v4_clean_runs SET status=?,finished_at=UTC_TIMESTAMP(3)
             WHERE id=? AND status='running'"
        );
        $statement->execute([$status, $runId]);
    }

    private function finish(
        array $job,
        int $runId,
        string $jobState,
        string $attemptOutcome,
        ?string $errorClass,
        ?int $delaySeconds,
    ): void {
        $companyId = (int) ($job['company_id'] ?? 0);
        $accountId = (int) ($job['meli_account_id'] ?? 0);
        $this->assertTenant($companyId, $accountId);
        $this->pdo->beginTransaction();
        try {
            $available = $delaySeconds === null
                ? 'available_at'
                : 'DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ' . (int) $delaySeconds . ' SECOND)';
            $statement = $this->pdo->prepare(
                "UPDATE queue_v4_clean_jobs
                 SET state=?,available_at={$available},lease_owner=NULL,lease_expires_at=NULL,last_error_class=?,
                     completed_at=IF(?='completed',UTC_TIMESTAMP(3),NULL)
                 WHERE id=? AND company_id=? AND meli_account_id=? AND state='running' AND lease_owner=?"
            );
            $statement->execute([$jobState, $errorClass, $jobState, (int) $job['id'], $companyId, $accountId, (string) $job['lease_owner']]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_finish_cas_lost');
            }
            $attempt = $this->pdo->prepare(
                'UPDATE queue_v4_clean_attempts SET outcome=?,error_class=?,finished_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND outcome=\'running\''
            );
            $attempt->execute([$attemptOutcome, $errorClass, (int) $job['attempt_id'], (int) $job['id'], $companyId, $accountId, (string) $job['lease_owner']]);
            if ($attempt->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_attempt_cas_lost');
            }
            $runColumn = $jobState === 'completed' ? 'jobs_completed' : 'jobs_deferred';
            $run = $this->pdo->prepare(
                "UPDATE queue_v4_clean_runs SET {$runColumn}={$runColumn}+1 WHERE id=? AND status='running'"
            );
            $run->execute([$runId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function assertTenant(int $companyId, int $accountId): void
    {
        if ($companyId < 1 || $accountId < 1) {
            throw new RuntimeException('queue_v4_clean_tenant_invalid');
        }
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM meli_accounts WHERE company_id=? AND id=? LIMIT 1'
        );
        $statement->execute([$companyId, $accountId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('queue_v4_clean_tenant_mismatch');
        }
    }

    private function safeUtcDateTime(?string $value): string
    {
        $timestamp = $value === null || trim($value) === ''
            ? false
            : strtotime(trim($value) . ' UTC');
        $minimum = time() + 1;
        return gmdate('Y-m-d H:i:s', max($minimum, $timestamp === false ? $minimum : $timestamp));
    }
}
