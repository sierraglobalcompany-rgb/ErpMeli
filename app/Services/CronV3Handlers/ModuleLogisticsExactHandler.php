<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Core\Database;
use App\Core\Modules\ModuleJobCapabilityGate;
use App\Core\Modules\ModuleRegistry;
use App\Modules\MeliLogistics\Services\LogisticsSyncService;
use App\Services\CronV3ExecutionContext;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final class ModuleLogisticsExactHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,string):void */
    private readonly Closure $preflight;

    /** @var Closure(WorkEnvelope,string):array<string,mixed> */
    private readonly Closure $sync;

    /**
     * @param null|callable(WorkEnvelope,string):void $preflight
     * @param null|callable(WorkEnvelope,string):array<string,mixed> $sync
     */
    public function __construct(?callable $preflight = null, ?callable $sync = null)
    {
        $this->preflight = $preflight !== null
            ? Closure::fromCallable($preflight)
            : static function (WorkEnvelope $work, string $shipmentId): void {
                $stmt = Database::connection()->prepare(
                    'SELECT COUNT(*) FROM meli_accounts WHERE id=? AND company_id=?'
                );
                $stmt->execute([$work->meliAccountId, $work->companyId]);
                if ((int) $stmt->fetchColumn() !== 1) {
                    throw new InvalidArgumentException('La cuenta no pertenece a la empresa del trabajo.');
                }
                if (!(new ModuleRegistry())->isEnabled('meli-logistics')) {
                    throw new RuntimeException('El módulo de logística no está instalado y habilitado.');
                }
                if (!(new ModuleJobCapabilityGate())->allows('meli-logistics', 'event_sync', [
                    'resource_type' => 'shipment',
                    'resource_id' => $shipmentId,
                ])) {
                    throw new RuntimeException('El endpoint logístico exacto no está confirmado.');
                }
            };
        $this->sync = $sync !== null
            ? Closure::fromCallable($sync)
            : static fn (WorkEnvelope $work, string $shipmentId): array =>
                (new LogisticsSyncService())->process([
                    'meli_account_id' => $work->meliAccountId,
                    'job_type' => 'event_sync',
                    'payload' => [
                        'resource_type' => 'shipment',
                        'resource_id' => $shipmentId,
                    ],
                ]);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $shipmentId = trim((string) ($work->payload['shipment_id'] ?? $work->payload['resource_id'] ?? ''));
        if ($shipmentId === '' || preg_match('/^[0-9]+$/', $shipmentId) !== 1) {
            throw new InvalidArgumentException('module_logistics_exact requiere shipment_id numérico.');
        }
        ($this->preflight)($work, $shipmentId);
        $result = $context->logicalRemoteCall(fn (): array => ($this->sync)($work, $shipmentId));
        $status = (string) ($result['status'] ?? 'completed');
        if (in_array($status, ['retry', 'pending', 'delayed'], true)) {
            return WorkResult::deferred(
                gmdate('Y-m-d H:i:s', time() + max(30, (int) ($result['delay_seconds'] ?? 60))),
                'module_logistics_retry',
                ['shipment_id' => $shipmentId]
            );
        }
        if ((int) ($result['processed'] ?? 0) !== 1 || (int) ($result['errors'] ?? 0) !== 0) {
            return WorkResult::review('module_logistics_uncertain', ['shipment_id' => $shipmentId]);
        }

        return WorkResult::completed(['shipment_id' => $shipmentId]);
    }
}
