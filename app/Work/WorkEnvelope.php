<?php

declare(strict_types=1);

namespace App\Work;

use DateTimeImmutable;
use RuntimeException;

final readonly class WorkEnvelope
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public int $contractVersion,
        public int $companyId,
        public int $meliAccountId,
        public string $workType,
        public ?string $resourceId,
        public string $idempotencyKey,
        public array $payload = [],
        public ?DateTimeImmutable $availableAt = null,
        public ?int $priorityOrFifoPosition = null,
        public int $maxAttempts = 3,
    ) {
        if ($contractVersion !== WorkContractVersion::CURRENT) {
            throw new RuntimeException('canonical_work_contract_version_invalid');
        }
        if ($companyId < 1 || $meliAccountId < 1) {
            throw new RuntimeException('canonical_work_tenant_invalid');
        }
        if (trim($workType) === '' || !WorkContractVersion::supports($workType)) {
            throw new RuntimeException('canonical_work_type_unsupported');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 190) {
            throw new RuntimeException('canonical_work_idempotency_key_invalid');
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 4096) {
            throw new RuntimeException('canonical_work_payload_too_large');
        }
        if ($maxAttempts < 1 || $maxAttempts > 10) {
            throw new RuntimeException('canonical_work_max_attempts_invalid');
        }
    }

    public function payloadJson(): string
    {
        return json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function availableAtSql(): string
    {
        return ($this->availableAt ?? new DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
