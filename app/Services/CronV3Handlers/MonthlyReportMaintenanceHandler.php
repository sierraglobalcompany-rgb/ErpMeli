<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\DatabaseMutationFreezeService;
use App\Services\MaintenanceExecutionLock;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Throwable;

final class MonthlyReportMaintenanceHandler implements CronV3WorkHandler
{
    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $lock = new MaintenanceExecutionLock();
        $freeze = new DatabaseMutationFreezeService();
        $owner = 'cron-v3-monthly-' . (int) ($work->id ?? 0) . '-' . (int) $work->leaseGeneration;
        try {
            $lock->acquire();
            $freeze->activate('cron_v3_maintenance', $owner, [
                'work_id' => (int) ($work->id ?? 0),
                'lane' => 'local',
                'monthly_checkpoint' => true,
            ]);
        } catch (Throwable) {
            $lock->release();
            return WorkResult::deferred(
                gmdate('Y-m-d H:i:s', time() + 1800),
                'maintenance_protection_busy',
                ['message' => 'Reportes mensuales pendientes por protección local.']
            );
        }

        try {
            return WorkResult::completed([
                'processed' => 0,
                'monthly_checkpoint' => true,
                'message' => 'Comprobación mensual local registrada; no hay mutación comercial.',
            ]);
        } finally {
            try {
                $freeze->release('cron_v3_maintenance', $owner);
            } catch (Throwable) {
            }
            $lock->release();
        }
    }
}
