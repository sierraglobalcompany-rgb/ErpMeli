<?php

declare(strict_types=1);

namespace App\Work\Adapters;

use App\QueueV4Clean\QueueV4CleanRepository;
use App\Work\Contracts\CanonicalWorkStore;
use App\Work\WorkAdmissionReceipt;
use App\Work\WorkEnvelope;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class QueueV4CanonicalWorkStore implements CanonicalWorkStore
{
    public const ADAPTER = 'queue_v4_clean_jobs';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?QueueV4CleanRepository $repository = null,
    ) {
    }

    public function admit(WorkEnvelope $envelope): WorkAdmissionReceipt
    {
        $shape = $this->toPhysicalShape($envelope);
        if (strlen((string) $shape['idempotency_key']) > 190) {
            throw new RuntimeException('canonical_work_physical_idempotency_key_too_long');
        }
        if (strlen((string) $shape['payload_json']) > 4096) {
            throw new RuntimeException('canonical_work_physical_payload_too_large');
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,max_attempts,state,available_at)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               id=LAST_INSERT_ID(id),
               completed_at=IF(job_type IN ("fresh_orders_discovery","order_exact") AND state IN ("completed","review"),NULL,completed_at),
               available_at=IF(job_type IN ("fresh_orders_discovery","order_exact") AND state IN ("completed","review"),UTC_TIMESTAMP(3),available_at),
               attempt_count=IF(job_type IN ("fresh_orders_discovery","order_exact") AND state IN ("completed","review"),0,attempt_count),
               last_error_class=IF(job_type IN ("fresh_orders_discovery","order_exact") AND state IN ("completed","review"),NULL,last_error_class),
               state=IF(job_type IN ("fresh_orders_discovery","order_exact") AND state IN ("completed","review"),"ready",state)'
        );
        $insert->execute([
            $shape['company_id'],
            $shape['meli_account_id'],
            $shape['job_type'],
            $shape['resource_id'],
            $shape['idempotency_key'],
            $shape['payload_json'],
            $shape['max_attempts'],
            $shape['state'],
            $shape['available_at'],
        ]);
        $inserted = $insert->rowCount() === 1;

        $job = $this->pdo->prepare(
            'SELECT id,state,resource_id FROM queue_v4_clean_jobs
             WHERE company_id=? AND meli_account_id=? AND job_type=? AND idempotency_key=?
             LIMIT 1'
        );
        $job->execute([
            $shape['company_id'],
            $shape['meli_account_id'],
            $shape['job_type'],
            $shape['idempotency_key'],
        ]);
        $row = $job->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('canonical_work_admission_receipt_missing');
        }
        if ((string) ($shape['resource_id'] ?? '') !== (string) ($row['resource_id'] ?? '')) {
            throw new RuntimeException('canonical_work_idempotency_conflict');
        }

        return $this->receiptForRow($envelope->workType, (int) $row['id'], (string) $row['state'], !$inserted);
    }

    /** @return array<string,mixed> */
    public function toPhysicalShape(WorkEnvelope $envelope): array
    {
        return self::physicalShapeFor($envelope);
    }

    /** @return array<string,mixed> */
    public static function physicalShapeFor(WorkEnvelope $envelope): array
    {
        $workType = $envelope->workType;
        $payload = $envelope->payload;
        $resourceId = $envelope->resourceId;
        $idempotencyKey = $envelope->idempotencyKey;

        if ($workType === 'financial_recalc'
            || $workType === 'financial_reconciliation'
            || $workType === 'notification_work_item'
            || $workType === 'order_enrichment_pack') {
            $payload = [
                'capability' => $workType,
                'source_id' => $resourceId !== null && ctype_digit($resourceId) ? (int) $resourceId : $resourceId,
            ] + ($payload === [] ? [] : ['payload' => $payload]);
            $idempotencyKey = 'domain:' . $workType . ':' . $idempotencyKey;
            $workType = 'domain_exact';
        } elseif ($workType === 'domain_exact') {
            $capability = trim((string) ($payload['capability'] ?? ''));
            if ($capability === '') {
                throw new RuntimeException('canonical_domain_exact_capability_required');
            }
        }

        if (!in_array($workType, ['fresh_orders_discovery', 'order_exact', 'domain_exact'], true)) {
            throw new RuntimeException('canonical_work_type_no_physical_mapping');
        }
        if (($workType === 'order_exact' || $workType === 'domain_exact')
            && ($resourceId === null || trim($resourceId) === '')) {
            throw new RuntimeException('canonical_work_resource_required');
        }

        $availableAt = $envelope->availableAt ?? new DateTimeImmutable('now');
        $state = $availableAt->getTimestamp() > time() ? 'waiting' : 'ready';

        return [
            'company_id' => $envelope->companyId,
            'meli_account_id' => $envelope->meliAccountId,
            'job_type' => $workType,
            'resource_id' => $resourceId,
            'idempotency_key' => $idempotencyKey,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'max_attempts' => max(1, min(10, $envelope->maxAttempts)),
            'state' => $state,
            'available_at' => $availableAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array{ready:int,running:int,waiting:int,review:int,dead:int,completed:int,total:int} */
    public function counts(): array
    {
        return ($this->repository ?? new QueueV4CleanRepository($this->pdo))->counts();
    }

    private function receiptForRow(string $canonicalWorkType, int $workId, string $state, bool $deduplicated): WorkAdmissionReceipt
    {
        if (!$deduplicated) {
            return new WorkAdmissionReceipt(true, $workId, false, 'ACCEPTED', $canonicalWorkType, self::ADAPTER);
        }

        return match ($state) {
            'ready', 'running', 'waiting' => new WorkAdmissionReceipt(true, $workId, true, 'ALREADY_QUEUED', $canonicalWorkType, self::ADAPTER),
            'completed' => new WorkAdmissionReceipt(false, $workId, true, 'ALREADY_COMPLETED', $canonicalWorkType, self::ADAPTER),
            'review' => new WorkAdmissionReceipt(false, $workId, true, 'REVIEW_HELD', $canonicalWorkType, self::ADAPTER),
            default => new WorkAdmissionReceipt(false, $workId, true, 'DEAD_HELD', $canonicalWorkType, self::ADAPTER),
        };
    }
}
