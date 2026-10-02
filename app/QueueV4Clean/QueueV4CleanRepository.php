<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use App\Services\CronDeadlineContext;
use App\Services\ManualCampaignPreviewService;
use App\Services\SaleFinancialStateService;
use RuntimeException;
use Throwable;

final class QueueV4CleanRepository
{
    private const HARD_PREVIEW_LIMIT = 60;
    private const MANUAL_CONTINUATION = 'billing_checkpoint_progress';

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

    /** @var null|\Closure():void Test-only/local fixture seam. */
    private ?\Closure $afterFinancialRecoverySourcesRead;

    /** @param null|callable():void $afterFinancialRecoverySourcesRead Test-only/local fixture seam. */
    public function __construct(private readonly PDO $pdo, ?callable $afterFinancialRecoverySourcesRead = null)
    {
        $this->afterFinancialRecoverySourcesRead = $afterFinancialRecoverySourcesRead !== null
            ? \Closure::fromCallable($afterFinancialRecoverySourcesRead)
            : null;
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
                   AND (q.lease_owner IS NULL OR q.lease_expires_at IS NULL OR q.lease_expires_at<=UTC_TIMESTAMP(3))
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
                 WHERE id=? AND company_id=? AND meli_account_id=? AND state='ready' AND lease_generation=?
                   AND (lease_owner IS NULL OR lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP(3))"
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
                AND (q.lease_owner IS NULL OR q.lease_expires_at IS NULL OR q.lease_expires_at<=UTC_TIMESTAMP(3))
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
     * Manual-only, read-only bounded keyset pages. Only fully validated rows
     * count toward the displayed limit and the single authorized lookahead.
     * @return array{rows:list<array<string,mixed>>,has_more:bool,candidate_count:int}
     */
    public function previewManualEligible(int $limit, ?array $authorizedAccountIds = null, ?int $accountId = null, ?callable $bind = null): array
    {
        $limit = max(1, min(self::HARD_PREVIEW_LIMIT, $limit));
        $candidateLimit = $limit + 1;
        $deadline = min(microtime(true) + 10.0, (CronDeadlineContext::deadline() ?? INF) - 1.0);
        [$scopeSql, $scopeParams] = $this->accountScopeSql('q', $authorizedAccountIds, $accountId);
        $sql =
            "SELECT q.*,q.id queue_job_id,SHA2(CAST(q.payload_json AS CHAR),256) queue_payload_hash,
                    JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability')) capability,
                    a.account_name,c.name company_name
               FROM queue_v4_clean_jobs q
               JOIN meli_accounts a ON a.id=q.meli_account_id AND a.company_id=q.company_id
               JOIN companies c ON c.id=q.company_id
              WHERE {$scopeSql} AND %s AND q.available_at<=UTC_TIMESTAMP(3)
                AND NOT (" . $this->unresolvedPhysicalPredicate('q') . ")
                AND ((q.state='ready'
                      AND (q.lease_owner IS NULL OR q.lease_expires_at IS NULL OR q.lease_expires_at<=UTC_TIMESTAMP(3))
                      AND NOT (" . $this->financialSourceFuturePredicate('q') . "))
                     OR ((" . $this->manualContinuationQueuePredicate('q') . ")
                         AND (" . $this->manualContinuationCheapSourcePredicate('q') . ")))
              ORDER BY q.available_at,q.id LIMIT {$candidateLimit}";
        $rows = [];
        $candidateCount = 0;
        $cursor = null;
        do {
            $this->assertManualPreviewDeadline($deadline);
            $cursorSql = $cursor === null ? '1=1' : '(q.available_at>? OR (q.available_at=? AND q.id>?))';
            // Use replacement, not sprintf: source predicates contain SQL LIKE
            // percent signs and must remain literal.
            $statement = $this->pdo->prepare(str_replace('%s', $cursorSql, $sql));
            $statement->execute(array_merge($scopeParams, $cursor === null ? [] : [$cursor[0], $cursor[0], $cursor[1]]));
            $candidates = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($candidates as $row) {
                $this->assertManualPreviewDeadline($deadline);
                $candidateCount++;
                $cursor = [(string) $row['available_at'], (int) $row['queue_job_id']];
                if ((string) $row['state'] === 'waiting') {
                    $source = $this->manualContinuationSource($row);
                    if ($source === null) {
                        continue;
                    }
                    $row += [
                        'manual_continuation_kind' => self::MANUAL_CONTINUATION,
                        'queue_lease_generation' => (int) $row['lease_generation'],
                        'financial_source_lease_generation' => (int) $source['lease_generation'],
                        'financial_input_version' => (string) $source['input_version'],
                    ];
                }
                $human = $this->humanQueuePreviewRow($row, count($rows) + 1);
                $bound = $bind === null ? $human : $bind($human);
                $this->assertManualPreviewDeadline($deadline);
                if ($bound === null) {
                    continue;
                }
                $rows[] = $bound;
                if (count($rows) > $limit) {
                    break 2;
                }
            }
        } while (count($candidates) === $candidateLimit);
        $this->assertManualPreviewDeadline($deadline);
        return ['rows' => array_slice($rows, 0, $limit), 'has_more' => count($rows) > $limit, 'candidate_count' => $candidateCount];
    }

    private function assertManualPreviewDeadline(float $deadline): void
    {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('El cálculo de pendientes agotó su tiempo seguro. Vuelva a calcular; no se guardó una selección parcial.');
        }
    }

