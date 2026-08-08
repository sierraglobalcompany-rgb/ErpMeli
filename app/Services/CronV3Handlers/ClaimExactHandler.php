<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\ClaimSyncService;
use App\Services\CronV3ExecutionContext;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class ClaimExactHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,string):mixed */
    private readonly Closure $sync;

    /** @param null|callable(WorkEnvelope,string):mixed $sync */
    public function __construct(?callable $sync = null)
    {
        $this->sync = $sync !== null
            ? Closure::fromCallable($sync)
            : static fn (WorkEnvelope $work, string $claimId): int =>
                (new ClaimSyncService($work->meliAccountId))->syncClaimById($claimId);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $claimId = trim((string) ($work->payload['claim_id'] ?? ''));
        if ($claimId === '' || preg_match('/^[0-9]+$/', $claimId) !== 1) {
            throw new InvalidArgumentException('El trabajo claim_exact requiere claim_id numérico.');
        }
        $localId = $context->logicalRemoteCall(fn (): mixed => ($this->sync)($work, $claimId));

        return WorkResult::completed([
            'claim_id' => $claimId,
            'local_id' => is_numeric($localId) ? (int) $localId : null,
        ]);
    }
}
