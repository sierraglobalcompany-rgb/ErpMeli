<?php

declare(strict_types=1);

namespace App\Services;

final readonly class CampaignItemResult
{
    public function __construct(
        public string $status,
        public string $message,
        public int $processed = 0,
        public int $primaryCalls = 0,
        public int $derivedCalls = 0,
        public int $avoidedCalls = 0,
        public ?string $nextEligibleAt = null,
        public ?string $diagnosticId = null,
        public ?string $reason = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