    /**
     * Called only inside manual admission's transaction/global lease. Invalid
     * individual rows are left untouched; infrastructure errors abort admission.
     * @param list<array<string,mixed>> $selection
     * @return list<array<string,mixed>>
     */
    public function admitManualConfirmedSelection(array $selection, ?array $authorizedAccountIds = null, ?int $accountId = null): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('manual_continuation_transaction_required');
        }
        [$scopeSql, $scopeParams] = $this->accountScopeSql('q', $authorizedAccountIds, $accountId);
        $accepted = [];
        foreach (array_slice($selection, 0, self::HARD_PREVIEW_LIMIT) as $snapshot) {
            if (!is_array($snapshot) || !$this->validManualContinuationFields($snapshot)) {
                continue;
            }
            [$identitySql, $identityParams] = $this->confirmedSelectionSql('q', [$snapshot]);
            $statement = $this->pdo->prepare(
                "SELECT q.* FROM queue_v4_clean_jobs q WHERE {$scopeSql} AND {$identitySql} FOR UPDATE"
            );
            $statement->execute(array_merge($scopeParams, $identityParams));
            $queue = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($queue)) {
                continue;
            }
            if (isset($snapshot['manual_continuation_kind'])) {
                $source = $this->manualContinuationSource($queue, true);
                if ($source === null || !$this->manualSourceSnapshotMatches($snapshot, $source)
                    || !ManualCampaignPreviewService::availableSourceIdentityMatches($snapshot)) {
                    continue;
                }
                $update = $this->pdo->prepare(
                    "UPDATE queue_v4_clean_jobs q SET state='ready'
                      WHERE {$scopeSql} AND {$identitySql}
                        AND (" . $this->manualContinuationQueuePredicate('q') . ")
                        AND NOT (" . $this->unresolvedPhysicalPredicate('q') . ")"
                );
                // An unchanged automatic wake to ready is acceptable. The
                // identity/generation SQL still rejects a claim/checkpoint ABA.
                if ((string) $queue['state'] === 'waiting') {
                    $update->execute(array_merge($scopeParams, $identityParams));
                    if ($update->rowCount() !== 1) {
                        continue;
                    }
                } elseif ((string) $queue['state'] !== 'ready') {
                    continue;
                }
            }
            $accepted[] = $snapshot;
        }
        return $this->claimableConfirmedSelection($accepted, $authorizedAccountIds, $accountId);
    }

    /** Domain binding deliberately does not compare q generation: the worker
     * calls it after its own fenced ready-only claim increments that generation.
     * Admission and confirmedSelectionSql enforce q generation before claim.
     */
    public function manualContinuationSourceIdentityMatches(array $snapshot): bool
    {
        if (!$this->validManualContinuationFields($snapshot)) {
            return false;
        }
        if (!isset($snapshot['manual_continuation_kind'])) {
            return true;
        }
        $source = $this->manualContinuationSource([
            'company_id' => $snapshot['company_id'] ?? 0,
            'meli_account_id' => $snapshot['meli_account_id'] ?? 0,
            'resource_id' => $snapshot['resource_id'] ?? '',
            'job_type' => $snapshot['job_type'] ?? '',
            'payload_json' => json_encode(['capability' => $snapshot['capability'] ?? '', 'source_id' => $snapshot['resource_id'] ?? '']),
        ]);
        return $source !== null && $this->manualSourceSnapshotMatches($snapshot, $source);
    }

    private function manualContinuationQueuePredicate(string $alias): string
    {
        $this->assertSqlAlias($alias);
        return "{$alias}.state='waiting' AND {$alias}.job_type='domain_exact'
            AND BINARY {$alias}.last_error_class='domain_source_waiting:financial_reconciliation:billing_checkpoint_progress'
            AND {$alias}.available_at<=UTC_TIMESTAMP(3) AND {$alias}.attempt_count<{$alias}.max_attempts
            AND (({$alias}.lease_owner IS NULL AND {$alias}.lease_expires_at IS NULL)
                 OR ({$alias}.lease_owner IS NOT NULL AND {$alias}.lease_expires_at IS NOT NULL
                     AND {$alias}.lease_expires_at<=UTC_TIMESTAMP(3)))";
    }

    /** Cheap prefilter only; the shared live-input/checkpoint validator remains
     * authoritative before showing or admitting any continuation. */
    private function manualContinuationCheapSourcePredicate(string $alias): string
    {
        $this->assertSqlAlias($alias);
        if (!$this->financialSourceTableExists()) {
            return '0=1';
        }
        return "BINARY JSON_UNQUOTE(JSON_EXTRACT({$alias}.payload_json,'$.capability'))='financial_reconciliation'
            AND {$alias}.resource_id REGEXP '^[1-9][0-9]*$'
            AND BINARY JSON_UNQUOTE(JSON_EXTRACT({$alias}.payload_json,'$.source_id'))=BINARY {$alias}.resource_id
            AND EXISTS (SELECT 1 FROM sale_financial_reconciliation_jobs ms
                JOIN sale_financial_state st ON st.company_id=ms.company_id AND st.meli_account_id=ms.meli_account_id
                     AND st.sale_key=ms.sale_key AND st.input_version=ms.input_version
                JOIN meli_packs p ON p.meli_account_id=ms.meli_account_id AND p.external_pack_id=SUBSTRING(ms.sale_key,3)
                WHERE ms.id={$alias}.resource_id AND ms.company_id={$alias}.company_id AND ms.meli_account_id={$alias}.meli_account_id
                  AND ms.status='retry' AND ms.sale_key LIKE 'P:%' AND p.integrity_status='complete'
                  AND ms.next_run_at<=UTC_TIMESTAMP(3)
                  AND ((ms.lock_owner IS NULL AND ms.lease_expires_at IS NULL)
                       OR (ms.lock_owner IS NOT NULL AND ms.lease_expires_at IS NOT NULL AND ms.lease_expires_at<=UTC_TIMESTAMP(3)))
                  AND NOT (" . $this->financialSourceManualReservationPredicate('ms') . "))";
    }

    /** Shared read-only authority for preview, binding and locked admission. */
    private function manualContinuationSource(array $queue, bool $forUpdate = false): ?array
    {
        $payload = json_decode((string) ($queue['payload_json'] ?? ''), true);
        $sourceId = (string) ($queue['resource_id'] ?? '');
        if ((string) ($queue['job_type'] ?? '') !== 'domain_exact'
            || !is_array($payload) || ($payload['capability'] ?? null) !== 'financial_reconciliation'
            || preg_match('/^[1-9][0-9]*$/D', $sourceId) !== 1
            || (!is_string($payload['source_id'] ?? null) && !is_int($payload['source_id'] ?? null))
            || (string) $payload['source_id'] !== $sourceId) {
            return null;
        }
        $statement = $this->pdo->prepare(
            "SELECT s.* FROM sale_financial_reconciliation_jobs s
               JOIN meli_accounts a ON a.id=s.meli_account_id AND a.company_id=s.company_id
               JOIN sale_financial_state st ON st.company_id=s.company_id AND st.meli_account_id=s.meli_account_id
                    AND st.sale_key=s.sale_key AND st.input_version=s.input_version
               JOIN meli_packs p ON p.meli_account_id=s.meli_account_id AND p.external_pack_id=SUBSTRING(s.sale_key,3)
              WHERE s.id=? AND s.company_id=? AND s.meli_account_id=? AND s.status='retry'
                AND s.sale_key LIKE 'P:%' AND p.integrity_status='complete'
                AND s.next_run_at<=UTC_TIMESTAMP(3)
                AND ((s.lock_owner IS NULL AND s.lease_expires_at IS NULL)
                     OR (s.lock_owner IS NOT NULL AND s.lease_expires_at IS NOT NULL AND s.lease_expires_at<=UTC_TIMESTAMP(3)))
                AND NOT (" . $this->financialSourceManualReservationPredicate('s') . ")"
                . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$sourceId, (int) ($queue['company_id'] ?? 0), (int) ($queue['meli_account_id'] ?? 0)]);
        $source = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($source)) {
            return null;
        }
        $orders = $this->pdo->prepare(
            'SELECT o.id,o.external_order_id FROM meli_orders o
              JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=? AND o.external_pack_id=? ORDER BY o.id'
             . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $orders->execute([(int) $source['company_id'], (int) $source['meli_account_id'], substr((string) $source['sale_key'], 2)]);
        $orderRows = $orders->fetchAll(PDO::FETCH_ASSOC);
        if (count($orderRows) < 2
            || !hash_equals((string) $source['input_version'], (new SaleFinancialStateService())->inputVersionForOrder((int) $orderRows[0]['id']))) {
            return null;
        }
        $known = array_fill_keys(array_map(static fn (array $row): string => (string) $row['external_order_id'], $orderRows), true);
        $evidence = $this->pdo->prepare(
            'SELECT e.source_id,e.evidence_json,c.requested_order_ids_json
               FROM sale_financial_evidence e
               JOIN meli_billing_capture_runs c ON c.id=e.source_id AND c.company_id=e.company_id
                    AND c.meli_account_id=e.meli_account_id AND c.sale_key=e.sale_key AND c.input_version=e.input_version
              WHERE e.company_id=? AND e.meli_account_id=? AND e.sale_key=? AND e.input_version=?
                AND e.evidence_type="billing_capture" AND e.evidence_status="reconciled"
                AND c.http_status=200 AND c.response_class="complete" AND c.captured_at IS NOT NULL'
                . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $evidence->execute([(int) $source['company_id'], (int) $source['meli_account_id'], (string) $source['sale_key'], (string) $source['input_version']]);
        $completed = [];
        foreach ($evidence->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $checkpoint = json_decode((string) $row['evidence_json'], true);
            $requested = json_decode((string) $row['requested_order_ids_json'], true);
            if (!is_array($checkpoint) || ($checkpoint['format'] ?? null) !== 'billing_order_v2'
                || (!is_string($checkpoint['order_id'] ?? null) && !is_int($checkpoint['order_id'] ?? null))
                || (!is_int($checkpoint['capture_id'] ?? null) && !is_string($checkpoint['capture_id'] ?? null))) {
                continue;
            }
            $orderId = (string) $checkpoint['order_id'];
            $captureId = (string) $checkpoint['capture_id'];
            if (preg_match('/^[1-9][0-9]*$/D', $captureId) !== 1 || $captureId !== (string) $row['source_id']
                || !isset($known[$orderId]) || !is_array($requested) || count($requested) !== 1
                || (!is_string($requested[0] ?? null) && !is_int($requested[0] ?? null))
                || (string) $requested[0] !== $orderId) {
                continue;
            }
            $completed[$orderId] = true;
        }
        return count($completed) > 0 && count($completed) < count($known) ? $source : null;
    }

    private function validManualContinuationFields(array $row): bool
    {
        $fields = ['manual_continuation_kind', 'queue_lease_generation', 'financial_source_lease_generation', 'financial_input_version'];
        if (array_intersect($fields, array_keys($row)) === []) {
            return true;
        }
        return ($row['manual_continuation_kind'] ?? null) === self::MANUAL_CONTINUATION
            && is_int($row['queue_lease_generation'] ?? null) && $row['queue_lease_generation'] >= 0
            && is_int($row['financial_source_lease_generation'] ?? null) && $row['financial_source_lease_generation'] >= 0
            && is_string($row['financial_input_version'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $row['financial_input_version']) === 1;
    }

    private function manualSourceSnapshotMatches(array $snapshot, array $source): bool
    {
        return (int) $source['lease_generation'] === $snapshot['financial_source_lease_generation']
            && hash_equals($snapshot['financial_input_version'], (string) $source['input_version']);
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
                AND (q.lease_owner IS NULL OR q.lease_expires_at IS NULL OR q.lease_expires_at<=UTC_TIMESTAMP(3))
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
                AND (q.lease_owner IS NULL OR q.lease_expires_at IS NULL OR q.lease_expires_at<=UTC_TIMESTAMP(3))
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
                            q.job_type queue_job_type,q.lease_owner queue_lease_owner,
                            q.lease_expires_at queue_lease_expires_at,
                            JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,"$.capability")) queue_capability,
                            s.id source_id,s.status source_status,s.sale_key,s.input_version,s.safe_message,
                            s.next_run_at source_next_run_at,s.lock_owner source_lock_owner,
                            s.lease_expires_at source_lease_expires_at,
                            (' . $this->financialSourceManualReservationPredicate('s') . ') source_reserved_by_manual,
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
                $boundaryReached = false;
                foreach ($rows as $row) {
                    $cursorAvailableAt = (string) ($row['queue_available_at'] ?? $cursorAvailableAt);
                    $cursorJobId = (int) ($row['queue_job_id'] ?? $cursorJobId);
                    if ((int) ($row['queue_company_id'] ?? 0) !== $companyId
                        || (int) ($row['queue_account_id'] ?? 0) !== $accountId
                        || (string) ($row['queue_job_type'] ?? '') !== 'domain_exact'
                        || (string) ($row['queue_capability'] ?? '') !== 'financial_reconciliation') {
                        $boundaryReached = true;
                        break;
                    }
                    $leaseOwner = trim((string) ($row['queue_lease_owner'] ?? ''));
                    $leaseExpiresAt = strtotime((string) ($row['queue_lease_expires_at'] ?? '') . ' UTC');
                    $sourceLockOwner = trim((string) ($row['source_lock_owner'] ?? ''));
                    $sourceLeaseExpiresAt = strtotime((string) ($row['source_lease_expires_at'] ?? '') . ' UTC');
                    $sourceNextRunAt = strtotime((string) ($row['source_next_run_at'] ?? '') . ' UTC');
                    if (($leaseOwner !== '' && ($leaseExpiresAt === false || $leaseExpiresAt > time()))
                        || ($sourceLockOwner !== '' && ($sourceLeaseExpiresAt === false || $sourceLeaseExpiresAt > time()))
                        || ($sourceNextRunAt !== false && $sourceNextRunAt > time())
                        || (int) ($row['source_reserved_by_manual'] ?? 0) === 1
                        || !in_array((string) ($row['source_status'] ?? ''), ['pending', 'retry', 'awaiting_remote'], true)
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
                if ($boundaryReached || count($rows) < 240) {
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
        bool $requireCurrentEligibility = false,
    ): void {
        $this->assertTenant($companyId, $accountId);
        $currentEligibility = $requireCurrentEligibility
            ? ' AND (' . $this->readyFinancialReconciliationPointerSourcePredicate('q') . ')'
            : '';
        $complete = $this->pdo->prepare(
            'UPDATE queue_v4_clean_jobs q
             SET state="completed",completed_at=UTC_TIMESTAMP(3),last_error_class=NULL
              WHERE q.company_id=? AND q.meli_account_id=? AND q.resource_id=? AND q.state="ready"
                AND q.job_type="domain_exact"
                AND (q.lease_owner IS NULL OR q.lease_expires_at IS NULL OR q.lease_expires_at<=UTC_TIMESTAMP(3))
                AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,"$.capability"))="financial_reconciliation"'
                . $currentEligibility
        );
        $waiting = $this->pdo->prepare(
            'UPDATE queue_v4_clean_jobs q
             SET state="waiting",available_at=?,completed_at=NULL,last_error_class=?
              WHERE q.company_id=? AND q.meli_account_id=? AND q.resource_id=? AND q.state="ready"
                AND q.job_type="domain_exact"
                AND (q.lease_owner IS NULL OR q.lease_expires_at IS NULL OR q.lease_expires_at<=UTC_TIMESTAMP(3))
                AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,"$.capability"))="financial_reconciliation"'
                . $currentEligibility
        );
        $review = $this->pdo->prepare(
            'UPDATE queue_v4_clean_jobs q
             SET state="review",completed_at=NULL,last_error_class=?
              WHERE q.company_id=? AND q.meli_account_id=? AND q.resource_id=? AND q.state="ready"
                AND q.job_type="domain_exact"
                AND (q.lease_owner IS NULL OR q.lease_expires_at IS NULL OR q.lease_expires_at<=UTC_TIMESTAMP(3))
                AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,"$.capability"))="financial_reconciliation"'
                . $currentEligibility
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
             WHERE company_id=? AND meli_account_id=? AND id IN (' . $in . ')
             AND status IN ("pending","retry","awaiting_remote")
             AND next_run_at<=UTC_TIMESTAMP(3)
             AND (lock_owner IS NULL OR lease_expires_at IS NULL OR lease_expires_at<=UTC_TIMESTAMP(3))
             AND NOT (' . $this->financialSourceManualReservationPredicate('sale_financial_reconciliation_jobs') . ')'
        );
        $statement->execute(array_merge([$companyId, $accountId], $sourceIds));
        $outcomes = [];
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($this->afterFinancialRecoverySourcesRead !== null) {
            ($this->afterFinancialRecoverySourcesRead)();
        }
        foreach ($rows as $row) {
            $sourceId = (int) ($row['id'] ?? 0);
            if ($sourceId < 1) {
                continue;
            }
            $outcomes[$sourceId] = [
                'state' => 'waiting',
                'classification' => 'domain_source_waiting:financial_reconciliation',
                'next_safe_at' => (string) ($row['next_run_at'] ?? ''),
            ];
        }
        $this->alignReadyFinancialReconciliationPointers($companyId, $accountId, $outcomes, $exceptSourceId, true);
    }

    public function complete(array $job, int $runId): void
    {
        $this->finish($job, $runId, 'completed', 'completed', null, null);
    }

    /**
     * Finalize a notification pointer while holding the durable source row.
     * This closes the post-processing race without keeping a transaction open
     * during HTTP: a later webhook either commits first and is observed here,
     * or waits and creates/reopens executable work after this commit.
     *
     * @param array<string,mixed> $job
     * @return array{state:string,classification:string,next_safe_at?:string}
     */
    public function finalizeNotificationPointer(array $job, int $runId): array
    {
        $companyId = (int) ($job['company_id'] ?? 0);
        $accountId = (int) ($job['meli_account_id'] ?? 0);
        $sourceId = (int) ($job['resource_id'] ?? 0);
        $this->assertTenant($companyId, $accountId);
        if ($sourceId < 1) {
            throw new RuntimeException('notification_pointer_source_invalid');
        }

        $this->pdo->beginTransaction();
        try {
            $source = $this->pdo->prepare(
                'SELECT w.status,w.next_run_at
                 FROM meli_notification_work_items w
                 INNER JOIN meli_accounts ma ON ma.id=w.meli_account_id AND ma.company_id=?
                 WHERE w.id=? AND w.meli_account_id=? LIMIT 1 FOR UPDATE'
            );
            $source->execute([$companyId, $sourceId, $accountId]);
            $row = $source->fetch(PDO::FETCH_ASSOC);
            $status = is_array($row) ? strtolower(trim((string) ($row['status'] ?? ''))) : '';

            if (in_array($status, ['complete', 'ignored'], true)) {
                $outcome = ['state' => 'completed', 'classification' => 'completed'];
                $this->finishInTransaction($job, $runId, 'completed', 'completed', null, true, null, false, null);
            } elseif (in_array($status, ['pending', 'retry', 'running'], true)) {
                $nextSafeAt = $this->safeUtcDateTime(is_array($row) ? (string) ($row['next_run_at'] ?? '') : null);
                $outcome = [
                    'state' => 'waiting',
                    'classification' => 'domain_source_waiting:notification_work_item',
                    'next_safe_at' => $nextSafeAt,
                ];
                $this->finishInTransaction(
                    $job,
                    $runId,
                    'waiting',
                    'waiting',
                    $outcome['classification'],
                    false,
                    $nextSafeAt,
                    true,
                    null,
                );
            } else {
                $classification = 'domain_source_' . ($status !== ''
                    ? substr(preg_replace('/[^a-z0-9_]+/', '_', $status) ?: 'unknown', 0, 100)
                    : 'missing');
                $outcome = ['state' => 'review', 'classification' => $classification];
                $this->finishInTransaction($job, $runId, 'review', 'review', $classification, true, null, false, null);
            }
            $this->pdo->commit();
            return $outcome;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
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

    /** Return a manual claim whose durable source changed before its effect boundary. */
    public function releaseManualStaleClaim(array $job, int $runId): void
    {
        $companyId = (int) ($job['company_id'] ?? 0);
        $accountId = (int) ($job['meli_account_id'] ?? 0);
        $this->assertTenant($companyId, $accountId);
        $this->pdo->beginTransaction();
        try {
            $pointer = $this->pdo->prepare(
                "UPDATE queue_v4_clean_jobs
                 SET state='ready',lease_owner=NULL,lease_expires_at=NULL,
                     attempt_count=GREATEST(attempt_count-1,0),last_error_class='manual_selection_source_changed'
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND state='running' AND lease_owner=? AND lease_generation=? AND attempt_count=?"
            );
            $pointer->execute([
                (int) $job['id'], $companyId, $accountId, (string) $job['lease_owner'],
                (int) $job['lease_generation'], (int) $job['attempt_count'],
            ]);
            if ($pointer->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_manual_stale_pointer_cas_lost');
            }
            $attempt = $this->pdo->prepare(
                "UPDATE queue_v4_clean_attempts
                 SET outcome='lease_expired',error_class='manual_selection_source_changed',
                     finished_at=UTC_TIMESTAMP(3),source_closed_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=?
                   AND lease_owner=? AND lease_generation=? AND outcome='running'"
            );
            $attempt->execute([
                (int) $job['attempt_id'], (int) $job['id'], $companyId, $accountId,
                (string) $job['lease_owner'], (int) $job['lease_generation'],
            ]);
            if ($attempt->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_manual_stale_attempt_cas_lost');
            }
            $run = $this->pdo->prepare(
                "UPDATE queue_v4_clean_runs SET jobs_claimed=GREATEST(jobs_claimed-1,0)
                 WHERE id=? AND status='running'"
            );
            $run->execute([$runId]);
            if ($run->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_manual_stale_run_cas_lost');
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
                 AND (q.lease_owner IS NULL OR q.lease_expires_at IS NULL OR q.lease_expires_at<=UTC_TIMESTAMP(3))
                 AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='financial_reconciliation'
                AND EXISTS (
                    SELECT 1
                      FROM sale_financial_reconciliation_jobs s
                     WHERE s.id=CAST(q.resource_id AS UNSIGNED)
                       AND s.company_id=q.company_id
                       AND s.meli_account_id=q.meli_account_id
                       AND (s.lock_owner IS NULL OR s.lease_expires_at IS NULL OR s.lease_expires_at<=UTC_TIMESTAMP(3))
                       AND NOT (" . $this->financialSourceManualReservationPredicate('s') . ")
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
    public function releaseDueRetryableDirectWaiting(?array $authorizedAccountIds = null, ?int $accountId = null): int
    {
        // Closed, zero-dispatch causes emitted by the existing worker/transport only.
        // Labels alone never authorize reentry: the attempt and journal must agree.
        $notDispatchedCauses = [
            'oauth_refresh_required', 'pre_transport_deferred', 'pre_transport_lease_expired',
            'capacity_deferred:budget', 'capacity_deferred:cron_deadline',
            'capacity_deferred:manual_burst', 'capacity_deferred:budget_infrastructure',
            'manual_pause:app', 'manual_pause:account',
            'rate_limit_deferred:rhythm', 'rate_limit_deferred:rhythm_permit_busy',
            'rate_limit_deferred:rhythm_interval', 'rate_limit_deferred:rhythm_block_pause',
            'rate_limit_deferred:rhythm_shared_orders_search_window',
            'rate_limit_deferred:rhythm_global_window',
            'rate_limit_deferred:rhythm_penalty_state_unavailable',
            'rate_limit_deferred:retry_after', 'rate_limit_deferred:rhythm_endpoint_shared_reduced',
            'rate_limit_deferred:rhythm_account_reduced',
            'rate_limit_deferred:billing_endpoint_interval', 'rate_limit_deferred:billing_429_backoff',
            'rate_limit_deferred:rhythm_authority_unavailable', 'rate_limit_deferred:rhythm_fence_stale',
        ];
        [$scopeSql, $scopeParams] = $this->accountScopeSql('q', $authorizedAccountIds, $accountId);
        $causesSql = implode(',', array_fill(0, count($notDispatchedCauses), '?'));
        $statement = $this->pdo->prepare(
            "UPDATE queue_v4_clean_jobs q SET q.state='ready'
             WHERE q.job_type IN ('order_exact','fresh_orders_discovery')
               AND q.state='waiting' AND q.available_at<=UTC_TIMESTAMP(3)
               AND q.attempt_count<q.max_attempts AND q.lease_generation>0
               AND {$scopeSql}
               AND EXISTS (SELECT 1 FROM meli_accounts m
                   WHERE m.id=q.meli_account_id AND m.company_id=q.company_id)
               AND NOT (" . $this->unresolvedPhysicalPredicate('q') . ")
               AND EXISTS (
                   SELECT 1 FROM queue_v4_clean_attempts a
                   WHERE a.job_id=q.id AND a.company_id=q.company_id AND a.meli_account_id=q.meli_account_id
                     AND a.lease_generation=q.lease_generation AND a.outcome='waiting'
                     AND a.finished_at IS NOT NULL AND a.source_closed_at IS NOT NULL
                     AND a.error_class=q.last_error_class
                     AND ((q.lease_owner IS NULL AND q.lease_expires_at IS NULL)
                          OR (q.lease_owner=a.lease_owner AND q.lease_owner<>''
                              AND q.lease_expires_at<=UTC_TIMESTAMP(3)))
                     AND NOT EXISTS (SELECT 1 FROM queue_v4_clean_attempts newer
                         WHERE newer.job_id=q.id AND newer.company_id=q.company_id
                           AND newer.meli_account_id=q.meli_account_id
                           AND newer.lease_generation>=q.lease_generation AND newer.id<>a.id)
                     AND NOT EXISTS (SELECT 1 FROM queue_v4_clean_transport_events misplaced
                         WHERE misplaced.source_kind='queue' AND misplaced.work_id=q.id
                           AND misplaced.company_id=q.company_id AND misplaced.meli_account_id=q.meli_account_id
                           AND misplaced.attempt_id=a.id AND misplaced.lease_generation<>a.lease_generation)
                     AND (
                         (a.dispatch_state='NOT_DISPATCHED' AND a.physical_http_calls=0
                          AND a.physical_started_at IS NULL AND a.http_status IS NULL AND a.response_known_at IS NULL
                          AND a.error_class IN ({$causesSql})
                          AND NOT EXISTS (SELECT 1 FROM queue_v4_clean_transport_events e
                              WHERE e.source_kind='queue' AND e.work_id=q.id
                                AND e.company_id=q.company_id AND e.meli_account_id=q.meli_account_id
                                AND e.lease_generation=a.lease_generation))
                         OR
                         (a.dispatch_state='RESPONSE_KNOWN' AND a.physical_http_calls=1
                          AND a.transport_method='GET' AND a.endpoint_key<>''
                          AND a.physical_started_at IS NOT NULL AND a.response_known_at IS NOT NULL
                          AND (a.http_status=429 OR a.http_status BETWEEN 500 AND 599)
                          AND (SELECT COUNT(*) FROM queue_v4_clean_transport_events e
                              WHERE e.source_kind='queue' AND e.work_id=q.id
                                AND e.company_id=q.company_id AND e.meli_account_id=q.meli_account_id
                                AND e.lease_generation=a.lease_generation)=1
                          AND EXISTS (SELECT 1 FROM queue_v4_clean_transport_events e
                              WHERE e.source_kind='queue' AND e.work_id=q.id AND e.attempt_id=a.id
                                AND e.company_id=q.company_id AND e.meli_account_id=q.meli_account_id
                                AND e.lease_generation=a.lease_generation AND e.method=a.transport_method
                                AND e.endpoint_key=a.endpoint_key AND e.dispatch_state='RESPONSE_KNOWN'
                                AND e.physical_started_at IS NOT NULL AND e.response_known_at IS NOT NULL
                                AND e.http_status=a.http_status))
                     )
               )
             ORDER BY q.available_at,q.id LIMIT 200"
        );
        // A single bounded local UPDATE, never an HTTP budget or an unbounded draining loop.
        // Query failures propagate; they cannot certify absence of a transport restriction.
        $statement->execute(array_merge($scopeParams, $notDispatchedCauses));
        return $statement->rowCount() + $this->releaseDueSafePackWaiting($authorizedAccountIds, $accountId);
    }

    /**
     * Reenter the same admitted pack unit, not a new admission. Unlike direct
     * GET reentry above, this branch NEVER accepts RESPONSE_KNOWN/429/5xx.
     * One atomic, bounded UPDATE rechecks state and durable evidence under
     * the database row locks. It does not change source, attempts or fences.
     * @param list<int>|null $authorizedAccountIds
     */
    private function releaseDueSafePackWaiting(?array $authorizedAccountIds, ?int $accountId): int
    {
        [$scopeSql, $scopeParams] = $this->accountScopeSql('q', $authorizedAccountIds, $accountId);
        // CASE prevents malformed JSON from being evaluated as an identity.
        $payload = "(CASE WHEN JSON_VALID(q.payload_json) THEN q.payload_json ELSE '{}' END)";
        $sourceId = "JSON_UNQUOTE(JSON_EXTRACT({$payload},'$.source_id'))";
        $statement = $this->pdo->prepare(
            "UPDATE queue_v4_clean_jobs q SET q.state='ready'
             WHERE q.job_type='domain_exact' AND q.state='waiting'
               AND q.company_id>0 AND q.meli_account_id>0 AND {$scopeSql}
               AND q.available_at IS NOT NULL AND q.available_at<=UTC_TIMESTAMP(3)
               AND q.attempt_count<q.max_attempts AND q.lease_generation>0
               AND JSON_VALID(q.payload_json)=1
               AND BINARY JSON_UNQUOTE(JSON_EXTRACT({$payload},'$.capability'))=BINARY 'order_enrichment_pack'
               AND q.resource_id REGEXP '^[1-9][0-9]*$'
               AND {$sourceId} REGEXP '^[1-9][0-9]*$'
               AND BINARY {$sourceId}=BINARY q.resource_id
               AND BINARY q.last_error_class IN (
                   'domain_source_waiting:order_enrichment_pack:waiting_deadline',
                   'domain_source_waiting:order_enrichment_pack:waiting_rhythm')
               AND EXISTS (
                   SELECT 1 FROM order_resource_enrichment_jobs s
                   JOIN meli_accounts m ON m.id=s.meli_account_id AND m.company_id=q.company_id
                   WHERE s.id=CAST(q.resource_id AS UNSIGNED) AND s.meli_account_id=q.meli_account_id
                     AND BINARY CAST(s.id AS CHAR)=BINARY q.resource_id
                     AND s.resource_type='pack'
                     AND s.external_resource_id IS NOT NULL AND s.external_resource_id<>''
                     AND (JSON_CONTAINS_PATH({$payload},'one','$.pack_id')=0
                          OR BINARY JSON_UNQUOTE(JSON_EXTRACT({$payload},'$.pack_id'))=BINARY s.external_resource_id)
                     AND (JSON_CONTAINS_PATH({$payload},'one','$.payload.pack_id')=0
                          OR BINARY JSON_UNQUOTE(JSON_EXTRACT({$payload},'$.payload.pack_id'))=BINARY s.external_resource_id)
                     AND s.next_run_at IS NOT NULL AND s.next_run_at<=UTC_TIMESTAMP()
                     AND ((s.locked_at IS NULL AND s.lock_token IS NULL)
                          OR (s.locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)
                              AND s.lock_token IS NOT NULL AND s.lock_token<>''))
                     AND (
                         (BINARY q.last_error_class=BINARY 'domain_source_waiting:order_enrichment_pack:waiting_deadline'
                          AND ((s.status='pending' AND BINARY COALESCE(s.failure_class,'')=BINARY ''
                                AND (s.reached_remote IS NULL OR s.reached_remote=0))
                               OR (s.status='retry' AND BINARY s.failure_class=BINARY 'waiting_deadline' AND s.reached_remote=0)))
                         OR (BINARY q.last_error_class=BINARY 'domain_source_waiting:order_enrichment_pack:waiting_rhythm'
                             AND s.status='retry' AND BINARY s.failure_class=BINARY 'waiting_rhythm' AND s.reached_remote=0)
                     )
                     AND NOT EXISTS (
                         SELECT 1 FROM manual_campaign_reservations r
                         JOIN manual_campaigns c ON c.id=r.manual_campaign_id
                         WHERE r.queue_key='order_enrichment' AND r.source_id=CAST(s.id AS CHAR)
                           AND r.status='active' AND r.expires_at>UTC_TIMESTAMP(3)
                           AND c.status IN ('active','pausing','paused'))
               )
               AND EXISTS (
                   SELECT 1 FROM queue_v4_clean_attempts a
                   WHERE a.job_id=q.id AND a.company_id=q.company_id AND a.meli_account_id=q.meli_account_id
                     AND a.lease_generation=q.lease_generation AND a.outcome='waiting'
                     AND BINARY a.error_class=BINARY q.last_error_class
                     AND a.finished_at IS NOT NULL AND a.source_closed_at IS NOT NULL
                     AND a.source_closed_at>=a.started_at AND a.finished_at>=a.source_closed_at
                     AND a.dispatch_state='NOT_DISPATCHED' AND a.physical_http_calls=0
                     AND a.physical_started_at IS NULL AND a.http_status IS NULL AND a.response_known_at IS NULL
                     AND ((q.lease_owner IS NULL AND q.lease_expires_at IS NULL)
                          OR (q.lease_owner=a.lease_owner AND q.lease_owner<>''
                              AND q.lease_expires_at IS NOT NULL AND q.lease_expires_at<=UTC_TIMESTAMP(3)))
                     AND NOT EXISTS (
                         SELECT 1 FROM queue_v4_clean_attempts other
                         WHERE other.job_id=q.id
                           AND (other.company_id<>q.company_id OR other.meli_account_id<>q.meli_account_id
                                OR (other.id<>a.id AND (other.id>a.id OR other.lease_generation>=a.lease_generation))
                                OR other.outcome='running' OR other.dispatch_state='PHYSICAL_STARTED'
                                OR other.error_class IN ('remote_result_uncertain','remoteresultuncertainexception',
                                                        'remote_result_uncertain_safe_get')))
                     AND NOT EXISTS (
                         SELECT 1 FROM queue_v4_clean_transport_events e
                         WHERE e.attempt_id=a.id
                            OR (((e.source_kind='queue' AND e.work_id=q.id)
                                 OR EXISTS (SELECT 1 FROM queue_v4_clean_attempts linked
                                            WHERE linked.id=e.attempt_id AND linked.job_id=q.id))
                                AND (e.source_kind<>'queue' OR e.work_id<>q.id
                                     OR e.company_id<>q.company_id OR e.meli_account_id<>q.meli_account_id
                                     OR e.lease_generation>=a.lease_generation
                                     OR (e.dispatch_state='PHYSICAL_STARTED' AND e.response_known_at IS NULL)
                                     OR NOT EXISTS (
                                         SELECT 1 FROM queue_v4_clean_attempts ea
                                         WHERE ea.id=e.attempt_id AND ea.job_id=q.id
                                           AND ea.company_id=q.company_id AND ea.meli_account_id=q.meli_account_id
                                           AND ea.lease_generation=e.lease_generation
                                           AND ea.dispatch_state='RESPONSE_KNOWN' AND e.dispatch_state='RESPONSE_KNOWN'
                                           AND BINARY ea.transport_method=BINARY e.method
                                           AND BINARY ea.endpoint_key=BINARY e.endpoint_key AND ea.endpoint_key<>''
                                           AND ea.http_status=e.http_status AND ea.physical_http_calls=1
                                           AND ea.physical_started_at IS NOT NULL AND e.physical_started_at IS NOT NULL
                                           AND ea.response_known_at IS NOT NULL AND e.response_known_at IS NOT NULL
                                           AND ea.physical_started_at=e.physical_started_at
                                           AND ea.response_known_at=e.response_known_at
                                           AND ea.response_known_at>=ea.physical_started_at
                                           AND (SELECT COUNT(*) FROM queue_v4_clean_transport_events duplicate_event
                                                WHERE duplicate_event.attempt_id=ea.id)=1))))
               )
             ORDER BY q.available_at,q.id LIMIT 200"
        );
        $statement->execute($scopeParams);
        return $statement->rowCount();
    }

    /**
     * Re-open only due notification pointers whose durable source and prior
     * attempt still prove that another natural cycle is safe. Initial
     * debounce pointers have no attempt yet; subsequent continuations must
     * have one closed waiting attempt for the current generation.
     *
     * @param list<int>|null $authorizedAccountIds
     */
    public function releaseDueNotificationWaiting(?array $authorizedAccountIds = null, ?int $accountId = null): int
    {
        [$scopeSql, $scopeParams] = $this->accountScopeSql('q', $authorizedAccountIds, $accountId);
        $statement = $this->pdo->prepare(
            "UPDATE queue_v4_clean_jobs q
             INNER JOIN meli_notification_work_items w
                     ON w.id=CAST(q.resource_id AS UNSIGNED)
                    AND w.meli_account_id=q.meli_account_id
             INNER JOIN meli_accounts ma
                     ON ma.id=w.meli_account_id
                    AND ma.company_id=q.company_id
             SET q.state='ready',q.last_error_class=NULL
             WHERE q.job_type='domain_exact'
               AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='notification_work_item'
               AND q.resource_id REGEXP '^[0-9]+$'
               AND q.state='waiting'
               AND q.available_at<=UTC_TIMESTAMP(3)
               AND q.attempt_count<q.max_attempts
               AND {$scopeSql}
               AND w.status IN ('pending','retry')
               AND w.next_run_at<=UTC_TIMESTAMP(3)
               AND ((q.lease_owner IS NULL AND q.lease_expires_at IS NULL)
                    OR (q.lease_owner IS NOT NULL AND q.lease_owner<>''
                        AND q.lease_expires_at IS NOT NULL AND q.lease_expires_at<=UTC_TIMESTAMP(3)))
               AND ((w.locked_by IS NULL AND w.lock_expires_at IS NULL)
                    OR (w.locked_by IS NOT NULL AND w.locked_by<>''
                        AND w.lock_expires_at IS NOT NULL AND w.lock_expires_at<=UTC_TIMESTAMP(3)))
               AND NOT (" . $this->unresolvedPhysicalPredicate('q') . ")
               AND NOT EXISTS (
                   SELECT 1 FROM manual_campaign_reservations mcr
                    WHERE mcr.queue_key='notification_fallback'
                      AND mcr.source_id=CAST(w.id AS CHAR)
                      AND mcr.company_id=q.company_id
                      AND mcr.meli_account_id=q.meli_account_id
                      AND mcr.status='active'
                      AND mcr.expires_at>UTC_TIMESTAMP(3)
               )
               AND (
                   (q.lease_generation=0 AND q.attempt_count=0
                    AND NOT EXISTS (SELECT 1 FROM queue_v4_clean_attempts initial_attempt
                                     WHERE initial_attempt.job_id=q.id))
                   OR EXISTS (
                       SELECT 1 FROM queue_v4_clean_attempts a
                        WHERE a.job_id=q.id
                          AND a.company_id=q.company_id
                          AND a.meli_account_id=q.meli_account_id
                          AND a.lease_generation=q.lease_generation
                          AND a.outcome='waiting'
                          AND a.finished_at IS NOT NULL
                          AND a.source_closed_at IS NOT NULL
                          AND a.error_class=q.last_error_class
                          AND NOT EXISTS (
                              SELECT 1 FROM queue_v4_clean_attempts newer
                               WHERE newer.job_id=q.id
                                 AND newer.company_id=q.company_id
                                 AND newer.meli_account_id=q.meli_account_id
                                 AND newer.lease_generation>=a.lease_generation
                                 AND newer.id<>a.id
                          )
                   )
               )
             ORDER BY q.available_at,q.id LIMIT 200"
        );
        $statement->execute($scopeParams);
        return $statement->rowCount();
    }

    /** @param list<int>|null $authorizedAccountIds */
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
            $this->finishInTransaction(
                $job,
                $runId,
                $jobState,
                $attemptOutcome,
                $errorClass,
                $delaySeconds === null,
                null,
                false,
                $delaySeconds,
            );
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $job */
    private function finishInTransaction(
        array $job,
        int $runId,
        string $jobState,
        string $attemptOutcome,
        ?string $errorClass,
        bool $preserveAvailableAt,
        ?string $availableAt,
        bool $undoClaimAttempt,
        ?int $delaySeconds,
    ): void {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('queue_v4_clean_finish_transaction_required');
        }
        $companyId = (int) ($job['company_id'] ?? 0);
        $accountId = (int) ($job['meli_account_id'] ?? 0);
        $availableExpression = $preserveAvailableAt
            ? 'available_at'
            : ($delaySeconds === null
                ? '?'
                : 'DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ' . max(0, $delaySeconds) . ' SECOND)');
        $statement = $this->pdo->prepare(
            "UPDATE queue_v4_clean_jobs
             SET state=?,available_at={$availableExpression},
                 attempt_count=IF(?=1,GREATEST(attempt_count-1,0),attempt_count),
                 lease_owner=NULL,lease_expires_at=NULL,last_error_class=?,
                 completed_at=IF(?='completed',UTC_TIMESTAMP(3),NULL)
             WHERE id=? AND company_id=? AND meli_account_id=? AND state='running'
               AND lease_owner=? AND lease_generation=?"
        );
        $params = [
            $jobState,
        ];
        if (!$preserveAvailableAt && $delaySeconds === null) {
            $params[] = $availableAt;
        }
        array_push(
            $params,
            $undoClaimAttempt ? 1 : 0,
            $errorClass,
            $jobState,
            (int) $job['id'],
            $companyId,
            $accountId,
            (string) $job['lease_owner'],
            (int) $job['lease_generation'],
        );
        $statement->execute($params);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('queue_v4_clean_finish_cas_lost');
        }
        $attempt = $this->pdo->prepare(
            'UPDATE queue_v4_clean_attempts SET outcome=?,error_class=?,finished_at=UTC_TIMESTAMP(3),source_closed_at=UTC_TIMESTAMP(3)
             WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=?
               AND lease_owner=? AND lease_generation=? AND outcome=\'running\''
        );
        $attempt->execute([
            $attemptOutcome,
            $errorClass,
            (int) $job['attempt_id'],
            (int) $job['id'],
            $companyId,
            $accountId,
            (string) $job['lease_owner'],
            (int) $job['lease_generation'],
        ]);
        if ($attempt->rowCount() !== 1) {
            throw new RuntimeException('queue_v4_clean_attempt_cas_lost');
        }
        $runColumn = $jobState === 'completed' ? 'jobs_completed' : 'jobs_deferred';
        $run = $this->pdo->prepare(
            "UPDATE queue_v4_clean_runs SET {$runColumn}={$runColumn}+1 WHERE id=? AND status='running'"
        );
        $run->execute([$runId]);
        if ($run->rowCount() !== 1) {
            throw new RuntimeException('queue_v4_clean_finish_run_cas_lost');
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

        $continuation = array_intersect_key($row, array_flip([
            'manual_continuation_kind', 'queue_lease_generation', 'financial_source_lease_generation', 'financial_input_version',
        ]));
        return $continuation + [
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
        $continuation = array_intersect_key($row, array_flip([
            'manual_continuation_kind', 'queue_lease_generation', 'financial_source_lease_generation', 'financial_input_version',
        ]));
        return hash('sha256', json_encode($continuation + [
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
                || (string) ($row['queue_idempotency_key'] ?? '') === ''
                || !$this->validManualContinuationFields($row)) {
                continue;
            }
            $fence = '';
            $fenceParams = [];
            if (isset($row['manual_continuation_kind'])) {
                $identity = array_replace($row, ['idempotency_key' => $row['queue_idempotency_key']]);
                if (!hash_equals((string) $row['selection_version'], $this->queueSelectionVersion($identity))) {
                    continue;
                }
                $fence = " AND {$alias}.lease_generation=? AND {$alias}.attempt_count<{$alias}.max_attempts
                    AND (({$alias}.lease_owner IS NULL AND {$alias}.lease_expires_at IS NULL)
                         OR ({$alias}.lease_owner IS NOT NULL AND {$alias}.lease_expires_at IS NOT NULL AND {$alias}.lease_expires_at<=UTC_TIMESTAMP(3)))
                    AND EXISTS (SELECT 1 FROM sale_financial_reconciliation_jobs ms
                        WHERE ms.id={$alias}.resource_id AND ms.company_id={$alias}.company_id
                          AND ms.meli_account_id={$alias}.meli_account_id AND ms.lease_generation=? AND ms.input_version=?)";
                $fenceParams = [$row['queue_lease_generation'], $row['financial_source_lease_generation'], $row['financial_input_version']];
            }
            $clauses[] = "({$alias}.id=? AND {$alias}.company_id=? AND {$alias}.meli_account_id=?
                AND {$alias}.job_type=? AND COALESCE({$alias}.resource_id,'')=?
                AND {$alias}.idempotency_key=? AND SHA2(CAST({$alias}.payload_json AS CHAR),256)=?{$fence})";
            array_push($params,
                (int) $row['queue_job_id'],
                (int) ($row['company_id'] ?? 0),
                (int) ($row['meli_account_id'] ?? 0),
                (string) ($row['job_type'] ?? ''),
                (string) ($row['resource_id'] ?? ''),
                (string) $row['queue_idempotency_key'],
                (string) $row['queue_payload_hash']
            );
            array_push($params, ...$fenceParams);
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
                       OR (s.lock_owner IS NOT NULL AND (s.lease_expires_at IS NULL OR s.lease_expires_at>UTC_TIMESTAMP(3)))
                       OR (" . $this->financialSourceManualReservationPredicate('s') . ")
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

    private function readyFinancialReconciliationPointerSourcePredicate(string $queueAlias): string
    {
        $this->assertSqlAlias($queueAlias);
        $sourceIdSql = $this->financialSourceIdExpression($queueAlias);

        return "EXISTS (
            SELECT 1
              FROM sale_financial_reconciliation_jobs s
             WHERE s.id={$sourceIdSql}
               AND s.company_id={$queueAlias}.company_id
               AND s.meli_account_id={$queueAlias}.meli_account_id
               AND s.status IN ('pending','retry','awaiting_remote')
               AND s.next_run_at<=UTC_TIMESTAMP(3)
               AND (s.lock_owner IS NULL OR s.lease_expires_at IS NULL OR s.lease_expires_at<=UTC_TIMESTAMP(3))
               AND NOT (" . $this->financialSourceManualReservationPredicate('s') . ")
        )";
    }

    private function financialSourceManualReservationPredicate(string $sourceAlias): string
    {
        $this->assertSqlAlias($sourceAlias);
        if (!$this->hasTable('manual_campaign_reservations') || !$this->hasTable('manual_campaigns')) {
            return '0=1';
        }

        return "EXISTS (
            SELECT 1
              FROM manual_campaign_reservations mcr
              JOIN manual_campaigns mc ON mc.id=mcr.manual_campaign_id
             WHERE mcr.queue_key='sale_financial_reconciliation'
               AND mcr.source_id=CAST({$sourceAlias}.id AS CHAR)
               AND mcr.company_id={$sourceAlias}.company_id
               AND mcr.meli_account_id={$sourceAlias}.meli_account_id
               AND mcr.status='active'
               AND mcr.expires_at>UTC_TIMESTAMP(3)
               AND mc.status IN ('active','pausing','paused')
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
