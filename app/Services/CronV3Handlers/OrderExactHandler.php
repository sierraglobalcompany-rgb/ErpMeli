<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\OrderSyncService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class OrderExactHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,string):mixed */
    private readonly Closure $sync;

    /** @param null|callable(WorkEnvelope,string):mixed $sync */
    public function __construct(?callable $sync = null)
    {
        $this->sync = $sync !== null
            ? Closure::fromCallable($sync)
            : static fn (WorkEnvelope $work, string $orderId): int =>
                (new OrderSyncService($work->meliAccountId))->syncOrderById($orderId, [
                    'job_type' => 'cron_v3_order_exact',
                    'source' => 'cron_v3',
                    'bulk' => false,
                    'source_work_id' => (string) ($work->id ?? ''),
                ]);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $orderId = trim((string) ($work->payload['external_order_id'] ?? $work->payload['order_id'] ?? ''));
        if ($orderId === '' || preg_match('/^[0-9]+$/', $orderId) !== 1) {
            throw new InvalidArgumentException('El trabajo order_exact requiere external_order_id numérico.');
        }
        $localId = $context->logicalRemoteCall(fn (): mixed => ($this->sync)($work, $orderId));

        return WorkResult::completed([
            'external_order_id' => $orderId,
            'local_order_id' => is_numeric($localId) ? (int) $localId : null,
        ]);
    }
}
