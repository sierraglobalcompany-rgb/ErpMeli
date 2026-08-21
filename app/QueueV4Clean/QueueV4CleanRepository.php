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

    public function hasOutstandingFreshFrontierWork(int $companyId, int $accountId): bool
    {
        $this->assertTenant($companyId, $accountId);
        $statement = $this->pdo->prepare(
            "SELECT EXISTS(
                SELECT 1 FROM queue_v4_clean_jobs
                WHERE company_id=? AND meli_account_id=?
                  AND job_type IN ('fresh_orders_discovery','order_exact')
                  AND state IN ('ready','running','waiting')
             )"
        );
        $statement->execute([$companyId, $accountId]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function nextOAuthOpportunity(int $companyId, int $accountId): string
    {
        $this->assertTenant($companyId, $accountId);
        $statement = $this->pdo->prepare(
            "SELECT next_attempt_at FROM oauth_refresh_operations
             WHERE company_id=? AND meli_account_id=?
               AND state IN ('SCHEDULED','RUNNING','WAITING')
             ORDER BY next_attempt_at,id LIMIT 1"
        );
        $statement->execute([$companyId, $accountId]);
        $value = $statement->fetchColumn();
        $timestamp = $value === false ? false : strtotime((string) $value . ' UTC');
        return gmdate('Y-m-d H:i:s', $timestamp === false ? time() + 60 : max(time() + 5, $timestamp));
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
            $previousGeneration = (int) ($row['lease_generation'] ?? 0);
            $generation = $previousGeneration + 1;
            $this->assertTenant($companyId, $accountId);
            $update = $this->pdo->prepare(
                "UPDATE queue_v4_clean_jobs
                 SET state='running',lease_owner=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND),
                     lease_generation=?,attempt_count=attempt_count+1
                 WHERE id=? AND company_id=? AND meli_account_id=? AND state='ready' AND lease_generation=?"
            );
            $update->execute([
                $owner, max(10, min(300, $leaseSeconds)), $generation,
                (int) $row['id'], $companyId, $accountId, $previousGeneration,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_claim_lost');
            }
            $attempt = $this->pdo->prepare(
                'INSERT INTO queue_v4_clean_attempts
                 (job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation)
                 VALUES (?,?,?,?,?,?)'
            );
            $attempt->execute([(int) $row['id'], $runId, $companyId, $accountId, $owner, $generation]);
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
            $row['lease_generation'] = $generation;
            $row['payload'] = json_decode((string) $row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
            return $row;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Queue V4 owns batching boundaries.  Starting from the already-claimed
     * financial pointer, walk the global ready FIFO and include only contiguous
     * simple one-order financial sources from the same tenant.  The first
     * incompatible global job stops the batch, even if a later financial row
     * would otherwise match.
     *
     * @param array<string,mixed> $runningJob
     * @return list<int>
     */
    public function contiguousFinancialReconciliationSourceIds(array $runningJob, int $maxOrderIds): array
    {
        $companyId = (int) ($runningJob['company_id'] ?? 0);
        $accountId = (int) ($runningJob['meli_account_id'] ?? 0);
        $sourceId = (int) ($runningJob['resource_id'] ?? 0);
        $this->assertTenant($companyId, $accountId);
        if ($sourceId < 1 || $maxOrderIds < 1 || (string) ($runningJob['job_type'] ?? '') !== 'domain_exact') {
            return [];
        }
        $payload = is_array($runningJob['payload'] ?? null) ? $runningJob['payload'] : [];
        if ((string) ($payload['capability'] ?? '') !== 'financial_reconciliation') {
            return [];
        }

        $sourceIds = [$sourceId];
        if ($this->financialSourceRequiresExactFallback($companyId, $accountId, $sourceId)) {
            return $sourceIds;
        }

        $remaining = max(0, min(60, $maxOrderIds) - 1);
        if ($remaining < 1) {
            return $sourceIds;
        }

        $availableAt = (string) ($runningJob['available_at'] ?? '');
        $jobId = (int) ($runningJob['id'] ?? 0);
        if ($availableAt === '' || $jobId < 1) {
            return $sourceIds;
        }

        try {
            $statement = $this->pdo->prepare(
                'SELECT q.id queue_job_id,q.company_id queue_company_id,q.meli_account_id queue_account_id,
                        q.job_type queue_job_type,q.resource_id queue_resource_id,
                        JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,"$.capability")) queue_capability,
                        s.id source_id,s.status source_status,s.sale_key,s.input_version,s.safe_message,
                        COALESCE(st.official_status,"missing") official_status,
                        (SELECT COUNT(*)
                           FROM meli_orders o
                          WHERE o.meli_account_id=s.meli_account_id
                            AND CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),COALESCE(o.external_pack_id,o.external_order_id))=s.sale_key) order_count
                 FROM queue_v4_clean_jobs q
                 LEFT JOIN sale_financial_reconciliation_jobs s
                   ON s.id=CAST(q.resource_id AS UNSIGNED)
                  AND s.company_id=q.company_id
                  AND s.meli_account_id=q.meli_account_id
                 LEFT JOIN sale_financial_state st
                   ON st.company_id=s.company_id
                  AND st.meli_account_id=s.meli_account_id
                  AND st.sale_key=s.sale_key
                  AND st.input_version=s.input_version
                 WHERE q.state="ready"
                   AND q.available_at<=UTC_TIMESTAMP(3)
                   AND (q.available_at>? OR (q.available_at=? AND q.id>?))
                 ORDER BY q.available_at ASC,q.id ASC
                 LIMIT 240'
            );
            $statement->execute([$availableAt, $availableAt, $jobId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return $sourceIds;
        }

        foreach ($rows as $row) {
            if (!$this->isContiguousFinancialBatchRow($row, $companyId, $accountId)) {
                break;
            }
            $rowSourceId = (int) ($row['source_id'] ?? 0);
            if ($rowSourceId < 1) {
                break;
            }
            $sourceIds[] = $rowSourceId;
            $remaining--;
            if ($remaining < 1) {
                break;
            }
        }

        return $sourceIds;
    }

    private function financialSourceRequiresExactFallback(int $companyId, int $accountId, int $sourceId): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT safe_message
                   FROM sale_financial_reconciliation_jobs
                  WHERE id=?
                    AND company_id=?
                    AND meli_account_id=?
                  LIMIT 1'
            );
            $statement->execute([$sourceId, $companyId, $accountId]);
            $safeMessage = $statement->fetchColumn();
        } catch (Throwable) {
            return true;
        }

        return str_starts_with((string) $safeMessage, 'BILLING_BATCH_EXACT_FALLBACK_REQUIRED');
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isContiguousFinancialBatchRow(array $row, int $companyId, int $accountId): bool
    {
        return (int) ($row['queue_company_id'] ?? 0) === $companyId
            && (int) ($row['queue_account_id'] ?? 0) === $accountId
            && (string) ($row['queue_job_type'] ?? '') === 'domain_exact'
            && (string) ($row['queue_capability'] ?? '') === 'financial_reconciliation'
            && (int) ($row['source_id'] ?? 0) > 0
            && in_array((string) ($row['source_status'] ?? ''), ['pending', 'retry', 'awaiting_remote'], true)
            && str_starts_with((string) ($row['sale_key'] ?? ''), 'O:')
            && (int) ($row['order_count'] ?? 0) === 1
            && (string) ($row['official_status'] ?? 'missing') !== 'complete'
            && preg_match('/^[a-f0-9]{64}$/', (string) ($row['input_version'] ?? '')) === 1
            && !str_starts_with((string) ($row['safe_message'] ?? ''), 'BILLING_BATCH_EXACT_FALLBACK_REQUIRED');
    }

    /**
     * Queue V4, not the financial domain service, owns pointer state.  This
     * aligns unclaimed contiguous pointers after a coalesced domain call.  The
     * already-running pointer is intentionally ignored here and closed through
     * the normal worker CAS path.
     *
     * @param array<int,array{state:string,classification?:string,next_safe_at?:?string}> $outcomesBySourceId
     */
    public function alignReadyFinancialReconciliationPointers(
        int $companyId,
        int $accountId,
        array $outcomesBySourceId,
        int $exceptSourceId,
    ): void {
        $this->assertTenant($companyId, $accountId);
        $complete = $this->pdo->prepare(
            'UPDATE queue_v4_clean_jobs
             SET state="completed",completed_at=UTC_TIMESTAMP(3),last_error_class=NULL
             WHERE company_id=? AND meli_account_id=? AND resource_id=? AND state="ready"
               AND job_type="domain_exact"
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))="financial_reconciliation"'
        );
        $waiting = $this->pdo->prepare(
            'UPDATE queue_v4_clean_jobs
             SET state="waiting",available_at=?,completed_at=NULL,last_error_class=?
             WHERE company_id=? AND meli_account_id=? AND resource_id=? AND state="ready"
               AND job_type="domain_exact"
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))="financial_reconciliation"'
        );
        $review = $this->pdo->prepare(
            'UPDATE queue_v4_clean_jobs
             SET state="review",completed_at=NULL,last_error_class=?
             WHERE company_id=? AND meli_account_id=? AND resource_id=? AND state="ready"
               AND job_type="domain_exact"
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))="financial_reconciliation"'
        );
        foreach ($outcomesBySourceId as $sourceId => $outcome) {
            $sourceId = (int) $sourceId;
            if ($sourceId < 1 || $sourceId === $exceptSourceId) {
                continue;
            }
            $state = (string) ($outcome['state'] ?? '');
            if ($state === 'completed') {
                $complete->execute([$companyId, $accountId, (string) $sourceId]);
                continue;
            }
            $classification = substr((string) ($outcome['classification'] ?? 'domain_source_waiting:financial_reconciliation'), 0, 100);
            if ($state === 'waiting') {
                $nextSafeAt = $this->safeUtcDateTime((string) ($outcome['next_safe_at'] ?? ''));
                $waiting->execute([$nextSafeAt, $classification, $companyId, $accountId, (string) $sourceId]);
                continue;
            }
            $review->execute([
                substr($classification !== '' ? $classification : 'domain_source_review', 0, 100),
                $companyId,
                $accountId,
                (string) $sourceId,
            ]);
        }
    }

    /**
     * @param list<int> $sourceIds
     */
    public function alignReadyFinancialReconciliationPointersFromSources(
        int $companyId,
        int $accountId,
        array $sourceIds,
        int $exceptSourceId,
    ): void {
        $this->assertTenant($companyId, $accountId);
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn (int $id): bool => $id > 0)));
        if ($sourceIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($sourceIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT id,status,next_run_at FROM sale_financial_reconciliation_jobs
             WHERE company_id=? AND meli_account_id=? AND id IN (' . $in . ')'
        );
        $statement->execute(array_merge([$companyId, $accountId], $sourceIds));
        $outcomes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            $sourceId = (int) ($row['id'] ?? 0);
            if ($sourceId < 1) {
                continue;
            }
            if ($status === 'complete') {
                $outcomes[$sourceId] = ['state' => 'completed'];
                continue;
            }
            if (in_array($status, ['pending', 'running', 'retry', 'awaiting_remote'], true)) {
                $next = trim((string) ($row['next_run_at'] ?? ''));
                $outcomes[$sourceId] = [
                    'state' => 'waiting',
                    'classification' => 'domain_source_waiting:financial_reconciliation',
                    'next_safe_at' => $next !== '' ? $next : gmdate('Y-m-d H:i:s', time() + 60),
                ];
                continue;
            }
            $outcomes[$sourceId] = [
                'state' => 'review',
                'classification' => 'domain_source_' . substr($status !== '' ? $status : 'unknown', 0, 70),
            ];
        }
        $this->alignReadyFinancialReconciliationPointers($companyId, $accountId, $outcomes, $exceptSourceId);
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
                   AND state='running' AND lease_owner=? AND lease_generation=? AND attempt_count=?"
            );
            $statement->execute([
                $availableAt,
                $classification,
                (int) $job['id'],
                $companyId,
                $accountId,
                (string) $job['lease_owner'],
                (int) $job['lease_generation'],
                (int) $job['attempt_count'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_non_failure_defer_cas_lost');
            }
            $attempt = $this->pdo->prepare(
                "UPDATE queue_v4_clean_attempts
                 SET outcome='waiting',error_class=?,finished_at=UTC_TIMESTAMP(3),source_closed_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=?
                   AND lease_owner=? AND lease_generation=? AND outcome='running'"
            );
            $attempt->execute([
                $classification,
                (int) $job['attempt_id'],
                (int) $job['id'],
                $companyId,
                $accountId,
                (string) $job['lease_owner'],
                (int) $job['lease_generation'],
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

    /**
     * Move every non-running financial Billing pointer behind the global
     * Billing gate without claiming it. This is intentionally global because
     * Billing's physical gate is endpoint-wide, not tenant-wide.
     */
    public function parkFinancialReconciliationUntil(string $nextSafeAt, ?int $exceptJobId = null): int
    {
        $availableAt = $this->safeUtcDateTime($nextSafeAt);
        $whereExcept = $exceptJobId !== null && $exceptJobId > 0 ? ' AND q.id<>?' : '';
        $statement = $this->pdo->prepare(
            "UPDATE queue_v4_clean_jobs q
             SET state='waiting',
                  available_at=IF(available_at IS NULL OR available_at < ?, ?, available_at)
              WHERE q.job_type='domain_exact'
                AND q.state IN ('ready','waiting')
                AND q.resource_id REGEXP '^[0-9]+$'
                AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='financial_reconciliation'
                AND EXISTS (
                    SELECT 1
                      FROM sale_financial_reconciliation_jobs s
                     WHERE s.id=CAST(q.resource_id AS UNSIGNED)
                       AND s.company_id=q.company_id
                       AND s.meli_account_id=q.meli_account_id
                )"
            . $whereExcept
        );
        $parameters = [$availableAt, $availableAt];
        if ($whereExcept !== '') {
            $parameters[] = (int) $exceptJobId;
        }
        $statement->execute($parameters);
        return $statement->rowCount();
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
                "SELECT j.id,j.company_id,j.meli_account_id,j.lease_owner,j.lease_generation,
                        a.id attempt_id,a.dispatch_state
                 FROM queue_v4_clean_jobs j
                 INNER JOIN queue_v4_clean_attempts a
                   ON a.job_id=j.id AND a.company_id=j.company_id AND a.meli_account_id=j.meli_account_id
                  AND a.lease_owner=j.lease_owner AND a.lease_generation=j.lease_generation AND a.outcome='running'
                 WHERE j.state='running' AND j.lease_expires_at<UTC_TIMESTAMP(3)
                 ORDER BY j.id FOR UPDATE"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($expired as $row) {
                $classification = (string) $row['dispatch_state'] === 'NOT_DISPATCHED'
                    ? 'pre_transport_lease_expired'
                    : 'remote_result_uncertain_safe_get';
                $attempt = $this->pdo->prepare(
                    "UPDATE queue_v4_clean_attempts
                     SET outcome='waiting',error_class=?,finished_at=UTC_TIMESTAMP(3),source_closed_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=?
                       AND lease_owner=? AND lease_generation=? AND outcome='running'"
                );
                $attempt->execute([
                    $classification, (int) $row['attempt_id'], (int) $row['id'],
                    (int) $row['company_id'], (int) $row['meli_account_id'], (string) $row['lease_owner'],
                    (int) $row['lease_generation'],
                ]);
                $job = $this->pdo->prepare(
                    "UPDATE queue_v4_clean_jobs
                     SET state='waiting',available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND),
                         attempt_count=GREATEST(attempt_count-1,0),lease_owner=NULL,lease_expires_at=NULL,
                         last_error_class=?
                     WHERE id=? AND company_id=? AND meli_account_id=? AND state='running'
                       AND lease_owner=? AND lease_generation=?"
                );
                $job->execute([
                    $classification, (int) $row['id'], (int) $row['company_id'], (int) $row['meli_account_id'],
                    (string) $row['lease_owner'], (int) $row['lease_generation'],
                ]);
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
                 WHERE id=? AND company_id=? AND meli_account_id=? AND state='running'
                   AND lease_owner=? AND lease_generation=?"
            );
            $statement->execute([
                $jobState, $errorClass, $jobState, (int) $job['id'], $companyId, $accountId,
                (string) $job['lease_owner'], (int) $job['lease_generation'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_finish_cas_lost');
            }
            $attempt = $this->pdo->prepare(
                'UPDATE queue_v4_clean_attempts SET outcome=?,error_class=?,finished_at=UTC_TIMESTAMP(3),source_closed_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=?
                   AND lease_owner=? AND lease_generation=? AND outcome=\'running\''
            );
            $attempt->execute([
                $attemptOutcome, $errorClass, (int) $job['attempt_id'], (int) $job['id'],
                $companyId, $accountId, (string) $job['lease_owner'], (int) $job['lease_generation'],
            ]);
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
