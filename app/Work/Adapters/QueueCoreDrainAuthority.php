<?php

declare(strict_types=1);

namespace App\Work\Adapters;

use App\QueueCore\QueueExecutionLease;
use App\QueueCore\QueueExecutionLeaseService;
use App\Work\Contracts\DrainAuthorityContract;
use App\Work\DrainAuthorityToken;
use PDO;

final class QueueCoreDrainAuthority implements DrainAuthorityContract
{
    private QueueExecutionLeaseService $leases;

    public function __construct(PDO $pdo, ?QueueExecutionLeaseService $leases = null)
    {
        $this->leases = $leases ?? new QueueExecutionLeaseService($pdo);
    }

    public function acquire(string $drainerId, string $ownerToken, int $leaseSeconds): ?DrainAuthorityToken
    {
        $lease = $this->leases->acquire($drainerId, $ownerToken, $leaseSeconds);
        if (!$lease instanceof QueueExecutionLease) {
            return null;
        }

        return new DrainAuthorityToken(
            $lease->launcher,
            $lease->ownerToken,
            $lease->generation,
            $lease->leaseSeconds,
            'queue_core_execution_leases',
        );
    }

    public function heartbeat(DrainAuthorityToken $token): bool
    {
        return $this->leases->heartbeat($this->toLease($token));
    }

    public function release(DrainAuthorityToken $token): bool
    {
        return $this->leases->release($this->toLease($token));
    }

    public function activeDrainer(): ?string
    {
        return $this->leases->activeLauncher();
    }

    private function toLease(DrainAuthorityToken $token): QueueExecutionLease
    {
        return new QueueExecutionLease(
            $token->drainerId,
            $token->ownerToken,
            $token->generation,
            $token->leaseSeconds,
        );
    }
}
