<?php
declare(strict_types=1);
namespace App\QueueCore;
final readonly class QueueClaim
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public int $id, public int $companyId, public int $meliAccountId,
        public string $workType, public string $resourceType, public ?string $resourceId,
        public string $lane, public int $priority, public string $state,
        public int $attemptCount, public int $maxAttempts, public string $leaseOwner,
        public int $leaseGeneration, public string $dispatchState, public array $payload,
        public string $source, public ?string $sourceRef,
    ) {}
}
