<?php

declare(strict_types=1);

namespace App\Services;

use App\Work\Adapters\QueueV4CanonicalWorkStore;
use App\Work\WorkContractVersion;
use App\Work\WorkEnvelope;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/** Stable internal boundary used by business modules to request exact Cron work. */
final class CronAdmissionService
{
    private const SOURCES = [
        'financial_recalc' => 'order_financial_recalc_jobs',
        'financial_reconciliation' => 'sale_financial_reconciliation_jobs',
        'notification_work_item' => 'meli_notification_work_items',
        'order_enrichment_pack' => 'order_resource_enrichment_jobs',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The caller owns the transaction. This method never begins, commits, or rolls it back.
     *
     * @param array<string,mixed> $payload
     * @return array{accepted:bool,job_id:?int,deduplicated:bool,reason:string}
     */
    public function submit(
        string $capability,
        int $companyId,
        int $accountId,
        int $sourceId,
        string $idempotencyKey,
        array $payload = [],
        ?string $reentryReason = null,
    ): array {
        $capability = trim($capability);
        if (!isset(self::SOURCES[$capability])) {
            return $this->receipt(false, null, false, 'UNSUPPORTED_CAPABILITY');
        }
        if ($companyId < 1 || $accountId < 1) {
            return $this->receipt(false, null, false, 'INVALID_TENANT');
        }
        if ($sourceId < 1 || trim($idempotencyKey) === '') {
            return $this->receipt(false, null, false, 'INVALID_SOURCE');
        }
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('cron_admission_source_transaction_required');
        }

        $tenant = $this->pdo->prepare(
            'SELECT 1 FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1'
        );
        $tenant->execute([$accountId, $companyId]);
        if ($tenant->fetchColumn() === false) {
            return $this->receipt(false, null, false, 'INVALID_TENANT');
        }

        $sourceRow = $this->sourceRow($capability, $sourceId, $companyId, $accountId);
        if (!is_array($sourceRow)) {
            return $this->receipt(false, null, false, 'INVALID_SOURCE');
        }
        if (
            in_array($capability, ['notification_work_item', 'order_enrichment_pack'], true)
            && !in_array(strtolower((string) ($sourceRow['status'] ?? '')), ['pending', 'retry'], true)
        ) {
            return $this->receipt(false, null, false, 'SOURCE_NOT_ELIGIBLE');
        }
        $sourceFutureAt = $this->sourceFutureAvailabilityAt($capability, $sourceRow);
        $storedKey = 'domain:' . $capability . ':' . trim($idempotencyKey);
        if (strlen($storedKey) > 190) {
            throw new RuntimeException('cron_admission_idempotency_key_too_long');
        }
        if ($capability === 'notification_work_item') {
            $uncertain = $this->unresolvedNotificationTransportPointer(
                $companyId,
                $accountId,
                $sourceId,
            );
            if ($uncertain !== null) {
                // A later webhook event is already durable in the source
                // transaction and may be acknowledged, but it cannot create or
                // reopen executable work while any physical result for this
                // exact logical source remains unknown. Explicit recovery
                // actions must fail so their source transition rolls back.
                return $this->receipt(
                    $reentryReason === null,
                    (int) $uncertain['id'],
                    true,
                    'UNRESOLVED_TRANSPORT_HELD',
                );
            }
            $active = $this->activeNotificationPointer($companyId, $accountId, $sourceId);
            if ($active !== null) {
                $jobId = (int) $active['id'];
                if ($sourceFutureAt !== null) {
                    $this->alignPointerAvailability($capability, $jobId, $companyId, $accountId, $sourceId, $sourceFutureAt);
                }
                return $this->receipt(true, $jobId, true, 'ALREADY_QUEUED');
            }
            $resumed = $this->resumeProtectedNotificationPointer(
                $companyId,
                $accountId,
                $sourceId,
                $storedKey,
                $sourceFutureAt,
                $reentryReason,
            );
            if ($resumed !== null) {
                return $this->receipt(true, (int) $resumed['id'], true, 'REQUEUED_FROM_PAUSE');
            }
        }
        $document = ['capability' => $capability, 'source_id' => $sourceId];
        if ($payload !== []) {
            $document['payload'] = $payload;
        }
        $json = json_encode(
            $document,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (strlen($json) > 4096) {
            throw new RuntimeException('cron_admission_payload_too_large');
        }

        $receipt = (new QueueV4CanonicalWorkStore($this->pdo))->admit(
            new WorkEnvelope(
                WorkContractVersion::CURRENT,
                $companyId,
                $accountId,
                $capability,
                (string) $sourceId,
                trim($idempotencyKey),
                $payload,
                new DateTimeImmutable(($sourceFutureAt ?? gmdate('Y-m-d H:i:s')) . ' UTC'),
                null,
                3,
            )
        );

        if (!$receipt->accepted && $receipt->workId === null) {
            return $receipt->toLegacyCronAdmissionReceipt();
        }
        $jobId = (int) $receipt->workId;
        $job = $this->pdo->prepare(
            'SELECT state,resource_id,available_at FROM queue_v4_clean_jobs
             WHERE id=? AND company_id=? AND meli_account_id=? AND job_type="domain_exact"
             LIMIT 1'
        );
        $job->execute([$jobId, $companyId, $accountId]);
        $row = $job->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('cron_admission_receipt_missing');
        }
        if (!hash_equals((string) $sourceId, (string) $row['resource_id'])) {
            throw new RuntimeException('cron_admission_idempotency_conflict');
        }
        if ($receipt->deduplicated && $sourceFutureAt !== null) {
            $this->alignPointerAvailability($capability, $jobId, $companyId, $accountId, $sourceId, $sourceFutureAt);
            $job->execute([$jobId, $companyId, $accountId]);
            $row = $job->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('cron_admission_receipt_missing');
            }
        }
        if (!$receipt->deduplicated) {
            return $this->receipt(true, $jobId, false, 'ACCEPTED');
        }

