<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Core\Env;
use App\Services\CronDeadlineContext;
use App\Services\EmergencyControlService;
use PDO;
use Throwable;

final class CronV4Cli
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /** @param list<string> $argv @return array<string,mixed> */
    public function run(array $argv): array
    {
        $runtime = $this->option($argv, 'runtime', 45, 5, 55);
        $max = $this->option($argv, 'max-jobs', 50, 1, 200);
        $deadline = microtime(true) + $runtime;
        if ((new EmergencyControlService())->automationStopped()) {
            return ['ok' => true, 'status' => 'SKIPPED_AUTOMATION_STOPPED', 'side_effects' => 0, 'claimed' => 0];
        }
        if (!Env::bool('CRON_V4_ENABLED', false)) {
            return ['ok' => true, 'status' => 'DISABLED', 'side_effects' => 0, 'claimed' => 0];
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            return ['ok' => false, 'status' => 'BLOCKED_ML_WRITE_ENABLED', 'side_effects' => 0, 'claimed' => 0];
        }

        $safeClose = min(10, max(1, $runtime - 1));
        CronDeadlineContext::start($runtime, $runtime - $safeClose, 8, 3);
        $executionLease = null;
        $engineControl = null;
        $enginePermit = null;
        $runLedger = null;
        $runId = 0;
        $runFinalized = false;
        try {
            if ($this->pdo === null) {
                Database::useProfile('cli');
            }
            $pdo = $this->pdo ?? Database::connectionFresh();
            if (!(new QueueCorePreflightService($pdo))->runtimeSchemaReady()) {
                return ['ok' => false, 'status' => 'BLOCKED_SCHEMA_INCOMPLETE', 'side_effects' => 0, 'claimed' => 0, 'http' => 0];
            }
            $core = QueueCoreFactory::build($pdo);

            // Preserve the web-manual exclusion even while the active engine is
            // disabled. This is a read-only check and cannot enqueue work.
            if ($core['execution_leases']->activeLauncher() === 'manual') {
                return [
                    'ok' => true,
                    'status' => 'SKIPPED_MANUAL_ACTIVE',
                    'side_effects' => 0,
                    'producer_calls' => 0,
                    'stale_recovery' => 0,
                    'claimed' => 0,
                    'handlers' => 0,
                    'http' => 0,
                ];
            }

            $engineControl = new QueueEngineControlService($pdo);
            $engineRuntime = $engineControl->acquireRuntime('v4', 'operational');
            if (empty($engineRuntime['ok'])
                || !(($engineRuntime['permit'] ?? null) instanceof QueueEngineRuntimePermit)) {
                return [
                    'ok' => true,
                    'status' => 'SKIPPED_ENGINE_INACTIVE',
                    'reason' => (string) ($engineRuntime['reason'] ?? 'engine_control_unavailable'),
                    'active_engine' => (string) ($engineRuntime['active_engine'] ?? 'disabled'),
                    'generation' => (int) ($engineRuntime['generation'] ?? 0),
                    'side_effects' => 0,
                    'claimed' => 0,
                    'http' => 0,
                ];
            }
            $enginePermit = $engineRuntime['permit'];

            $worker = 'cron-v4-' . bin2hex(random_bytes(8));
            $executionLease = $core['execution_leases']->acquire('cron_v4', $worker, 60);
            if ($executionLease === null) {
                return [
                    'ok' => true,
                    'status' => 'SKIPPED_LAUNCHER_ACTIVE',
                    'side_effects' => 0,
                    'producer_calls' => 0,
                    'stale_recovery' => 0,
                    'claimed' => 0,
                    'handlers' => 0,
                    'http' => 0,
                ];
            }

            $runLedger = new QueueCoreRunLedger($pdo);
            $runId = $runLedger->begin($enginePermit->generation, 'cron_v4', $worker);

            if (!$this->advancePhase($runLedger, $runId, 'stale_recovery', $core, $executionLease, 3)) {
                $result = ['ok'=>true,'status'=>'STOPPED_SAFE_CLOSE','claimed'=>0,'http'=>0,'run'=>['claimed'=>0,'reason'=>'deadline']];
                $runLedger->finish($runId, 'stopped', 'deadline_before_stale_recovery', $result);
                $runFinalized = true;
                return $result;
            }
            $recovered = $core['repository']->recoverStale(min(100, $max));
            if (!$this->advancePhase($runLedger, $runId, 'oauth_supervisor', $core, $executionLease, 3)) {
                $result = ['ok'=>true,'status'=>'STOPPED_SAFE_CLOSE','recovered'=>$recovered,'claimed'=>0,'http'=>0,'run'=>['claimed'=>0,'reason'=>'deadline']];
                $runLedger->finish($runId, 'stopped', 'deadline_before_oauth_supervisor', $result);
                $runFinalized = true;
                return $result;
            }
            $oauthProduced = $core['oauth_supervisor']->scheduleDueAccounts(min(20, $max));
            if (!$this->advancePhase($runLedger, $runId, 'fresh_producer', $core, $executionLease, 3)) {
                $result = ['ok'=>true,'status'=>'STOPPED_SAFE_CLOSE','recovered'=>$recovered,'oauth_supervisor'=>$oauthProduced,'claimed'=>0,'http'=>0,'run'=>['claimed'=>0,'reason'=>'deadline']];
                $runLedger->finish($runId, 'stopped', 'deadline_before_fresh_producer', $result);
                $runFinalized = true;
                return $result;
            }
            $produced = $core['feature_flags']->enabled('fresh_producer')
                ? $core['producer']->scheduleDueAccounts(min(20, $max))
                : ['created'=>0,'revived'=>0,'existing'=>0,'blocked'=>0,'disabled'=>1];
            $saleMaterialized = $core['feature_flags']->enabled('pack_shipment_followups')
                ? $core['sale_pipeline']->materializePending(min(20, $max))
                : ['materialized'=>0,'review'=>0,'already_terminal'=>0,'disabled'=>1];
            $effectiveDeadline = CronDeadlineContext::deadline() ?? $deadline;
            if (!$engineControl->stillCurrent($enginePermit)) {
                $result = [
                    'ok' => true,
                    'status' => 'ENGINE_GENERATION_CHANGED',
                    'side_effects' => 0,
                    'oauth_supervisor' => $oauthProduced,
                    'producer' => $produced,
                    'sale_pipeline' => $saleMaterialized,
                    'claimed' => 0,
                    'http' => 0,
                ];
                $runLedger->finish($runId, 'stopped', 'engine_generation_changed', $result);
                $runFinalized = true;
                return $result;
            }
            // Un solo consumidor preserva FIFO absoluto. OAuth no obtiene un
            // carril privilegiado: los trabajos que requieren token esperan en
            // waiting_oauth y reanudan tras avanzar la generación de su cuenta.
            $run = $core['runner']->run(new QueueRunRequest(
                'cron_v4',
                $worker,
                $max,
                $effectiveDeadline,
                60,
                [],
                [],
                null,
                $executionLease,
                null,
                null,
                $runId,
            ));

            $result = [
                'ok' => true,
                'status' => 'COMPLETE',
                'oauth_supervisor' => $oauthProduced,
                'producer' => $produced,
                'sale_pipeline' => $saleMaterialized,
                'recovered' => $recovered,
                'run' => $run,
                'metrics' => (new QueueMetricsService($pdo))->snapshot(),
            ];
            $runLedger->finish(
                $runId,
                ($run['reason'] ?? '') === 'execution_lease_lost' ? 'lease_lost' : 'completed',
                (string) ($run['reason'] ?? 'complete'),
                $result
            );
            $runFinalized = true;
            return $result;
        } catch (Throwable $error) {
            if ($runLedger instanceof QueueCoreRunLedger && $runId > 0 && !$runFinalized) {
                try {
                    $runLedger->finish($runId, 'failed', 'local_failure', ['claimed'=>0]);
                    $runFinalized = true;
                } catch (Throwable) {
                }
            }
            return [
                'ok' => false,
                'status' => 'LOCAL_FAILURE',
                'error_class' => strtolower((new \ReflectionClass($error))->getShortName()),
                'claimed' => 0,
            ];
        } finally {
            if ($engineControl instanceof QueueEngineControlService) {
                $engineControl->releaseRuntime(
                    $enginePermit instanceof QueueEngineRuntimePermit ? $enginePermit : null
                );
            }
            if ($executionLease !== null && isset($core)) {
                $core['execution_leases']->release($executionLease);
            }
            CronDeadlineContext::clear();
        }
    }

    /** @param array<string,mixed> $core */
    private function advancePhase(
        QueueCoreRunLedger $ledger,
        int $runId,
        string $phase,
        array $core,
        QueueExecutionLease $lease,
        int $reserveSeconds,
    ): bool {
        if (!CronDeadlineContext::canAcceptWork($reserveSeconds)) {
            return false;
        }
        if (!isset($core['execution_leases'])
            || !$core['execution_leases'] instanceof QueueExecutionLeaseService
            || !$core['execution_leases']->heartbeat($lease)) {
            return false;
        }
        $ledger->phase($runId, $phase);
        return true;
    }

    /** @param list<string> $argv */
    private function option(array $argv, string $key, int $default, int $min, int $max): int
    {
        foreach ($argv as $argument) {
            if (str_starts_with($argument, '--' . $key . '=')) {
                return max($min, min($max, (int) substr($argument, strlen($key) + 3)));
            }
        }
        return $default;
    }
}
