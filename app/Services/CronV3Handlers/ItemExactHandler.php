<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\MeliItemSyncService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class ItemExactHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,string):mixed */
    private readonly Closure $sync;

    /** @param null|callable(WorkEnvelope,string):mixed $sync */
    public function __construct(?callable $sync = null)
    {
        $this->sync = $sync !== null
            ? Closure::fromCallable($sync)
            : static function (WorkEnvelope $work, string $externalId): int {
                $service = new MeliItemSyncService($work->meliAccountId);
                return $service->persistApprovedItem($service->fetchRemoteItem($externalId));
            };
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $externalId = strtoupper(trim((string) ($work->payload['external_item_id'] ?? '')));
        if ($externalId === '' || preg_match('/^[A-Z]{2,4}[0-9]+$/', $externalId) !== 1) {
            throw new InvalidArgumentException('item_exact requiere external_item_id válido.');
        }
        $localId = $context->logicalRemoteCall(fn (): mixed => ($this->sync)($work, $externalId));

        return WorkResult::completed([
            'external_item_id' => $externalId,
            'local_item_id' => is_numeric($localId) ? (int) $localId : null,
        ]);
    }
}
