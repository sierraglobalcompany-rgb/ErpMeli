<?php

declare(strict_types=1);

namespace App\Services;

final readonly class CampaignExecutionContext
{
    public function __construct(
        public int $campaignId,
        public int $campaignItemId,
        public int $companyId,
        public string $worker,
        public int $leaseGeneration,
        public float $deadline,
        public int $requestedBlockSize,
    ) {
    }
}
