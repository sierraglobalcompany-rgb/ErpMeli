<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Core\Env;
use App\Services\AppSettingsService;
use App\Services\AutomationCallBudgetService;
use App\Services\CronDeadlineContext;
use App\Services\CapacityPolicyService;
use App\Services\EmergencyControlService;
use App\Services\SalesAuditExactRepairService;
use App\Work\Adapters\QueueCoreDrainAuthority;
use App\Work\DrainAuthorityToken;
use PDO;

final class QueueV4CleanScheduler
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function run(?int $maxCalls = null, int $runtimeSeconds = 45): array
    {
        if (QueueV4CleanCycleBudget::snapshot()['limit'] > 0) {
            throw new \RuntimeException('physical_budget_already_owned');
        }
        $capacityService = new AutomationCallBudgetService(
            new AppSettingsService(),
            new CapacityPolicyService($this->pdo),
        );
        $capacity = $capacityService->resolve($maxCalls);
        $requestedMaxCalls = $capacity['requested_max_calls'];
        $maxCalls = $capacity['max_calls'];
        QueueV4CleanOAuthStageContext::reset();
        $startedAt = microtime(true);
        $deadline = $startedAt + max(5, min(45, $runtimeSeconds));
        $repository = new QueueV4CleanRepository($this->pdo);
        $control = $repository->control();
        if ((string) $control['engine_state'] !== 'ACTIVE'
            || (int) $control['scheduler_enabled'] !== 1
            || (new EmergencyControlService())->automationStopped()
            || Env::bool('ML_WRITE_ENABLED', false)) {
            return [
                'ok' => true,
                'status' => 'stopped',
                'processed' => 0,
                'control_unit' => 'PHYSICAL_API_CALL',
                'max_calls' => $maxCalls,
                'requested_max_calls' => $requestedMaxCalls,
                'configured_max_calls' => $capacity['configured_max_calls'],
                'ceiling' => $capacity['ceiling'],
            ];
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
            return [
                'ok' => true,
                'status' => 'busy',
                'processed' => 0,
                'control_unit' => 'PHYSICAL_API_CALL',
                'max_calls' => $maxCalls,
                'requested_max_calls' => $requestedMaxCalls,
                'configured_max_calls' => $capacity['configured_max_calls'],
                'ceiling' => $capacity['ceiling'],
            ];
        }
        $drainLeases = new QueueCoreDrainAuthority($this->pdo);
        $drainAuthority = $drainLeases->acquire('cron_v4', $owner, 60);
        if (!$drainAuthority instanceof DrainAuthorityToken) {
            $release = $this->pdo->prepare(
                "UPDATE queue_v4_clean_leases
                 SET owner_ref=NULL,acquired_at=NULL,heartbeat_at=NULL,expires_at=NULL
                 WHERE lease_key='scheduler' AND owner_ref=?"
            );
            $release->execute([$owner]);
            return [
                'ok' => true,
                'status' => 'busy_drainer',
                'processed' => 0,
                'claimed_total' => 0,
                'control_unit' => 'PHYSICAL_API_CALL',
                'max_calls' => $maxCalls,
                'requested_max_calls' => $requestedMaxCalls,
                'configured_max_calls' => $capacity['configured_max_calls'],
                'ceiling' => $capacity['ceiling'],
            ];
        }
        try {
            // The lease wait is a concurrency boundary. Re-read the ERP pair
            // immediately before beginning physical work so saved reductions win.
            $capacity = $capacityService->resolve($requestedMaxCalls);
            $maxCalls = $capacity['max_calls'];
            $outerDeadline = CronDeadlineContext::deadline();
            QueueV4CleanCycleBudget::start($maxCalls, 'automatic', $outerDeadline === null ? $deadline : min($deadline,$outerDeadline));
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
                    'control_unit' => 'PHYSICAL_API_CALL',
                    'max_calls' => $maxCalls,
                    'requested_max_calls' => $requestedMaxCalls,
                    'configured_max_calls' => $capacity['configured_max_calls'],
                    'ceiling' => $capacity['ceiling'],
                    'http_budget' => QueueV4CleanCycleBudget::snapshot(),
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
                    'control_unit' => 'PHYSICAL_API_CALL',
                    'max_calls' => $maxCalls,
                    'requested_max_calls' => $requestedMaxCalls,
                    'configured_max_calls' => $capacity['configured_max_calls'],
                    'ceiling' => $capacity['ceiling'],
                    'http_budget' => QueueV4CleanCycleBudget::snapshot(),
                ];
            }
            $oauthClaimed = (int) ($oauth['claimed'] ?? 0);
            $salesClaimed = (int) ($salesAudit['claimed'] ?? 0);
            $producer = (new QueueV4CleanProducer($this->pdo, $repository))->produce();
            // This bounded local-only step must run before the worker can use
            // the remaining wall clock. Otherwise a busy remote queue can
            // leave the browser incident catalogue permanently stale.
            $maintenance = microtime(true) < $deadline - 1.0
                ? (new QueueV4CleanMaintenanceService())->run(200)
                : ['materialized' => 0, 'retained' => 0, 'warnings' => 0, 'deferred' => true];
            $availableWorkerCalls = QueueV4CleanCycleBudget::remaining();
            $remaining = min(45, (int) floor($deadline - microtime(true)));
            // Reserve a small local-only window for the incident read model.
            // HTTP/business work remains bounded by the shared cycle budget.
            $workerRuntime = $remaining >= 8 ? $remaining - 3 : $remaining;
            $worker = $remaining >= 5 && $availableWorkerCalls > 0
                ? (new QueueV4CleanWorker($this->pdo, $repository))->run('scheduler', $availableWorkerCalls, $workerRuntime)
                : ['claimed' => 0, 'completed' => 0, 'deferred' => 0];
            $workerClaimed = (int) ($worker['claimed'] ?? 0);
            $protectedStop = in_array((string) ($worker['stop_reason'] ?? ''), ['remote_result_uncertain', 'remote_429_global_pause'], true)
                ? (string) $worker['stop_reason'] : null;
            $salesRepair = $protectedStop === null && QueueV4CleanCycleBudget::remaining() > 0 && CronDeadlineContext::canAcceptWork(3)
                ? (new SalesAuditExactRepairService())->processDue(1)
                : ['processed' => 0, 'jobs' => 0, 'status' => 'deferred'];
            $repairClaimed = (int) ($salesRepair['jobs'] ?? 0);
            $claimedTotal = $oauthClaimed + $salesClaimed + $workerClaimed + $repairClaimed;
            if ($repairClaimed > 1 || QueueV4CleanCycleBudget::snapshot()['used'] > $maxCalls) {
                throw new \RuntimeException('queue_v4_clean_functional_capacity_exceeded');
            }
            $this->pdo->exec(
                "UPDATE queue_v4_clean_control SET last_scheduler_at=UTC_TIMESTAMP(3) WHERE control_key='primary'"
            );
            $physicalReceipt = QueueV4CleanCycleBudget::snapshot();
            return [
                'ok' => $protectedStop === null,
                'status' => $protectedStop ?? 'completed',
                'oauth' => $oauth,
                'recovery' => $recovery,
                'maintenance' => $maintenance,
                'sales_audit' => $salesAudit,
                'sales_repair' => $salesRepair,
                'producer' => $producer,
                'worker' => $worker,
                'claimed_total' => $claimedTotal,
                'control_unit' => 'PHYSICAL_API_CALL',
                'max_calls' => $maxCalls,
                'requested_max_calls' => $requestedMaxCalls,
                'configured_max_calls' => $capacity['configured_max_calls'],
                'ceiling' => $capacity['ceiling'],
                'physical_http_calls' => $physicalReceipt['physical_http_calls'],
                'physical_http_calls_certainty' => $physicalReceipt['physical_http_calls_certainty'],
                'known_physical_calls' => $physicalReceipt['known_physical_calls'],
                'charged_calls' => $physicalReceipt['used'],
                'http_budget' => $physicalReceipt,
            ];
        } finally {
            if (isset($drainLeases, $drainAuthority)
                && $drainAuthority instanceof DrainAuthorityToken) {
                $drainLeases->release($drainAuthority);
            }
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
