<?php
declare(strict_types=1);
namespace App\QueueCore;
use InvalidArgumentException;
final readonly class QueueJob
{
    /** @param array<string,mixed> $payload @param array<string,mixed> $provenance */
    public function __construct(
        public int $companyId, public int $meliAccountId, public string $workType,
        public string $resourceType, public ?string $resourceId, public string $lane,
        public int $priority, public string $idempotencyKey, public string $inputVersion,
        public string $source, public ?string $sourceRef, public array $payload = [],
        public array $provenance = [], public int $maxAttempts = 5, public ?string $availableAt = null,
    ) {
        if ($companyId < 1 || $meliAccountId < 1) throw new InvalidArgumentException('Queue Core requires company and account scope.');
        if (!preg_match('/^[a-z][a-z0-9_]{1,79}$/', $workType)) throw new InvalidArgumentException('Queue Core work_type is invalid.');
        if (!in_array($lane, QueueScheduler::LANES, true)) throw new InvalidArgumentException('Queue Core lane is invalid.');
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191 || $inputVersion === '') throw new InvalidArgumentException('Queue Core idempotency identity is invalid.');
        if ($maxAttempts < 1 || $maxAttempts > 100) throw new InvalidArgumentException('Queue Core max_attempts is invalid.');
    }
}
