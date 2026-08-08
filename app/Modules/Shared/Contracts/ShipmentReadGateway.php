<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

interface ShipmentReadGateway
{
    /** @return list<array<string,mixed>> */
    public function recentShipments(int $accountId, int $limit = 50): array;
}
