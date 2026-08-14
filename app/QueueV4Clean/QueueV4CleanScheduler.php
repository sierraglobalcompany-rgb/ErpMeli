<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Core\Env;
use App\Services\EmergencyControlService;
use App\Services\SalesAuditExactRepairService;
use PDO;

final class QueueV4CleanScheduler
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function run(int $maxJobs = QueueV4CleanWorker::DEFAULT_MAX_JOBS, int $runtimeSeconds = 45): array
    {
        QueueV4CleanOAuthStageContext::reset();
        $startedAt = microtime(true);
        $deadline = $startedAt + max(5, min(45, $runtimeSeconds));
        $repository = new QueueV4CleanRepository($this->pdo);
        $control = $repository->control();
        if ((string) $control['engine_state'] !== 'ACTIVE'
            || (int) $control['scheduler_enabled'] !== 1
            || (new EmergencyControlService())->automationStopped()
            || Env::bool('ML_WRITE_ENABLED', false)) {
            return ['ok' => true, 'status' => 'stopped', 'processed' => 0];
        }
        $owner = bin2hex(random_bytes(16));
        $lease = $this->pdo->prepare(
            "UPDATE queue_v4_clean_leases
             SET owner_ref=?,acquired_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
                 expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND)
             WHERE lease_key='scheduler' AND (owner_ref IS NULL OR expires_at<UTC_TIMESTAMP(3))"
        );
        $lease->execute([$owner]);
        if ($lease->rowCount() !== 1) {
            return ['ok' => true, 'status' => 'busy', 'processed' => 0];
        }
        QueueV4CleanCycleBudget::start(min(10, max(1, $maxJobs)));
        try {
            $oauth = (new QueueV4CleanOAuthSupervisor(
                $this->pdo,
                new QueueV4CleanOAuthOperationRepository($this->pdo),
            ))->run($owner);
            if (($oauth['abort_scheduler'] ?? false) === true) {
                return [
                    'ok' => false,
                    'status' => (string) ($oauth['status'] ?? 'oauth_control_plane_blocked'),
                    'oauth' => $oauth,
                    'producer' => ['skipped' => true],
                    'worker' => ['skipped' => true],
                ];
            }
            // OAuth provenance must not leak into later producer/worker
            // diagnostics in the same long-lived PHP process.
            QueueV4CleanOAuthStageContext::reset();
            $recovery = (new QueueV4CleanUncertainReadRecoveryService($this->pdo))->recoverOne();
            $salesAudit = (new QueueV4CleanSalesAuditStage())->run($deadline);
            if (($salesAudit['abort_scheduler'] ?? false) === true) {
                return [
                    'ok' => false,
                    'status' => 'sales_audit_invariant_blocked',
                    'oauth' => $oauth,
                    'recovery' => $recovery,
                    'sales_audit' => $salesAudit,
                    'producer' => ['skipped' => true],
                    'worker' => ['skipped' => true],
                    'claimed_total' => (int) ($oauth['claimed'] ?? 0) + (int) ($salesAudit['claimed'] ?? 0),
                    'http_budget' => QueueV4CleanCycleBudget::snapshot(),
                ];
            }
            $oauthClaimed = (int) ($oauth['claimed'] ?? 0);
            $salesClaimed = (int) ($salesAudit['claimed'] ?? 0);
            $salesRepair = $oauthClaimed + $salesClaimed < min(10, $maxJobs)
                && microtime(true) < $deadline - 3.0
                ? (new SalesAuditExactRepairService())->processDue(1)
                : ['processed' => 0, 'jobs' => 0, 'status' => 'deferred'];
            $producer = (new QueueV4CleanProducer($this->pdo, $repository))->produce();
            $repairClaimed = (int) ($salesRepair['jobs'] ?? 0);
            $remainingJobs = max(0, min(10, $maxJobs) - $oauthClaimed - $salesClaimed - $repairClaimed);
            $remaining = min(45, (int) floor($deadline - microtime(true)));
            // Reserve a small local-only window for the incident read model.
            // HTTP/business work remains bounded by the shared cycle budget.
            $workerRuntime = $remaining >= 8 ? $remaining - 3 : $remaining;
            $worker = $remaining >= 5 && $remainingJobs > 0
                ? (new QueueV4CleanWorker($this->pdo, $repository))->run('scheduler', $remainingJobs, $workerRuntime)
                : ['claimed' => 0, 'completed' => 0, 'deferred' => 0];
            $maintenance = microtime(true) < $deadline - 1.0
                ? (new QueueV4CleanMaintenanceService())->run(100)
                : ['materialized' => 0, 'retained' => 0, 'warnings' => 0, 'deferred' => true];
            $this->pdo->exec(
                "UPDATE queue_v4_clean_control SET last_scheduler_at=UTC_TIMESTAMP(3) WHERE control_key='primary'"
            );
            return [
                'ok' => true,
                'status' => 'completed',
                'oauth' => $oauth,
                'recovery' => $recovery,
                'maintenance' => $maintenance,
                'sales_audit' => $salesAudit,
                'sales_repair' => $salesRepair,
                'producer' => $producer,
                'worker' => $worker,
                'claimed_total' => $oauthClaimed + $salesClaimed + $repairClaimed + (int) ($worker['claimed'] ?? 0),
                'http_budget' => QueueV4CleanCycleBudget::snapshot(),
            ];
        } finally {
            $release = $this->pdo->prepare(
                "UPDATE queue_v4_clean_leases
                 SET owner_ref=NULL,acquired_at=NULL,heartbeat_at=NULL,expires_at=NULL
                 WHERE lease_key='scheduler' AND owner_ref=?"
            );
            $release->execute([$owner]);
            QueueV4CleanCycleBudget::clear();
        }
    }
}
