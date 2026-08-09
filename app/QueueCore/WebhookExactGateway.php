<?php
declare(strict_types=1);

namespace App\QueueCore;

interface WebhookExactGateway
{
    public function sync(
        int $companyId,
        int $meliAccountId,
        string $resourceType,
        string $resourceId,
        int $queueJobId
    ): int;
}
