<?php

declare(strict_types=1);

namespace App\Work\Contracts;

use App\Work\DrainAuthorityToken;

interface DrainAuthorityContract
{
    public function acquire(string $drainerId, string $ownerToken, int $leaseSeconds): ?DrainAuthorityToken;

    public function heartbeat(DrainAuthorityToken $token): bool;

    public function release(DrainAuthorityToken $token): bool;

    public function activeDrainer(): ?string;
}
