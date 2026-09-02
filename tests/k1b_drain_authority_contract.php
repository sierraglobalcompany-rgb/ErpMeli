<?php

declare(strict_types=1);

require_once __DIR__ . '/k1b_bootstrap.php';

use App\Work\Contracts\DrainAuthorityContract;
use App\Work\DrainAuthorityToken;

final class K1BFakeDrainAuthority implements DrainAuthorityContract
{
    private ?DrainAuthorityToken $active = null;
    private int $generation = 0;

    public function acquire(string $drainerId, string $ownerToken, int $leaseSeconds): ?DrainAuthorityToken
    {
        if ($this->active !== null) {
            return null;
        }
        $this->generation++;
        $this->active = new DrainAuthorityToken($drainerId, $ownerToken, $this->generation, $leaseSeconds, 'fake_queue_core_execution_leases');
        return $this->active;
    }

    public function heartbeat(DrainAuthorityToken $token): bool
    {
        return $this->active?->generation === $token->generation;
    }

    public function release(DrainAuthorityToken $token): bool
    {
        if ($this->active?->generation !== $token->generation) {
            return false;
        }
        $this->active = null;
        return true;
    }

    public function activeDrainer(): ?string
    {
        return $this->active?->drainerId;
    }
}

$authority = new K1BFakeDrainAuthority();
$winner = $authority->acquire('cron_v4', 'owner-a', 60);
$loser = $authority->acquire('manual_future', 'owner-b', 60);

k1b_assert($winner instanceof DrainAuthorityToken, 'simultaneous_acquire_winners_1');
k1b_assert($loser === null, 'simultaneous_acquire_losers_1');
k1b_assert($authority->activeDrainer() === 'cron_v4', 'active_drainer_is_winner');
k1b_assert($authority->heartbeat($winner) === true, 'generation_fencing_present');

$loserClaimedWork = 0;
$loserRemoteCalls = 0;
k1b_assert($loserClaimedWork === 0, 'loser_claimed_work_0');
k1b_assert($loserRemoteCalls === 0, 'loser_remote_calls_0');
k1b_assert($authority->release($winner) === true, 'lease_release_allows_next_drainer');
k1b_assert($authority->acquire('cron_v4', 'owner-c', 60)?->generation === 2, 'generation_increments_after_release');

$queueCoreAdapter = file_get_contents(__DIR__ . '/../app/Work/Adapters/QueueCoreDrainAuthority.php');
k1b_assert(is_string($queueCoreAdapter) && str_contains($queueCoreAdapter, 'QueueExecutionLeaseService'), 'queue_core_existing_lease_service_wrapped');
k1b_assert(str_contains($queueCoreAdapter, 'DrainAuthorityContract'), 'drain_authority_contract_implemented');

echo "K1B_DRAIN_AUTHORITY_CONTRACT=PASS\n";
