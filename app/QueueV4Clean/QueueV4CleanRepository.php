<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanRepository
{
    private const HARD_PREVIEW_LIMIT = 60;

    private ?bool $financialSourceTableExists = null;
    /** @var array{ran:bool,candidates:int,released:int,skipped_future:int,skipped_blocked:int,errors:int} */
    private array $lastFinanceWakeupRuntime = [
        'ran' => false,
        'candidates' => 0,
        'released' => 0,
        'skipped_future' => 0,
        'skipped_blocked' => 0,
        'errors' => 0,
    ];

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
               completed_at=IF(state IN ("completed","review") AND COALESCE(last_error_class,"") NOT IN ("remote_result_uncertain","remoteresultuncertainexception","remote_result_uncertain_safe_get"),NULL,completed_at),
               available_at=IF(state IN ("completed","review") AND COALESCE(last_error_class,"") NOT IN ("remote_result_uncertain","remoteresultuncertainexception","remote_result_uncertain_safe_get"),UTC_TIMESTAMP(3),available_at),
               attempt_count=IF(state IN ("completed","review") AND COALESCE(last_error_class,"") NOT IN ("remote_result_uncertain","remoteresultuncertainexception","remote_result_uncertain_safe_get"),0,attempt_count),
               last_error_class=IF(state IN ("completed","review") AND COALESCE(last_error_class,"") NOT IN ("remote_result_uncertain","remoteresultuncertainexception","remote_result_uncertain_safe_get"),NULL,last_error_class),
               state=IF(state IN ("completed","review") AND COALESCE(last_error_class,"") NOT IN ("remote_result_uncertain","remoteresultuncertainexception","remote_result_uncertain_safe_get"),"ready",state)'
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

    /**
     * @param list<int>|null $authorizedAccountIds
     * @return array<string,mixed>|null
     */
    public function claim(
        int $runId,
        string $owner,
        int $leaseSeconds = 60,
        ?array $authorizedAccountIds = null,
        ?int $accountId = null,
        ?array $confirmedSelection = null
    ): ?array
    {
        [$scopeSql, $scopeParams] = $this->accountScopeSql('q', $authorizedAccountIds, $accountId);
        [$selectionSql, $selectionParams] = $this->confirmedSelectionSql('q', $confirmedSelection);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "SELECT q.* FROM queue_v4_clean_jobs q
                 WHERE q.state='ready'
                   AND q.available_at<=UTC_TIMESTAMP(3)
                   AND NOT (" . $this->financialSourceFuturePredicate('q') . ")
                   AND NOT (" . $this->unresolvedPhysicalPredicate('q') . ")
                   AND {$scopeSql}
                   AND {$selectionSql}
                 ORDER BY available_at ASC,id ASC LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            $statement->execute(array_merge($scopeParams, $selectionParams));
            $row = $statement->fetch(PDO::FETCH_ASSOC);
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
     * Human-facing preview of the exact same canonical Queue V4 rows claim()
     * can take now. It never reserves work: process-time claim remains the
     * authority and preserves FIFO among actually eligible rows.
     *
     * @param list<int>|null $authorizedAccountIds
     * @return list<array<string,mixed>>
     */
    public function previewEligible(
        int $limit,
        ?array $authorizedAccountIds = null,
        ?int $accountId = null
    ): array {
        $limit = max(1, min(self::HARD_PREVIEW_LIMIT, $limit));
        [$scopeSql, $scopeParams] = $this->accountScopeSql('q', $authorizedAccountIds, $accountId);
        $statement = $this->pdo->prepare(
            "SELECT q.id AS queue_job_id,
                    q.company_id,
                    q.meli_account_id,
                    q.job_type,
                    q.resource_id,
                    q.idempotency_key,
                    SHA2(CAST(q.payload_json AS CHAR),256) AS queue_payload_hash,
                    q.state,
                    q.available_at,
                    JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability')) AS capability,
                    a.account_name,
                    c.name AS company_name
               FROM queue_v4_clean_jobs q
               INNER JOIN meli_accounts a
                 ON a.id=q.meli_account_id AND a.company_id=q.company_id
               INNER JOIN companies c
                 ON c.id=q.company_id
              WHERE q.state='ready'
                AND q.available_at<=UTC_TIMESTAMP(3)
                AND NOT (" . $this->financialSourceFuturePredicate('q') . ")
                AND NOT (" . $this->unresolvedPhysicalPredicate('q') . ")
                AND {$scopeSql}
              ORDER BY q.available_at ASC,q.id ASC
              LIMIT {$limit}"
        );
        $statement->execute($scopeParams);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $position => $row) {
            $rows[] = $this->humanQueuePreviewRow($row, $position + 1);
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $selection
     * @param list<int>|null $authorizedAccountIds
     * @return list<array<string,mixed>>
     */
    public function claimableConfirmedSelection(
        array $selection,
        ?array $authorizedAccountIds = null,
        ?int $accountId = null
    ): array {
        [$scopeSql, $scopeParams] = $this->accountScopeSql('q', $authorizedAccountIds, $accountId);
        [$selectionSql, $selectionParams] = $this->confirmedSelectionSql('q', $selection);
        if ($selectionSql === '1=0') {
            return [];
        }
        $statement = $this->pdo->prepare(
            "SELECT q.id FROM queue_v4_clean_jobs q
              WHERE q.state='ready' AND q.available_at<=UTC_TIMESTAMP(3)
                AND NOT (" . $this->financialSourceFuturePredicate('q') . ")
                AND NOT (" . $this->unresolvedPhysicalPredicate('q') . ")
                AND {$scopeSql} AND {$selectionSql}"
        );
        $statement->execute(array_merge($scopeParams, $selectionParams));
        $eligible = array_fill_keys(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
        return array_values(array_filter(
            $selection,
            static fn (array $row): bool => isset($eligible[(int) ($row['queue_job_id'] ?? 0)])
        ));
    }

    /**
     * @param list<int>|null $authorizedAccountIds
     */
    public function eligibleCount(?array $authorizedAccountIds = null, ?int $accountId = null): int
    {
        [$scopeSql, $scopeParams] = $this->accountScopeSql('q', $authorizedAccountIds, $accountId);
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
               FROM queue_v4_clean_jobs q
              WHERE q.state='ready'
                AND q.available_at<=UTC_TIMESTAMP(3)
                AND NOT (" . $this->financialSourceFuturePredicate('q') . ")
                AND NOT (" . $this->unresolvedPhysicalPredicate('q') . ")
                AND {$scopeSql}"
        );
        $statement->execute($scopeParams);

        return (int) $statement->fetchColumn();
    }

    /**
     * Queue V4 owns batching boundaries. The primary pointer has already won
     * the global FIFO claim. Its optional extras are later, due, ready,
     * one-order finance pointers from that same tenant only. A different
     * tenant or a non-finance pointer is a global FIFO boundary and stops the
     * scan. Ineligible finance pointers from the same tenant are skipped but
     * remain untouched: they cannot change the primary turn, nor be claimed,
     * moved, or aligned as part of this Billing request.
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
        // The global FIFO winner remains eligible to run normally even when it
        // cannot join a bulk request. In that case it must be the only source:
        // never claim later sources before the primary's real order cardinality
        // and current input are known.
        if (!$this->financialSourceIsCurrentSimpleBatchCandidate($companyId, $accountId, $sourceId)) {
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
            $cursorAvailableAt = $availableAt;
            $cursorJobId = $jobId;
            while ($remaining > 0) {
            $statement = $this->pdo->prepare(
                'SELECT q.id queue_job_id,q.available_at queue_available_at,
                        q.company_id queue_company_id,q.meli_account_id queue_account_id,
                        q.job_type queue_job_type,
                        JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,"$.capability")) queue_capability,
                        s.id source_id,s.status source_status,s.sale_key,s.input_version,s.safe_message,
                        st.input_version state_input_version,COALESCE(st.official_status,"missing") official_status,
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
                  WHERE q.state="ready"
                    AND NOT (' . $this->unresolvedPhysicalPredicate('q') . ')
                    AND q.available_at<=UTC_TIMESTAMP(3)
                    AND (q.available_at>? OR (q.available_at=? AND q.id>?))
                  ORDER BY q.available_at ASC,q.id ASC
                  LIMIT 240'
            );
            $statement->execute([$cursorAvailableAt, $cursorAvailableAt, $cursorJobId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cursorAvailableAt = (string) ($row['queue_available_at'] ?? $cursorAvailableAt);
                $cursorJobId = (int) ($row['queue_job_id'] ?? $cursorJobId);
                if ((int) ($row['queue_company_id'] ?? 0) !== $companyId
                    || (int) ($row['queue_account_id'] ?? 0) !== $accountId
                    || (string) ($row['queue_job_type'] ?? '') !== 'domain_exact'
                    || (string) ($row['queue_capability'] ?? '') !== 'financial_reconciliation') {
                    break;
                }
                if (!in_array((string) ($row['source_status'] ?? ''), ['pending', 'retry', 'awaiting_remote'], true)
                    || !str_starts_with((string) ($row['sale_key'] ?? ''), 'O:')
                    || (int) ($row['order_count'] ?? 0) !== 1
                    || (string) ($row['official_status'] ?? 'missing') === 'complete'
                    || preg_match('/^[a-f0-9]{64}$/', (string) ($row['input_version'] ?? '')) !== 1
                    || !hash_equals((string) ($row['input_version'] ?? ''), (string) ($row['state_input_version'] ?? ''))
                    || str_starts_with((string) ($row['safe_message'] ?? ''), 'BILLING_BATCH_EXACT_FALLBACK_REQUIRED')) {
                    continue;
                }
                $rowSourceId = (int) ($row['source_id'] ?? 0);
                if ($rowSourceId > 0) {
                    $sourceIds[] = $rowSourceId;
                    $remaining--;
                    if ($remaining < 1) {
                        break;
                    }
                }
            }
            if (count($rows) < 240) {
                break;
            }
            }
        } catch (Throwable) {
            return $sourceIds;
        }

        return $sourceIds;
    }

    private function financialSourceIsCurrentSimpleBatchCandidate(int $companyId, int $accountId, int $sourceId): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT s.status,s.sale_key,s.input_version,s.safe_message,
                        st.input_version state_input_version,COALESCE(st.official_status,"missing") official_status,
                        (SELECT COUNT(*)
                           FROM meli_orders o
                          WHERE o.meli_account_id=s.meli_account_id
                            AND CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),COALESCE(o.external_pack_id,o.external_order_id))=s.sale_key) order_count
                   FROM sale_financial_reconciliation_jobs s
                   LEFT JOIN sale_financial_state st
                     ON st.company_id=s.company_id
                    AND st.meli_account_id=s.meli_account_id
                    AND st.sale_key=s.sale_key
                  WHERE s.id=?
                    AND s.company_id=?
                    AND s.meli_account_id=?
                  LIMIT 1'
            );
            $statement->execute([$sourceId, $companyId, $accountId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return false;
        }

        return is_array($row)
            && in_array((string) ($row['status'] ?? ''), ['pending', 'retry', 'awaiting_remote'], true)
            && str_starts_with((string) ($row['sale_key'] ?? ''), 'O:')
            && (int) ($row['order_count'] ?? 0) === 1
            && (string) ($row['official_status'] ?? 'missing') !== 'complete'
            && preg_match('/^[a-f0-9]{64}$/', (string) ($row['input_version'] ?? '')) === 1
            && hash_equals((string) ($row['input_version'] ?? ''), (string) ($row['state_input_version'] ?? ''))
            && !str_starts_with((string) ($row['safe_message'] ?? ''), 'BILLING_BATCH_EXACT_FALLBACK_REQUIRED');
    }

    /**
     * Queue V4, not the financial domain service, owns pointer state.  This
     * aligns selected, unclaimed pointers after a coalesced domain call. The
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

    /**
     * @param list<int>|null $authorizedAccountIds
     */
    public function releaseDueWaiting(?array $authorizedAccountIds = null, ?int $accountId = null): int
    {
        $this->lastFinanceWakeupRuntime = [
            'ran' => true,
            'candidates' => 0,
            'released' => 0,
            'skipped_future' => 0,
            'skipped_blocked' => 0,
            'errors' => 0,
        ];
        if (!$this->financialSourceTableExists()) {
            return 0;
        }

        [$scopeSql, $scopeParams] = $this->accountScopeSql('q', $authorizedAccountIds, $accountId);
        $sourceIdSql = $this->financialSourceIdExpression('q');
        $candidateWhere = $this->financialWakeupCandidateWhere('q', 's', $scopeSql);
        $futureWhere = $this->financialWakeupFutureWhere('q', 's', $scopeSql);
        $totalWhere = $this->financialWakeupTotalWhere('q', 's', $scopeSql);
        try {
            $this->lastFinanceWakeupRuntime['candidates'] = $this->countFinanceWakeupRows($sourceIdSql, $candidateWhere, $scopeParams);
            $this->lastFinanceWakeupRuntime['skipped_future'] = $this->countFinanceWakeupRows($sourceIdSql, $futureWhere, $scopeParams);
            $total = $this->countFinanceWakeupRows($sourceIdSql, $totalWhere, $scopeParams);
            $this->lastFinanceWakeupRuntime['skipped_blocked'] = max(0, $total - $this->lastFinanceWakeupRuntime['candidates'] - $this->lastFinanceWakeupRuntime['skipped_future']);
        } catch (Throwable $error) {
            $this->lastFinanceWakeupRuntime['errors'] = 1;
            throw $error;
        }
        $statement = $this->pdo->prepare(
            "UPDATE queue_v4_clean_jobs q
             INNER JOIN sale_financial_reconciliation_jobs s
                     ON s.id={$sourceIdSql}
                    AND s.company_id=q.company_id
                    AND s.meli_account_id=q.meli_account_id
             SET q.state='ready',q.last_error_class=NULL
             WHERE {$candidateWhere}"
        );
        try {
            $statement->execute($scopeParams);
        } catch (Throwable $error) {
            $this->lastFinanceWakeupRuntime['errors'] = 1;
            throw $error;
        }

        $released = $statement->rowCount();
        $this->lastFinanceWakeupRuntime['released'] = $released;
        return $released;
    }

    /** @return array{ran:bool,candidates:int,released:int,skipped_future:int,skipped_blocked:int,errors:int} */
    public function lastFinanceWakeupRuntime(): array
    {
        return $this->lastFinanceWakeupRuntime;
    }

    /**
     * @param list<int>|null $authorizedAccountIds
     */
    public function expireLeases(?array $authorizedAccountIds = null, ?int $accountId = null): int
    {
        [$scopeSql, $scopeParams] = $this->accountScopeSql('j', $authorizedAccountIds, $accountId);
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                "SELECT j.id,j.company_id,j.meli_account_id,j.lease_owner,j.lease_generation,
                        a.id attempt_id,a.dispatch_state
                 FROM queue_v4_clean_jobs j
                 INNER JOIN queue_v4_clean_attempts a
                  ON a.job_id=j.id AND a.company_id=j.company_id AND a.meli_account_id=j.meli_account_id
                  AND a.lease_owner=j.lease_owner AND a.lease_generation=j.lease_generation AND a.outcome='running'
                 WHERE j.state='running' AND j.lease_expires_at<UTC_TIMESTAMP(3)
                   AND {$scopeSql}
                 ORDER BY j.id FOR UPDATE"
            );
            $statement->execute($scopeParams);
            $expired = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($expired as $row) {
                $notSent = (string) $row['dispatch_state'] === 'NOT_DISPATCHED';
                $outcome = $notSent ? 'waiting' : 'review';
                $classification = $notSent
                    ? 'pre_transport_lease_expired'
                    : 'remote_result_uncertain';
                $attempt = $this->pdo->prepare(
                    "UPDATE queue_v4_clean_attempts
                     SET outcome=?,error_class=?,finished_at=UTC_TIMESTAMP(3),source_closed_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=?
                       AND lease_owner=? AND lease_generation=? AND outcome='running'"
                );
                $attempt->execute([
                    $outcome, $classification, (int) $row['attempt_id'], (int) $row['id'],
                    (int) $row['company_id'], (int) $row['meli_account_id'], (string) $row['lease_owner'],
                    (int) $row['lease_generation'],
                ]);
                $job = $this->pdo->prepare(
                    "UPDATE queue_v4_clean_jobs
                     SET state=?,available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND),
                         attempt_count=IF(?=1 AND attempt_count>0,attempt_count-1,attempt_count),lease_owner=NULL,lease_expires_at=NULL,
                         last_error_class=?
                     WHERE id=? AND company_id=? AND meli_account_id=? AND state='running'
                       AND lease_owner=? AND lease_generation=?"
                );
                $job->execute([
                    $outcome, $notSent ? 1 : 0, $classification, (int) $row['id'], (int) $row['company_id'], (int) $row['meli_account_id'],
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

    /** @return array{completed:int,waiting:int,review:int,dead:int,running:int,total:int} */
    public function runOutcomeCounts(int $runId): array
    {
        $counts = ['completed' => 0, 'waiting' => 0, 'review' => 0, 'dead' => 0, 'running' => 0];
        if ($runId < 1) {
            return $counts + ['total' => 0];
        }
        $statement = $this->pdo->prepare(
            'SELECT outcome,COUNT(*) row_count
               FROM queue_v4_clean_attempts
              WHERE run_id=?
              GROUP BY outcome'
        );
        $statement->execute([$runId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $outcome = (string) ($row['outcome'] ?? '');
            if (array_key_exists($outcome, $counts)) {
                $counts[$outcome] = (int) $row['row_count'];
            }
        }
        return $counts + ['total' => array_sum($counts)];
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

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function humanQueuePreviewRow(array $row, int $position): array
    {
        $jobType = (string) ($row['job_type'] ?? '');
        $capability = (string) ($row['capability'] ?? '');
        $state = (string) ($row['state'] ?? 'ready');
        $type = $this->humanQueueType($jobType, $capability);
        $resource = $this->humanQueueResource($jobType, $capability);
        $status = $state === 'waiting' ? 'Disponible al iniciar' : 'Listo ahora';

        return [
            'queue_key' => 'available_queue',
            'source_id' => (string) ($row['queue_job_id'] ?? ''),
            'queue_job_id' => (int) ($row['queue_job_id'] ?? 0),
            'selection_id' => 'qv4:' . (int) ($row['queue_job_id'] ?? 0),
            'selection_version' => $this->queueSelectionVersion($row),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'meli_account_id' => (int) ($row['meli_account_id'] ?? 0),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'company_name' => (string) ($row['company_name'] ?? ''),
            'job_type' => $jobType,
            'capability' => $capability,
            'resource_id' => (string) ($row['resource_id'] ?? ''),
            'queue_idempotency_key' => (string) ($row['idempotency_key'] ?? ''),
            'queue_payload_hash' => (string) ($row['queue_payload_hash'] ?? ''),
            'available_at' => (string) ($row['available_at'] ?? ''),
            'position_no' => $position,
            'human_label' => $type,
            'label' => $type,
            'content_summary' => $resource,
            'resource_label' => $resource,
            'source_alias' => $resource,
            'source_state' => $state === 'waiting' ? 'waiting_due' : 'ready',
            'status_label' => $status,
            'state_label' => $status,
        ];
    }

    /** @param array<string,mixed> $row */
    private function queueSelectionVersion(array $row): string
    {
        return hash('sha256', json_encode([
            'queue_job_id' => (int) ($row['queue_job_id'] ?? 0),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'meli_account_id' => (int) ($row['meli_account_id'] ?? 0),
            'job_type' => (string) ($row['job_type'] ?? ''),
            'resource_id' => (string) ($row['resource_id'] ?? ''),
            'capability' => (string) ($row['capability'] ?? ''),
            'idempotency_key' => (string) ($row['idempotency_key'] ?? ''),
            'payload_hash' => (string) ($row['queue_payload_hash'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array<string,mixed>>|null $selection
     * @return array{0:string,1:list<mixed>}
     */
    private function confirmedSelectionSql(string $alias, ?array $selection): array
    {
        if ($selection === null) {
            return ['1=1', []];
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new RuntimeException('queue_v4_clean_alias_invalid');
        }
        $clauses = [];
        $params = [];
        foreach (array_slice($selection, 0, self::HARD_PREVIEW_LIMIT) as $row) {
            if (!is_array($row)
                || (int) ($row['queue_job_id'] ?? 0) < 1
                || preg_match('/^[a-f0-9]{64}$/', (string) ($row['selection_version'] ?? '')) !== 1
                || preg_match('/^[a-f0-9]{64}$/', (string) ($row['queue_payload_hash'] ?? '')) !== 1
                || (string) ($row['queue_idempotency_key'] ?? '') === '') {
                continue;
            }
            $clauses[] = "({$alias}.id=? AND {$alias}.company_id=? AND {$alias}.meli_account_id=?
                AND {$alias}.job_type=? AND COALESCE({$alias}.resource_id,'')=?
                AND {$alias}.idempotency_key=? AND SHA2(CAST({$alias}.payload_json AS CHAR),256)=?)";
            array_push($params,
                (int) $row['queue_job_id'],
                (int) ($row['company_id'] ?? 0),
                (int) ($row['meli_account_id'] ?? 0),
                (string) ($row['job_type'] ?? ''),
                (string) ($row['resource_id'] ?? ''),
                (string) $row['queue_idempotency_key'],
                (string) $row['queue_payload_hash']
            );
        }
        return $clauses === [] ? ['1=0', []] : ['(' . implode(' OR ', $clauses) . ')', $params];
    }

    private function humanQueueType(string $jobType, string $capability): string
    {
        if ($jobType === 'domain_exact' && $capability !== '') {
            return match ($capability) {
                'financial_reconciliation' => 'Finanzas',
                'notification_work_item' => 'Notificación',
                'order_enrichment_pack' => 'Pack exacto',
                default => 'Trabajo exacto',
            };
        }

        return match ($jobType) {
            'fresh_orders_discovery' => 'Buscar ventas',
            'order_exact' => 'Orden exacta',
            default => 'Trabajo disponible',
        };
    }

    private function humanQueueResource(string $jobType, string $capability): string
    {
        if ($jobType === 'domain_exact') {
            return match ($capability) {
                'financial_reconciliation' => 'Conciliación financiera',
                'notification_work_item' => 'Notificación recibida',
                'order_enrichment_pack' => 'Pack incompleto',
                default => 'Recurso exacto',
            };
        }

        return match ($jobType) {
            'fresh_orders_discovery' => 'Descubrimiento de ventas',
            'order_exact' => 'Orden puntual',
            default => 'Recurso disponible',
        };
    }

    /**
     * @param list<int>|null $authorizedAccountIds
     * @return array{0:string,1:list<int>}
     */
    private function accountScopeSql(string $alias, ?array $authorizedAccountIds, ?int $accountId): array
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new RuntimeException('queue_v4_clean_alias_invalid');
        }
        $accountId = max(0, (int) ($accountId ?? 0));
        if ($authorizedAccountIds !== null) {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $authorizedAccountIds),
                static fn (int $id): bool => $id > 0
            )));
            if ($accountId > 0) {
                if (!in_array($accountId, $ids, true)) {
                    return ['1=0', []];
                }
                return ["{$alias}.meli_account_id=?", [$accountId]];
            }
            if ($ids === []) {
                return ['1=0', []];
            }
            return [
                "{$alias}.meli_account_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids,
            ];
        }
        if ($accountId > 0) {
            return ["{$alias}.meli_account_id=?", [$accountId]];
        }

        return ['1=1', []];
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

    private function financialSourceFuturePredicate(string $queueAlias): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $queueAlias) !== 1) {
            throw new RuntimeException('queue_v4_clean_alias_invalid');
        }
        if (!$this->financialSourceTableExists()) {
            return '0=1';
        }

        $sourceIdSql = $this->financialSourceIdExpression($queueAlias);

        return "{$queueAlias}.job_type='domain_exact'
            AND JSON_UNQUOTE(JSON_EXTRACT({$queueAlias}.payload_json,'$.capability'))='financial_reconciliation'
            AND (
                JSON_UNQUOTE(JSON_EXTRACT({$queueAlias}.payload_json,'$.source_id')) REGEXP '^[0-9]+$'
                OR {$queueAlias}.resource_id REGEXP '^[0-9]+$'
            )
            AND EXISTS (
                SELECT 1
                  FROM sale_financial_reconciliation_jobs s
                 WHERE s.id={$sourceIdSql}
                   AND s.company_id={$queueAlias}.company_id
                   AND s.meli_account_id={$queueAlias}.meli_account_id
                   AND (
                       (s.next_run_at IS NOT NULL AND s.next_run_at>UTC_TIMESTAMP(3))
                       OR (
                           s.sale_key LIKE 'P:%'
                           AND COALESCE((
                               SELECT p.integrity_status
                                 FROM meli_packs p
                                WHERE p.meli_account_id=s.meli_account_id
                                  AND p.external_pack_id=SUBSTRING(s.sale_key,3)
                                LIMIT 1
                           ), '')<>'complete'
                       )
                   )
            )";
    }

    /**
     * @param list<mixed> $scopeParams
     */
    private function countFinanceWakeupRows(string $sourceIdSql, string $whereSql, array $scopeParams): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
               FROM queue_v4_clean_jobs q
               INNER JOIN sale_financial_reconciliation_jobs s
                       ON s.id={$sourceIdSql}
                      AND s.company_id=q.company_id
                      AND s.meli_account_id=q.meli_account_id
              WHERE {$whereSql}"
        );
        $statement->execute($scopeParams);

        return (int) $statement->fetchColumn();
    }

    private function financialWakeupTotalWhere(string $queueAlias, string $sourceAlias, string $scopeSql): string
    {
        $this->assertSqlAlias($queueAlias);
        $this->assertSqlAlias($sourceAlias);

        return "{$queueAlias}.state='waiting'
            AND {$queueAlias}.job_type='domain_exact'
            AND JSON_UNQUOTE(JSON_EXTRACT({$queueAlias}.payload_json,'$.capability'))='financial_reconciliation'
            AND (
                JSON_UNQUOTE(JSON_EXTRACT({$queueAlias}.payload_json,'$.source_id')) REGEXP '^[0-9]+$'
                OR {$queueAlias}.resource_id REGEXP '^[0-9]+$'
            )
            AND {$scopeSql}";
    }

    private function financialWakeupFutureWhere(string $queueAlias, string $sourceAlias, string $scopeSql): string
    {
        $this->assertSqlAlias($queueAlias);
        $this->assertSqlAlias($sourceAlias);

        return $this->financialWakeupTotalWhere($queueAlias, $sourceAlias, $scopeSql) . "
            AND {$sourceAlias}.next_run_at IS NOT NULL
            AND {$sourceAlias}.next_run_at>UTC_TIMESTAMP(3)";
    }

    /** Historical ready/waiting labels cannot authorize another unresolved physical request. */
    private function unresolvedPhysicalPredicate(string $queueAlias): string
    {
        $this->assertSqlAlias($queueAlias);
        return "EXISTS (SELECT 1 FROM queue_v4_clean_transport_events e
            WHERE e.company_id={$queueAlias}.company_id AND e.meli_account_id={$queueAlias}.meli_account_id
              AND e.source_kind='queue' AND e.work_id={$queueAlias}.id
              AND e.dispatch_state='PHYSICAL_STARTED' AND e.response_known_at IS NULL)";
    }

    private function financialWakeupCandidateWhere(string $queueAlias, string $sourceAlias, string $scopeSql): string
    {
        $this->assertSqlAlias($queueAlias);
        $this->assertSqlAlias($sourceAlias);

        return $this->financialWakeupTotalWhere($queueAlias, $sourceAlias, $scopeSql) . "
            AND NOT (" . $this->unresolvedPhysicalPredicate($queueAlias) . ")
            AND {$queueAlias}.available_at<=UTC_TIMESTAMP(3)
            AND ({$queueAlias}.lease_owner IS NULL OR {$queueAlias}.lease_expires_at IS NULL OR {$queueAlias}.lease_expires_at<=UTC_TIMESTAMP(3))
            AND {$sourceAlias}.status IN ('pending','retry','ready','waiting','running','awaiting_remote')
            AND {$sourceAlias}.next_run_at IS NOT NULL
            AND {$sourceAlias}.next_run_at<=UTC_TIMESTAMP(3)
            AND (
                {$sourceAlias}.sale_key IS NULL
                OR {$sourceAlias}.sale_key NOT LIKE 'P:%'
                OR COALESCE((
                    SELECT p.integrity_status
                      FROM meli_packs p
                     WHERE p.meli_account_id={$sourceAlias}.meli_account_id
                       AND p.external_pack_id=SUBSTRING({$sourceAlias}.sale_key,3)
                     LIMIT 1
                ), '')='complete'
            )";
    }

    private function financialSourceIdExpression(string $queueAlias): string
    {
        $this->assertSqlAlias($queueAlias);

        return "CAST((CASE
            WHEN JSON_UNQUOTE(JSON_EXTRACT({$queueAlias}.payload_json,'$.source_id')) REGEXP '^[0-9]+$'
                THEN JSON_UNQUOTE(JSON_EXTRACT({$queueAlias}.payload_json,'$.source_id'))
            WHEN {$queueAlias}.resource_id REGEXP '^[0-9]+$'
                THEN {$queueAlias}.resource_id
            ELSE NULL
        END) AS UNSIGNED)";
    }

    private function assertSqlAlias(string $alias): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new RuntimeException('queue_v4_clean_alias_invalid');
        }
    }

    private function financialSourceTableExists(): bool
    {
        if ($this->financialSourceTableExists === null) {
            $this->financialSourceTableExists = $this->hasTable('sale_financial_reconciliation_jobs');
        }

        return $this->financialSourceTableExists;
    }

    private function hasTable(string $table): bool
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return false;
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
            );
            $statement->execute([$table]);
            return $statement->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
