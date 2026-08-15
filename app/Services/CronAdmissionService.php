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

        $source = $this->pdo->prepare(
            'SELECT 1 FROM ' . self::SOURCES[$capability] . '
             WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1 FOR UPDATE'
        );
        $source->execute([$sourceId, $companyId, $accountId]);
        if ($source->fetchColumn() === false) {
            return $this->receipt(false, null, false, 'INVALID_SOURCE');
        }
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
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,max_attempts)
             VALUES (?,?,?,?,?,?,3)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $insert->execute([$companyId, $accountId, 'domain_exact', (string) $sourceId, $storedKey, $json]);
        $inserted = $insert->rowCount() === 1;

        $job = $this->pdo->prepare(
            'SELECT id,state,resource_id FROM queue_v4_clean_jobs
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
