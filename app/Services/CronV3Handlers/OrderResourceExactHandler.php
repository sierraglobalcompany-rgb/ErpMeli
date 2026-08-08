<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3;
use App\Services\CronV3ExecutionContext;
use App\Services\OrderSyncService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class OrderResourceExactHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,array<string,mixed>):array<string,mixed> */
    private readonly Closure $process;

    /** @var Closure(WorkEnvelope):mixed */
    private readonly Closure $enqueue;

    /**
     * @param null|callable(WorkEnvelope,array<string,mixed>):array<string,mixed> $process
     * @param null|callable(WorkEnvelope):mixed $enqueue
     */
    public function __construct(
        private readonly string $resourceType,
        ?callable $process = null,
        ?callable $enqueue = null,
    ) {
        if (!in_array($resourceType, ['pack', 'shipment'], true)) {
            throw new InvalidArgumentException('Tipo de enriquecimiento Cron V3 inválido.');
        }
        $this->process = $process !== null
            ? Closure::fromCallable($process)
            : static fn (WorkEnvelope $work, array $job): array =>
                (new OrderSyncService($work->meliAccountId))->processEnrichmentResource($job);
        $this->enqueue = $enqueue !== null
            ? Closure::fromCallable($enqueue)
            : static fn (WorkEnvelope $child): array => CronV3::enqueue($child);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $localOrderId = (int) ($work->payload['meli_order_id'] ?? 0);
        $externalId = trim((string) ($work->payload['external_resource_id'] ?? ''));
        if ($localOrderId < 1 || $externalId === '') {
            throw new InvalidArgumentException('El recurso exacto requiere meli_order_id y external_resource_id.');
        }
        $job = [
            'id' => (int) ($work->payload['legacy_job_id'] ?? $work->id ?? 0),
            'meli_account_id' => $work->meliAccountId,
            'meli_order_id' => $localOrderId,
            'resource_type' => $this->resourceType,
            'external_resource_id' => $externalId,
        ];
        $result = $context->logicalRemoteCall(fn (): array => ($this->process)($work, $job));

        $spawnedShipment = trim((string) ($result['spawned_shipment_id'] ?? ''));
        if ($this->resourceType === 'pack' && $spawnedShipment !== '') {
            ($this->enqueue)(WorkEnvelope::create(
                $work->companyId,
                $work->meliAccountId,
                'shipment_exact',
                'remote',
                'shipment:' . $spawnedShipment,
                'shipment:' . $spawnedShipment . ':from:' . $work->inputVersion,
                [
                    'meli_order_id' => $localOrderId,
                    'external_resource_id' => $spawnedShipment,
                ],
                $work->sourceRef ?? ('pack:' . $externalId),
                $work->priority
            ));
        }

        return WorkResult::completed([
            'resource_type' => $this->resourceType,
            'external_resource_id' => $externalId,
            'spawned_shipment' => $spawnedShipment !== '',
        ]);
    }
}
