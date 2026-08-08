<?php

declare(strict_types=1);

namespace App\Services;

final readonly class CampaignItemState
{
    public function __construct(
        public bool $exists,
        public bool $terminal,
        public bool $eligible,
        public bool $usesApi,
        public string $operationKey,
        public string $label,
        public string $message,
        public int $estimatedCalls = 0,
        public int $estimatedItems = 1,
        public string $sourceState = 'ready',
        public ?string $nextEligibleAt = null,
    ) {
    }
}
