<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\DatabaseMutationFreezeService;
use App\Services\MaintenanceExecutionLock;
use App\Services\OperationalMaintenanceService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Throwable;

final class OperationalMaintenanceHandler implements CronV3WorkHandler
{
    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $lock = new MaintenanceExecutionLock();
        $freeze = new DatabaseMutationFreezeService();
        $owner = 'cron-v3-maintenance-' . (int) ($work->id ?? 0) . '-' . (int) $work->leaseGeneration;
        try {
            $lock->acquire();
            $freeze->activate('cron_v3_maintenance', $owner, [
                'work_id' => (int) ($work->id ?? 0),
                'lane' => 'local',
                'technical_only' => true,
            ]);
        } catch (Throwable) {
            $lock->release();
            return WorkResult::deferred(
                gmdate('Y-m-d H:i:s', time() + 300),
                'maintenance_protection_busy',
                ['message' => 'Mantenimiento pendiente por protección; otro proceso conserva lock o freeze.']
            );
        }

        try {
            $result = (new OperationalMaintenanceService())->run(500);
        } catch (Throwable) {
            $result = [
                'processed' => 0,
                'warnings' => 1,
                'safe_message' => 'El mantenimiento técnico no pudo completarse; se reintentará sin marcar error operativo.',
            ];
        } finally {
            try {
                $freeze->release('cron_v3_maintenance', $owner);
            } catch (Throwable) {
            }
            $lock->release();
        }

        $metadata = [
            'processed' => (int) ($result['processed'] ?? 0),
            'warnings' => (int) ($result['warnings'] ?? $result['errors'] ?? 0),
            'technical_only' => true,
        ];
        if ($metadata['warnings'] > 0) {
            return WorkResult::deferred(gmdate('Y-m-d H:i:s', time() + 300), 'maintenance_partial', $metadata);
        }

        return WorkResult::completed($metadata);
    }
}