        return match ((string) $row['state']) {
            'ready', 'running', 'waiting' => $this->receipt(true, $jobId, true, 'ALREADY_QUEUED'),
            'completed' => $this->receipt(false, $jobId, true, 'ALREADY_COMPLETED'),
            'review' => $this->receipt(false, $jobId, true, 'REVIEW_HELD'),
            default => $this->receipt(false, $jobId, true, 'DEAD_HELD'),
        };
    }

    /**
     * Canonical admission for existing direct exact-order work. It centralizes
     * idempotent Queue V4 insertion so pack discovery can request missing child
     * orders without calling the worker or issuing inline child HTTP.
     *
     * @param array<string,mixed> $payload
     * @return array{accepted:bool,job_id:?int,deduplicated:bool,reason:string}
     */
    public function submitOrderExact(
        int $companyId,
        int $accountId,
        string $externalOrderId,
        string $idempotencyKey,
        array $payload = [],
    ): array {
        $externalOrderId = trim($externalOrderId);
        $idempotencyKey = trim($idempotencyKey);
        if ($companyId < 1 || $accountId < 1) {
            return $this->receipt(false, null, false, 'INVALID_TENANT');
        }
        if ($externalOrderId === '' || !ctype_digit($externalOrderId) || $idempotencyKey === '') {
            return $this->receipt(false, null, false, 'INVALID_SOURCE');
        }
        if (strlen($idempotencyKey) > 190) {
            throw new RuntimeException('cron_admission_order_exact_idempotency_key_too_long');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $tenant = $this->pdo->prepare(
                'SELECT 1 FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1'
            );
            $tenant->execute([$accountId, $companyId]);
            if ($tenant->fetchColumn() === false) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return $this->receipt(false, null, false, 'INVALID_TENANT');
            }

            $document = ['order_id' => $externalOrderId] + $payload;
            $json = json_encode(
                $document,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            if (strlen($json) > 4096) {
                throw new RuntimeException('cron_admission_order_exact_payload_too_large');
            }

            $receipt = (new QueueV4CanonicalWorkStore($this->pdo))->admit(
                new WorkEnvelope(
                    WorkContractVersion::CURRENT,
                    $companyId,
                    $accountId,
                    'order_exact',
                    $externalOrderId,
                    $idempotencyKey,
                    $document,
                    null,
                    null,
                    3,
                )
            );

            $job = $this->pdo->prepare(
                'SELECT id,state,resource_id FROM queue_v4_clean_jobs
                 WHERE id=? AND company_id=? AND meli_account_id=? AND job_type="order_exact"
                 LIMIT 1'
            );
            $job->execute([(int) $receipt->workId, $companyId, $accountId]);
            $row = $job->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('cron_admission_order_exact_receipt_missing');
            }
            if (!hash_equals($externalOrderId, (string) $row['resource_id'])) {
                throw new RuntimeException('cron_admission_order_exact_idempotency_conflict');
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            $jobId = (int) $row['id'];

            return !$receipt->deduplicated
                ? $this->receipt(true, $jobId, false, 'ACCEPTED')
                : match ((string) $row['state']) {
                    'ready', 'running', 'waiting' => $this->receipt(true, $jobId, true, 'ALREADY_QUEUED'),
                    'completed' => $this->receipt(false, $jobId, true, 'ALREADY_COMPLETED'),
                    'review' => $this->receipt(true, $jobId, true, 'REQUEUED_FROM_REVIEW'),
                    default => $this->receipt(false, $jobId, true, 'DEAD_HELD'),
                };
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function sourceRow(string $capability, int $sourceId, int $companyId, int $accountId): ?array
    {
        if ($capability === 'notification_work_item') {
            $source = $this->pdo->prepare(
                'SELECT w.id,w.status,w.next_run_at,a.company_id,w.meli_account_id
                 FROM meli_notification_work_items w
                 JOIN meli_accounts a ON a.id=w.meli_account_id
                 WHERE w.id=? AND a.company_id=? AND w.meli_account_id=? LIMIT 1 FOR UPDATE'
            );
            $source->execute([$sourceId, $companyId, $accountId]);
            $row = $source->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }
        if ($capability === 'order_enrichment_pack') {
            $source = $this->pdo->prepare(
                'SELECT j.id,j.status,j.next_run_at,a.company_id,j.meli_account_id,j.resource_type,j.external_resource_id,
                        j.failure_class,j.lock_token,j.locked_at,j.lease_generation,j.attempts
                 FROM order_resource_enrichment_jobs j
                 JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.id=? AND a.company_id=? AND j.meli_account_id=? AND j.resource_type="pack"
                 LIMIT 1 FOR UPDATE'
            );
            $source->execute([$sourceId, $companyId, $accountId]);
            $row = $source->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        $source = $this->pdo->prepare(
            'SELECT id,status,' . ($capability === 'financial_reconciliation' ? 'next_run_at' : 'NULL AS next_run_at') . '
             FROM ' . self::SOURCES[$capability] . '
             WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1 FOR UPDATE'
        );
        $source->execute([$sourceId, $companyId, $accountId]);
        $row = $source->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * For financial reconciliation, the source domain already owns the next
     * legitimate retry clock. Queue V4 must not make that pointer claimable
     * before this timestamp. Other exact domains keep the default immediate
     * admission semantics.
     *
     * @param array<string,mixed> $sourceRow
     */
    private function sourceFutureAvailabilityAt(string $capability, array $sourceRow): ?string
    {
        if (!in_array($capability, ['financial_reconciliation', 'notification_work_item', 'order_enrichment_pack'], true)) {
            return null;
        }
        $raw = trim((string) ($sourceRow['next_run_at'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $timestamp = strtotime($raw . ' UTC');
        if ($timestamp === false || $timestamp <= time()) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /** @return array{id:int}|null */
    private function unresolvedNotificationTransportPointer(
        int $companyId,
        int $accountId,
        int $sourceId,
    ): ?array {
        $statement = $this->pdo->prepare(
            'SELECT j.id
             FROM queue_v4_clean_jobs j
             INNER JOIN queue_v4_clean_transport_events e
               ON e.company_id=j.company_id
              AND e.meli_account_id=j.meli_account_id
              AND e.source_kind="queue"
              AND e.work_id=j.id
              AND e.dispatch_state="PHYSICAL_STARTED"
              AND e.response_known_at IS NULL
             WHERE j.company_id=? AND j.meli_account_id=?
               AND j.job_type="domain_exact" AND j.resource_id=?
               AND JSON_UNQUOTE(JSON_EXTRACT(j.payload_json,"$.capability"))="notification_work_item"
             ORDER BY j.id DESC,e.id DESC
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$companyId, $accountId, (string) $sourceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['id' => (int) $row['id']] : null;
    }

    /** @return array{id:int,state:string}|null */
    private function activeNotificationPointer(int $companyId, int $accountId, int $sourceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,state
             FROM queue_v4_clean_jobs
             WHERE company_id=? AND meli_account_id=?
               AND job_type="domain_exact" AND resource_id=?
               AND state IN ("ready","running","waiting")
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))="notification_work_item"
             ORDER BY id DESC LIMIT 2 FOR UPDATE'
        );
        $statement->execute([$companyId, $accountId, (string) $sourceId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException('notification_canonical_multiple_active_pointers');
        }

        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    /** @return array{id:int}|null */
    private function resumeProtectedNotificationPointer(
        int $companyId,
        int $accountId,
        int $sourceId,
        string $storedKey,
        ?string $sourceFutureAt,
        ?string $reentryReason,
    ): ?array {
        $policy = match ($reentryReason) {
            'resume_paused' => [
                'states' => ['review'],
                'errors' => ['domain_source_paused'],
                'allow_null_error' => false,
            ],
            'retry_failed', 'worker_recovery' => [
                'states' => ['review'],
                'errors' => [
                    'domain_source_error',
                    'domain_source_quarantined',
                    'notification_work_item_error',
                    'notification_work_item_action_required',
                ],
                'allow_null_error' => false,
            ],
            'collation_recovery' => [
                'states' => ['review', 'completed'],
                'errors' => [
                    'domain_source_error',
                    'notification_work_item_error',
                ],
                'allow_null_error' => true,
            ],
            default => null,
        };
        if ($policy === null) {
            return null;
        }
        $stateSql = implode(',', array_fill(0, count($policy['states']), '?'));
        $errorSql = implode(',', array_fill(0, count($policy['errors']), '?'));
        $errorPredicate = 'last_error_class IN (' . $errorSql . ')';
        if ($policy['allow_null_error']) {
            $errorPredicate = '(' . $errorPredicate . ' OR last_error_class IS NULL)';
        }
        $select = $this->pdo->prepare(
            'SELECT id
             FROM queue_v4_clean_jobs
             WHERE company_id=? AND meli_account_id=?
               AND job_type="domain_exact" AND resource_id=?
               AND idempotency_key=?
               AND state IN (' . $stateSql . ')
               AND ' . $errorPredicate . '
               AND attempt_count<max_attempts
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))="notification_work_item"
               AND ((lease_owner IS NULL AND lease_expires_at IS NULL)
                    OR (lease_owner IS NOT NULL AND lease_owner<>""
                        AND lease_expires_at IS NOT NULL AND lease_expires_at<=UTC_TIMESTAMP(3)))
               AND NOT EXISTS (
                   SELECT 1 FROM queue_v4_clean_transport_events e
                    WHERE e.company_id=queue_v4_clean_jobs.company_id
                      AND e.meli_account_id=queue_v4_clean_jobs.meli_account_id
                      AND e.source_kind="queue" AND e.work_id=queue_v4_clean_jobs.id
                      AND e.dispatch_state="PHYSICAL_STARTED" AND e.response_known_at IS NULL
               )
             ORDER BY id DESC LIMIT 2 FOR UPDATE'
        );
        $select->execute(array_merge(
            [$companyId, $accountId, (string) $sourceId, $storedKey],
            $policy['states'],
            $policy['errors'],
        ));
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new RuntimeException('notification_canonical_multiple_paused_pointers');
        }
        if (!isset($rows[0]) || !is_array($rows[0])) {
            return null;
        }

        $jobId = (int) $rows[0]['id'];
        $state = $sourceFutureAt === null ? 'ready' : 'waiting';
        $availableAt = $sourceFutureAt ?? gmdate('Y-m-d H:i:s');
        $update = $this->pdo->prepare(
            'UPDATE queue_v4_clean_jobs
             SET state=?,available_at=?,last_error_class=NULL,completed_at=NULL
             WHERE id=? AND company_id=? AND meli_account_id=?
               AND state IN (' . $stateSql . ')
               AND ' . $errorPredicate
        );
        $update->execute(array_merge(
            [$state, $availableAt, $jobId, $companyId, $accountId],
            $policy['states'],
            $policy['errors'],
        ));
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('notification_paused_pointer_reentry_cas_lost');
        }

        return ['id' => $jobId];
    }

    private function alignPointerAvailability(
        string $capability,
        int $jobId,
        int $companyId,
        int $accountId,
        int $sourceId,
        string $sourceFutureAt,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE queue_v4_clean_jobs
             SET state="waiting",
                 available_at=IF(available_at < ?, ?, available_at),
                 last_error_class=?
             WHERE id=?
               AND company_id=?
               AND meli_account_id=?
               AND job_type="domain_exact"
               AND resource_id=?
               AND state IN ("ready","waiting")
               AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))=?
               AND (state="ready" OR available_at < ?)'
        );
        $statement->execute([
            $sourceFutureAt,
            $sourceFutureAt,
            'domain_source_waiting:' . $capability,
            $jobId,
            $companyId,
            $accountId,
            (string) $sourceId,
            $capability,
            $sourceFutureAt,
        ]);
    }

    /** @return array{accepted:bool,job_id:?int,deduplicated:bool,reason:string} */
    private function receipt(bool $accepted, ?int $jobId, bool $deduplicated, string $reason): array
    {
        return [
            'accepted' => $accepted,
            'job_id' => $jobId,
            'deduplicated' => $deduplicated,
            'reason' => $reason,
        ];
    }
}
