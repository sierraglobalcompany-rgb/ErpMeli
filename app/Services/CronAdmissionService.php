<?php

declare(strict_types=1);

namespace App\Services;

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
        $initialState = $sourceFutureAt !== null ? 'waiting' : 'ready';
        $initialAvailableAt = $sourceFutureAt ?? gmdate('Y-m-d H:i:s');
        $storedKey = 'domain:' . $capability . ':' . trim($idempotencyKey);
        if (strlen($storedKey) > 190) {
            throw new RuntimeException('cron_admission_idempotency_key_too_long');
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

        $insert = $this->pdo->prepare(
            'INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,max_attempts,state,available_at)
             VALUES (?,?,?,?,?,?,3,?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $insert->execute([
            $companyId,
            $accountId,
            'domain_exact',
            (string) $sourceId,
            $storedKey,
            $json,
            $initialState,
            $initialAvailableAt,
        ]);
        $inserted = $insert->rowCount() === 1;

        $job = $this->pdo->prepare(
            'SELECT id,state,resource_id,available_at FROM queue_v4_clean_jobs
             WHERE company_id=? AND meli_account_id=? AND job_type="domain_exact" AND idempotency_key=?
             LIMIT 1'
        );
        $job->execute([$companyId, $accountId, $storedKey]);
        $row = $job->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('cron_admission_receipt_missing');
        }
        if (!hash_equals((string) $sourceId, (string) $row['resource_id'])) {
            throw new RuntimeException('cron_admission_idempotency_conflict');
        }
        $jobId = (int) $row['id'];
        if (!$inserted && $sourceFutureAt !== null) {
            $this->alignPointerAvailability($capability, $jobId, $companyId, $accountId, $sourceId, $sourceFutureAt);
            $job->execute([$companyId, $accountId, $storedKey]);
            $row = $job->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('cron_admission_receipt_missing');
            }
        }
        if ($inserted) {
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

            $insert = $this->pdo->prepare(
                'INSERT INTO queue_v4_clean_jobs
                 (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,max_attempts)
                 VALUES (?,?,?,?,?,?,3)
                 ON DUPLICATE KEY UPDATE
                   id=LAST_INSERT_ID(id),
                   completed_at=IF(state IN ("completed","review"),NULL,completed_at),
                   available_at=IF(state IN ("completed","review"),UTC_TIMESTAMP(3),available_at),
                   attempt_count=IF(state IN ("completed","review"),0,attempt_count),
                   last_error_class=IF(state IN ("completed","review"),NULL,last_error_class),
                   state=IF(state IN ("completed","review"),"ready",state)'
            );
            $insert->execute([
                $companyId,
                $accountId,
                'order_exact',
                $externalOrderId,
                $idempotencyKey,
                $json,
            ]);
            $inserted = $insert->rowCount() === 1;

            $job = $this->pdo->prepare(
                'SELECT id,state,resource_id FROM queue_v4_clean_jobs
                 WHERE company_id=? AND meli_account_id=? AND job_type="order_exact" AND idempotency_key=?
                 LIMIT 1'
            );
            $job->execute([$companyId, $accountId, $idempotencyKey]);
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

            return $inserted
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
                'SELECT j.id,j.status,j.next_run_at,a.company_id,j.meli_account_id,j.resource_type,j.external_resource_id
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
