<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

/**
 * Ejecuta pasos de cron con presupuesto global, aislamiento de fallos y trazabilidad.
 */
final class CronWorkCoordinator
{
    private float $startedAt;
    private float $deadline;
    private string $runToken;
    private array $steps = [];
    private bool $traceAvailable;

    public function __construct(
        private readonly ?int $cronHealthId = null,
        ?int $budgetSeconds = null,
        ?string $runToken = null
    ) {
        $settings = new AppSettingsService();
        $seconds = $budgetSeconds ?? $settings->int('cron.run_time_budget_seconds', 45);
        $this->startedAt = microtime(true);
        $this->deadline = $this->startedAt + max(5, min(240, $seconds));
        $this->runToken = $runToken ?: bin2hex(random_bytes(16));
        $this->traceAvailable = (new SchemaInspectorService())->hasTable('system_cron_run_steps');
    }

    public function deadline(): float
    {
        return $this->deadline;
    }

    public function remainingMilliseconds(): int
    {
        return max(0, (int) floor(($this->deadline - microtime(true)) * 1000));
    }

    /**
     * @param callable(float):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    public function run(
        string $name,
        int $priority,
        callable $callback,
        string $lane = 'normal',
        ?float $stepDeadline = null,
        ?string $selectionReason = null
    ): array
    {
        if ($this->remainingMilliseconds() < 750 || !CronDeadlineContext::canAcceptWork()) {
            $result = [
                'status' => 'deferred',
                'selected' => 1,
                'started' => 0,
                'not_started' => 1,
                'processed' => 0,
                'errors' => 0,
                'stop_reason' => 'time_budget',
                'message' => 'El paso quedó programado para la siguiente ejecución del cron.',
            ];
            $this->record($name, $priority, $result, 0, $lane, $selectionReason);
            return $result;
        }

        $start = microtime(true);
        $attemptedBefore = ApiExecutionMetadataContext::remoteAttemptCount();
        $remoteBefore = ApiExecutionMetadataContext::remoteDispatchCount();
        $blockedBefore = ApiExecutionMetadataContext::remoteBlockedCount();
        $responsesBefore = ApiExecutionMetadataContext::knownResponseCount();
        $resourcesBefore = ApiExecutionMetadataContext::resourcesReceivedCount();
        if (($this->cronHealthId ?? 0) > 0) {
            (new CronHealthService())->heartbeat((int) $this->cronHealthId, [
                'current_step' => $name,
                'phase' => 'starting',
                'coordinator' => $this->summary(),
            ]);
        }
        try {
            $effectiveDeadline = min($this->deadline, $stepDeadline ?? $this->deadline);
            $payload = CronDeadlineContext::within($effectiveDeadline, static fn (): array => $callback($effectiveDeadline));
            $processed = $this->processedCount($payload);
            $errors = $this->errorCount($payload);
            $result = CronWorkOutcome::normalize($payload, $processed, $errors);
        } catch (CronDeadlineDeferredException $error) {
            $result = [
                'status' => 'waiting_deadline',
                'processed' => 0,
                'errors' => 0,
                'deferred' => 1,
                'stop_reason' => 'time_budget',
                'message' => 'El paso continuará en el siguiente ciclo porque no quedaba una ventana segura para iniciar HTTP.',
                'next_safe_at' => $error->nextSafeAt,
            ];
        } catch (ApiRhythmDeferredException $error) {
            $result = [
                'status' => 'waiting_rhythm',
                'processed' => 0,
                'errors' => 0,
                'deferred' => 1,
                'stop_reason' => 'api_rhythm',
                'message' => 'El paso continuará en la próxima oportunidad del ritmo configurado.',
                'next_safe_at' => $error->nextSafeAt,
                'blocking_scope' => $error->blockingScope,
            ];
        } catch (ApiBudgetExhaustedException $error) {
            $result = [
                'status' => 'waiting_budget',
                'processed' => 0,
                'errors' => 0,
                'deferred' => 1,
                'stop_reason' => 'api_budget',
                'message' => 'El paso continuará cuando vuelva a existir una ventana segura de consultas.',
                'next_safe_at' => $error->nextSafeAt,
            ];
        } catch (Throwable $error) {
            $reference = SafeErrorPresenter::report(
                $error,
                'Un proceso del cron no pudo completarse y se reintentará de forma independiente.',
                ['cron_step' => $name]
            );
            $result = [
                'status' => 'failed',
                'processed' => 0,
                'errors' => 1,
                'stop_reason' => 'error',
                'message' => $reference['message'],
                'diagnostic_id' => $reference['reference'],
            ];
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $result['remote_calls'] = max(
            0,
            ApiExecutionMetadataContext::remoteDispatchCount() - $remoteBefore
        );
        $result['attempted_remote_calls'] = max(
            0,
            ApiExecutionMetadataContext::remoteAttemptCount() - $attemptedBefore
        );
        $result['blocked_remote_calls'] = max(
            0,
            ApiExecutionMetadataContext::remoteBlockedCount() - $blockedBefore
        );
        $result['known_responses'] = max(
            0,
            ApiExecutionMetadataContext::knownResponseCount() - $responsesBefore
        );
        $result['resources_received'] = max(
            0,
            ApiExecutionMetadataContext::resourcesReceivedCount() - $resourcesBefore
        );
        $result['duration_ms'] = $durationMs;
        $result['selected'] = max(1, (int) ($result['selected'] ?? 1));
        $notStarted = max(0, (int) ($result['not_started'] ?? 0));
        $result['started'] = array_key_exists('started', $result)
            ? max(0, (int) $result['started'])
            : $this->startedFromUsefulWork($result, $notStarted);
        $result['not_started'] = $notStarted > 0
            ? $notStarted
            : ((int) $result['started'] === 0 ? 1 : 0);
        $this->record($name, $priority, $result, $durationMs, $lane, $selectionReason);
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return [
            'run_token' => $this->runToken,
            'budget_ms' => (int) round(($this->deadline - $this->startedAt) * 1000),
            'used_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
            'remaining_ms' => $this->remainingMilliseconds(),
            'remote_calls' => ApiExecutionMetadataContext::remoteDispatchCount(),
            'attempted_remote_calls' => ApiExecutionMetadataContext::remoteAttemptCount(),
            'blocked_remote_calls' => ApiExecutionMetadataContext::remoteBlockedCount(),
            'known_responses' => ApiExecutionMetadataContext::knownResponseCount(),
            'resources_received' => ApiExecutionMetadataContext::resourcesReceivedCount(),
            'steps' => $this->steps,
        ];
    }

    private function record(
        string $name,
        int $priority,
        array $result,
        int $durationMs,
        string $lane = 'normal',
        ?string $selectionReason = null
    ): void
    {
        $this->steps[$name] = [
            'priority' => $priority,
            'lane' => $lane,
            'selection_reason' => $selectionReason,
            'selected' => max(1, (int) ($result['selected'] ?? 1)),
            'started' => max(0, (int) ($result['started'] ?? $this->startedFromUsefulWork($result, max(0, (int) ($result['not_started'] ?? 0))))),
            'inspected' => max(0, (int) ($result['inspected'] ?? $result['processed'] ?? 0)),
            'deferred' => max(0, (int) ($result['deferred'] ?? (CronWorkOutcome::isWaiting((string) ($result['status'] ?? '')) ? 1 : 0))),
            'checkpoint_approved' => max(0, (int) ($result['checkpoint_approved'] ?? $result['approved'] ?? 0)),
            'completed' => CronWorkOutcome::isWaiting((string) ($result['status'] ?? ''))
                ? 0
                : max(0, (int) ($result['completed'] ?? $result['processed'] ?? 0)),
            'failed' => max(0, (int) ($result['failed'] ?? $result['errors'] ?? 0)),
            'not_started' => max(0, (int) ($result['not_started'] ?? 0)),
            'status' => (string) ($result['status'] ?? 'empty'),
            'processed' => (int) ($result['processed'] ?? 0),
            'errors' => (int) ($result['errors'] ?? 0),
            'duration_ms' => $durationMs,
            'remote_calls' => max(0, (int) ($result['remote_calls'] ?? 0)),
            'attempted_remote_calls' => max(0, (int) ($result['attempted_remote_calls'] ?? $result['remote_calls'] ?? 0)),
            'blocked_remote_calls' => max(0, (int) ($result['blocked_remote_calls'] ?? 0)),
            'known_responses' => max(0, (int) ($result['known_responses'] ?? 0)),
            'resources_received' => max(0, (int) ($result['resources_received'] ?? 0)),
            'stop_reason' => (string) ($result['stop_reason'] ?? ''),
        ];
        if (($this->cronHealthId ?? 0) > 0) {
            (new CronHealthService())->heartbeat((int) $this->cronHealthId, [
                'current_step' => $name,
                'coordinator' => $this->summary(),
            ]);
        }
        if (!$this->traceAvailable) {
            return;
        }
        try {
            Database::connectionFresh()->prepare(
                'INSERT INTO system_cron_run_steps
                 (cron_health_check_id,run_token,step_name,lane,campaign_id,campaign_item_id,selection_reason,execution_result,
                  priority,status,selected_count,started_count,inspected_count,deferred_count,attempted_remote_call_count,
                  remote_call_count,blocked_remote_call_count,
                  checkpoint_approved_count,completed_count,failed_count,not_started_count,processed_count,error_count,
                  duration_ms,stop_reason,next_opportunity_at,safe_message,finished_at)
                 VALUES (:health,:token,:step,:lane,:campaign,:campaign_item,:selection_reason,:execution_result,
                         :priority,:status,:selected,:started,:inspected,:deferred,:attempted_remote,:remote,:blocked_remote,:approved,:completed,:failed,
                         :not_started,:processed,:errors,:duration,:reason,:next_at,:message,UTC_TIMESTAMP())'
            )->execute([
                'health' => $this->cronHealthId,
                'token' => $this->runToken,
                'step' => $name,
                'lane' => $lane,
                'campaign' => !empty($result['campaign_id']) ? (int) $result['campaign_id'] : null,
                'campaign_item' => !empty($result['campaign_item_id']) ? (int) $result['campaign_item_id'] : null,
                'selection_reason' => $selectionReason,
                'execution_result' => (string) ($result['campaign_result'] ?? $result['status'] ?? ''),
                'priority' => $priority,
                'status' => CronWorkOutcome::traceState((string) ($result['status'] ?? 'failed')),
                'selected' => 1,
                'started' => max(0, (int) ($result['started'] ?? $this->startedFromUsefulWork($result, max(0, (int) ($result['not_started'] ?? 0))))),
                'inspected' => max(0, (int) ($result['inspected'] ?? $result['processed'] ?? 0)),
                'deferred' => max(0, (int) ($result['deferred'] ?? 0)),
                'attempted_remote' => max(0, (int) ($result['attempted_remote_calls'] ?? $result['remote_calls'] ?? 0)),
                'remote' => max(0, (int) ($result['remote_calls'] ?? 0)),
                'blocked_remote' => max(0, (int) ($result['blocked_remote_calls'] ?? 0)),
                'approved' => max(0, (int) ($result['checkpoint_approved'] ?? $result['approved'] ?? 0)),
                'completed' => CronWorkOutcome::isWaiting((string) ($result['status'] ?? ''))
                    ? 0
                    : max(0, (int) ($result['completed'] ?? $result['processed'] ?? 0)),
                'failed' => max(0, (int) ($result['failed'] ?? $result['errors'] ?? 0)),
                'not_started' => max(0, (int) ($result['not_started'] ?? 0)),
                'processed' => (int) ($result['processed'] ?? 0),
                'errors' => (int) ($result['errors'] ?? 0),
                'duration' => $durationMs,
                'reason' => mb_substr((string) ($result['stop_reason'] ?? ''), 0, 120) ?: null,
                'next_at' => $result['campaign_next_at'] ?? $result['next_safe_at'] ?? $result['next_eligible_at'] ?? null,
                'message' => mb_substr(Logger::redactString((string) ($result['message'] ?? '')), 0, 500) ?: null,
            ]);
        } catch (Throwable) {
            // La observabilidad nunca debe bloquear el trabajo principal.
        }
        // system_cron_run_steps es la bitácora canónica por paso. Las
        // inserciones paralelas en system_process_metrics duplicaban una fila
        // por cada tarea seleccionada y no tenían lectores operativos. La
        // tabla histórica se conserva para archivo/retención, pero Cron deja
        // de alimentarla.
    }

    private function processedCount(array $payload): int
    {
        foreach (['processed', 'processed_chunks', 'orders', 'enqueued', 'questions'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }
        return 0;
    }

    private function errorCount(array $payload): int
    {
        foreach (['errors', 'error_chunks', 'failed'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }
        return 0;
    }

    /** @param array<string,mixed> $result */
    private function startedFromUsefulWork(array $result, int $notStarted): int
    {
        if ($notStarted > 0) {
            return 0;
        }

        $status = strtolower((string) ($result['status'] ?? ''));
        $stopReason = strtolower((string) ($result['stop_reason'] ?? ''));
        if (CronWorkOutcome::isWaiting($status)
            || str_starts_with($status, 'waiting_')
            || in_array($stopReason, ['waiting_source', 'source_paused', 'not_started_deadline', 'lane_deadline', 'time_budget'], true)) {
            return max(0, (int) ($result['remote_calls'] ?? 0)) > 0
                || max(0, (int) ($result['attempted_remote_calls'] ?? 0)) > 0
                || max(0, (int) ($result['known_responses'] ?? 0)) > 0
                || max(0, (int) ($result['resources_received'] ?? 0)) > 0
                ? 1
                : 0;
        }

        foreach ([
            'processed',
            'completed',
            'inspected',
            'checkpoint_approved',
            'approved',
            'remote_calls',
            'attempted_remote_calls',
            'blocked_remote_calls',
            'known_responses',
            'resources_received',
            'errors',
            'failed',
        ] as $key) {
            if (isset($result[$key]) && is_numeric($result[$key]) && (int) $result[$key] > 0) {
                return 1;
            }
        }

        if ($status === '') {
            return 0;
        }
        return in_array($status, ['failed', 'partial', 'complete', 'error', 'action_required'], true) ? 1 : 0;
    }
}
