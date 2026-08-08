<?php

declare(strict_types=1);

namespace App\Services;

interface ManualCampaignAdapter
{
    public function key(): string;

    public function queueKey(): string;

    public function supportsExact(): bool;

    public function inspect(string $sourceId, int $accountId): CampaignItemState;

    public function processExact(
        string $sourceId,
        int $accountId,
        CampaignExecutionContext $context
    ): CampaignItemResult;
}
