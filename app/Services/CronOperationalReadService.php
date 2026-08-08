<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\InternalUrl;
use PDO;
use Throwable;

/** Lectura rápida y estrictamente inmutable para el Centro de Automatización. */
final class CronOperationalReadService
{
    private SystemDatabaseUtcClock $clock;
    private ReadModelCacheService $cache;
    /** @var array<string,array<string,mixed>> */
    private array $definitions = [];
    private string $snapshotState = 'complete';
    private bool $hourMetricsAvailable = true;
    private bool $issueMetricsAvailable = true;
    private bool $batchMetricsAvailable = true;
    private bool $historyAvailable = true;
    private bool $selectorAvailable = true;

    public function __construct()
    {
        $this->clock = new SystemDatabaseUtcClock();
        $this->cache = new ReadModelCacheService();
        foreach ((new CronTaskDefinitionRegistry())->all() as $definition) {
            $this->definitions[(string) $definition['key']] = $definition;
        }
    }

    /** @return array<string,mixed> */
    /** @param list<array<string,mixed>>|null $taskRows */
    public function overview(?array $taskRows = null): array
    {
        (new CronOperationalAccessScope())->assertGlobal();
        $snapshot = (new OperationalSnapshotService())->latestCron();
        if (is_array($snapshot)) {
            return $this->overviewFromOperationalSnapshot($snapshot);
        }
        if ($taskRows !== null) {
            return $this->buildOverview($taskRows);
        }
        $cached = $this->cache->rememberArray(
            'cron-operational-overview',
            $this->readCacheKey(),
            12,
            fn (): array => $this->buildOverview($this->tasks())
        );
        $value = $cached['value'];
        $value['cache'] = $cached['cache'];
        return $value;
    }

    /** @param list<array<string,mixed>> $taskRows @return array<string,mixed> */
    private function buildOverview(array $taskRows): array
    {
        $health = (new CronHealthService())->status();
        $latestHealth = is_array($health['latest_automatic'] ?? null) ? $health['latest_automatic'] : null;
        $activeRun = $this->latestRun(false);
        $activeItems = $activeRun ? $this->runItems((int) $activeRun['id']) : [];
        $run = $this->latestRun(true);
        $items = $run ? $this->runItems((int) $run['id']) : [];
        $now = null;
        foreach ($activeItems as $item) {
            if ((string) ($item['result'] ?? '') === 'started') {
                $now = $this->presentRunItem($item);
                break;
            }
        }
        $next = $this->nextTasksFromRuntimeSelector($taskRows);
        $workload = $this->workloadSnapshot($taskRows);
        $history = $this->recentRuns(8);

        return $this->envelope([
            'state' => (string) ($health['state'] ?? 'unknown'),
            'state_label' => (string) ($health['label'] ?? 'No se pudo comprobar'),
            'state_message' => (string) ($health['message'] ?? ''),
            'last_signal_at' => $latestHealth['heartbeat_at'] ?? $latestHealth['finished_at'] ?? $latestHealth['started_at'] ?? null,
            'last_signal_label' => $this->clock->toBogota($latestHealth['heartbeat_at'] ?? $latestHealth['finished_at'] ?? $latestHealth['started_at'] ?? null),
            'observed_interval_seconds' => $health['observed_interval_seconds'] ?? null,
            'now' => $now,
            'current_run' => $activeRun ? $this->presentRun($activeRun, $activeItems) : null,
            'last_run' => $run ? $this->presentRun($run, $items) : null,
            'next' => $next,
            'workload' => $workload,
            'history' => $history,
            'section_availability' => [
                'hour_metrics' => $this->hourMetricsAvailable,
                'issues' => $this->issueMetricsAvailable,
                'batch_metrics' => $this->batchMetricsAvailable,
                'history' => $this->historyAvailable,
                'selector' => $this->selectorAvailable,
            ],
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function tasks(): array
    {
        (new CronOperationalAccessScope())->assertGlobal();
        $snapshot = (new OperationalSnapshotService())->latestCron();
        if (is_array($snapshot)) {
            return $this->tasksFromOperationalSnapshot($snapshot);
        }
        $cached = $this->cache->rememberArray(
            'cron-operational-tasks',
            $this->readCacheKey(),
            12,
            function (): array {
                $rows = $this->buildTasks();
                return [
                    'rows' => $rows,
                    'snapshot_state' => $this->snapshotState,
                ];
            }
        );
        $payload = $cached['value'];
        $rows = $payload['rows'];
        $this->snapshotState = $payload['snapshot_state'];
        if (array_filter($rows, static fn (array $row): bool => (string) ($row['measurement_state'] ?? 'complete') !== 'complete') !== []) {
            $this->snapshotState = 'partial';
        }
        return $rows;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function overviewFromOperationalSnapshot(array $snapshot): array
    {
        $protocol = $this->snapshotProtocol($snapshot);
        $this->snapshotState = $protocol === 'unavailable' ? 'unavailable' : 'partial';
        $measuredAt = isset($snapshot['measured_at']) ? (string) $snapshot['measured_at'] : null;
        $counters = is_array($snapshot['counters'] ?? null) ? $snapshot['counters'] : [];
        $queues = is_array($snapshot['queues'] ?? null) ? $snapshot['queues'] : [];
        $pending = $this->sumKnownQueueValues($queues, 'pending');
        $remoteBacklog = is_numeric($snapshot['remote_backlog'] ?? null)
            ? max(0, (int) $snapshot['remote_backlog'])
            : null;
        $failed = max(0, (int) ($counters['failed'] ?? 0));
        $completed = max(0, (int) ($counters['completed'] ?? 0));
        $deferred = max(0, (int) ($counters['deferred'] ?? 0));
        $lastRun = [
            'token' => (string) ($snapshot['run_token'] ?? ''),
            'status' => $failed > 0 ? 'partial' : 'completed',
            'selected' => max(0, (int) ($counters['selected'] ?? 0)),
            'started' => max(0, (int) ($counters['started'] ?? 0)),
            'completed' => $completed,
            'deferred' => $deferred,
            'failed' => $failed,
            'remote_calls' => max(0, (int) ($counters['http_dispatched'] ?? 0)),
            'attempted_remote_calls' => max(0, (int) ($counters['attempted_http'] ?? 0)),
            'blocked_remote_calls' => max(0, (int) ($counters['blocked_http'] ?? 0)),
            'not_started' => max(0, (int) ($counters['not_started'] ?? 0)),
            'finished_at' => $measuredAt,
            'finished_label' => $this->clock->toBogota($measuredAt),
            'duration_ms' => null,
            'end_reason' => (string) ($snapshot['end_reason'] ?? ''),
        ];
        $state = match (true) {
            $protocol === 'unavailable' => 'unknown',
            $failed > 0 => 'attention',
            max(0, (int) ($counters['http_dispatched'] ?? 0)) > 0 || $completed > 0 => 'ready',
            $deferred > 0 => 'waiting',
            default => 'ready',
        };
        return $this->envelope([
            'snapshot_source' => 'persisted_cli',
            'state' => $state,
            'state_label' => $protocol === 'unavailable'
                ? 'Último estado no comprobable'
                : ($failed > 0 ? 'Cron avanzó con asuntos por revisar' : 'Cron registró una señal reciente'),
            'state_message' => $protocol === 'unavailable'
                ? 'No se pudo validar el último snapshot. Se conservan los valores visibles anteriores.'
                : 'Resumen persistido por el proceso CLI; esta lectura no sondea las colas.',
            'last_signal_at' => $measuredAt,
            'last_signal_label' => $this->clock->toBogota($measuredAt),
            'observed_interval_seconds' => null,
            'now' => null,
            'current_run' => null,
            'last_run' => $lastRun,
            'next' => [],
            'workload' => [
                'pending' => $pending,
                'remote_backlog' => $remoteBacklog,
                'remote_calls_last_hour' => null,
                'finalized_last_hour' => null,
                'measurement_state' => $protocol,
                'trend' => 'unknown',
                'trend_label' => 'La tendencia se carga desde el historial medido.',
                'measured_at' => $measuredAt,
            ],
            'history' => [],
            'campaign' => is_array($snapshot['campaign'] ?? null) ? $snapshot['campaign'] : null,
            'section_availability' => [
                'hour_metrics' => false,
                'issues' => false,
                'batch_metrics' => false,
                'history' => false,
                'selector' => false,
                'queues' => $protocol !== 'unavailable',
            ],
        ]);
    }

    /** @param array<string,mixed> $snapshot @return list<array<string,mixed>> */
    private function tasksFromOperationalSnapshot(array $snapshot): array
    {
        $protocol = $this->snapshotProtocol($snapshot);
        $this->snapshotState = $protocol;
        $measuredAt = isset($snapshot['measured_at']) ? (string) $snapshot['measured_at'] : null;
        $queues = is_array($snapshot['queues'] ?? null) ? $snapshot['queues'] : [];
        $rows = [];
        foreach ($queues as $key => $queue) {
            if (!is_array($queue)) {
                continue;
            }
            $definition = $this->definitions[(string) $key] ?? [];
            $pending = is_numeric($queue['pending'] ?? null) ? max(0, (int) $queue['pending']) : null;
            $measurementState = in_array((string) ($queue['measurement_state'] ?? ''), ['complete', 'partial', 'unavailable'], true)
                ? (string) $queue['measurement_state']
                : 'unavailable';
            $unitSingular = (string) ($definition['unit_singular'] ?? 'recurso');
            $unitPlural = (string) ($definition['unit_plural'] ?? 'recursos');
            $rows[] = [
                'key' => (string) $key,
                'label' => $this->label((string) $key, is_array($snapshot['campaign'] ?? null) ? $snapshot['campaign'] : null),
                'lane' => (string) ($definition['lane'] ?? 'normal'),
                'remote' => !empty($queue['remote']),
                'pending' => $pending,
                'eligible_now' => is_numeric($queue['eligible_now'] ?? null) ? max(0, (int) $queue['eligible_now']) : null,
                'waiting_schedule' => is_numeric($queue['waiting_schedule'] ?? null) ? max(0, (int) $queue['waiting_schedule']) : null,
                'running_count' => is_numeric($queue['running_count'] ?? null) ? max(0, (int) $queue['running_count']) : null,
                'attention_count' => is_numeric($queue['attention_count'] ?? null) ? max(0, (int) $queue['attention_count']) : null,
                'measurement_state' => $measurementState,
                'value_state' => $protocol === 'partial' && (string) ($queue['value_state'] ?? '') === 'current'
                    ? 'last_known'
                    : (string) ($queue['value_state'] ?? 'unknown'),
                'pending_label' => $pending === null
                    ? 'Total pendiente no disponible'
                    : number_format($pending, 0, ',', '.') . ' ' . ($pending === 1 ? $unitSingular : $unitPlural) . ' por atender',
                'unit_singular' => $unitSingular,
                'unit_plural' => $unitPlural,
                'state' => $measurementState === 'complete' ? ($pending === 0 ? 'empty' : 'ready') : null,
                'state_label' => $measurementState === 'complete' ? ($pending === 0 ? 'Sin trabajo listo' : 'Lista') : null,
                'observed_at' => $queue['measured_at'] ?? $measuredAt,
                'observed_label' => $this->clock->toBogota($queue['measured_at'] ?? $measuredAt),
                'finalized_last_hour' => null,
                'remote_calls_last_hour' => null,
                'eta_hours' => null,
                'eta_label' => 'Todavía no se puede estimar',
                'hour_metrics_state' => 'unavailable',
                'batch_metrics_state' => 'unavailable',
                'issue_metrics_state' => is_numeric($queue['attention_count'] ?? null) ? 'complete' : 'unavailable',
                'url' => (string) $key === 'notification_spool'
                    ? null
                    : InternalUrl::to('/settings/cron/queue?' . http_build_query(['queue_key' => (string) $key, 'group' => 'all'])),
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $snapshot */
    private function snapshotProtocol(array $snapshot): string
    {
        $protocol = (string) ($snapshot['protocol'] ?? 'unavailable');
        if (!in_array($protocol, ['complete', 'partial', 'authoritative_empty', 'unavailable'], true)) {
            return 'unavailable';
        }
        $measured = $this->clock->timestamp((string) ($snapshot['measured_at'] ?? ''));
        if ($measured === null || $measured <= 0) {
            return 'unavailable';
        }
        if ($protocol !== 'unavailable' && $measured < time() - 180) {
            return 'partial';
        }
        return $protocol;
    }

    /** @param array<string,mixed> $queues */
    private function sumKnownQueueValues(array $queues, string $field): ?int
    {
        $sum = 0;
        $known = false;
        foreach ($queues as $queue) {
            if (!is_array($queue) || !is_numeric($queue[$field] ?? null)) {
                continue;
            }
            $known = true;
            $sum += max(0, (int) $queue[$field]);
        }
        return $known ? $sum : null;
    }

    public function snapshotState(): string
    {
        return $this->snapshotState;
    }

    /** @return list<array<string,mixed>> */
    private function buildTasks(): array
    {
        if (!(new SchemaInspectorService())->hasTable('cron_task_state')) {
            $this->snapshotState = 'unavailable';
            return [];
        }
        $rows = Database::connectionFresh()->query(
            'SELECT task_key,status,next_run_at,last_started_at,last_finished_at,last_run_token,
                    last_duration_ms,last_processed,last_errors,last_work_count,oldest_due_at,last_work_observed_at,
                    measurement_state,last_eligible_count,last_total_pending,last_waiting_schedule,last_running_count,last_attention_count,last_observation_error_at,
                    last_batch_started,last_batch_completed,last_batch_deferred,last_batch_remote_calls,
                    last_selection_reason,last_error_message,updated_at
             FROM cron_task_state
             ORDER BY priority ASC,task_key ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $campaign = $this->activeCampaignSummary();
        $recentMetrics = $this->queueMetricsLastHour();
        $issueCounts = $this->queueIssueCounts();
        $latestBatches = $this->latestBatchMetrics();
        $safety = (new SystemSafetyStatusService())->status();
        $automationStopped = (string) ($safety['automation'] ?? 'unknown') === 'stopped';
        $apiStopped = (string) ($safety['api'] ?? 'unknown') === 'stopped';
        $result = [];
        foreach ($rows as $row) {
            $key = (string) ($row['task_key'] ?? '');
            $definition = $this->definitions[$key] ?? ['lane' => 'normal', 'api' => true];
            $running = (string) ($row['status'] ?? '') === 'running'
                && !empty($row['last_started_at'])
                && (($this->clock->timestamp((string) $row['last_started_at']) ?? 0) >= time() - 180);
            $due = $this->clock->isDue(isset($row['next_run_at']) ? (string) $row['next_run_at'] : null);
            $state = match (true) {
                $running => 'running',
                (string) ($row['status'] ?? '') === 'error' => 'action_required',
                !$due => 'waiting_schedule',
                (string) ($row['status'] ?? '') === 'deferred' => 'ready',
                default => 'ready',
            };
            $measurementState = (string) ($row['measurement_state'] ?? 'complete');
            if ($measurementState === 'complete'
                && (!$this->hourMetricsAvailable || !$this->issueMetricsAvailable || !$this->batchMetricsAvailable)) {
                $measurementState = 'partial';
            }
            $pending = max(0, (int) ($row['last_total_pending'] ?? $row['last_work_count'] ?? 0));
            $eligibleNow = max(0, (int) ($row['last_eligible_count'] ?? $pending));
            $waitingSchedule = max(0, (int) ($row['last_waiting_schedule'] ?? max(0, $pending - $eligibleNow)));
            $measuredRunning = max(0, (int) ($row['last_running_count'] ?? 0));
            $measuredAttention = max(0, (int) ($row['last_attention_count'] ?? 0));
            if ($key === 'manual_campaign' && $campaign !== null) {
                $pending = (int) $campaign['pending'];
                $row['last_finished_at'] = $campaign['last_started_at'] ?? $row['last_finished_at'];
                $row['last_selection_reason'] = $campaign['last_result'] ?? $row['last_selection_reason'];
                $row['next_run_at'] = $campaign['next_at'] ?? $row['next_run_at'];
                $state = (string) $campaign['state'];
                // La campaña puede sustituir la próxima oportunidad persistida
                // del task. La etiqueta debe calcularse con ese valor final.
                $due = $this->clock->isDue(isset($row['next_run_at']) ? (string) $row['next_run_at'] : null);
            }
            $issues = max($measuredAttention, (int) ($issueCounts[$key] ?? ($key === 'manual_campaign' && $campaign !== null
                ? ($campaign['failed'] ?? 0)
                : 0)));
            $isRemote = !empty($definition['api']);
            $unitSingular = (string) ($definition['unit_singular'] ?? 'recurso');
            $unitPlural = (string) ($definition['unit_plural'] ?? 'recursos');
            $unit = $pending === 1 ? $unitSingular : $unitPlural;
            $batchLimit = isset($definition['batch_limit']) ? (int) $definition['batch_limit'] : null;
            $batchConfigured = isset($definition['batch_configured']) ? (int) $definition['batch_configured'] : null;
            $batchEffective = isset($definition['batch_effective']) ? (int) $definition['batch_effective'] : null;
            $batchReason = (string) ($definition['batch_limit_reason'] ?? 'fixed_safe_limit');
            $lastBatch = $latestBatches[$key] ?? [];
            $batchExecuted = $this->batchMetricsAvailable && isset($lastBatch['executed'])
                ? max(0, (int) $lastBatch['executed'])
                : null;
            $lastProcessed = max(0, (int) ($row['last_batch_completed'] ?? $row['last_processed'] ?? 0));
            $lastDeferred = max(0, (int) ($row['last_batch_deferred'] ?? 0));
            $lastRemote = max(0, (int) ($row['last_batch_remote_calls'] ?? 0));
            $hour = $recentMetrics[$key] ?? [
                'finalized' => 0, 'remote' => 0, 'known_responses' => 0,
                'resources_received' => 0, 'newly_discovered' => null,
                'deduplicated' => null, 'deferred' => 0,
            ];
            $hourlyFinalized = max(0, (int) $hour['finalized']);
            $etaHours = $pending > 0 && $hourlyFinalized > 0 ? round($pending / $hourlyFinalized, 1) : null;
            $state = match (true) {
                $automationStopped => 'waiting_automation',
                $isRemote && $apiStopped => 'waiting_api',
                $measurementState === 'unavailable' => 'unknown',
                $running && $issues > 0 => 'advancing_with_issues',
                $pending > 0 && $issues > 0 => 'advancing_with_issues',
                $pending === 0 && $issues > 0 => 'action_required',
                $pending === 0 && !in_array($state, ['running', 'action_required'], true) => 'empty',
                default => $state,
            };
            $stateAvailable = $this->issueMetricsAvailable
                || in_array($state, ['waiting_automation', 'waiting_api', 'running'], true);
            $result[] = [
                'key' => $key,
                'label' => $this->label($key, $campaign),
                'lane' => (string) ($definition['lane'] ?? 'normal'),
                'remote' => $isRemote,
                'pending' => $pending,
                'eligible_now' => $eligibleNow,
                'waiting_schedule' => $waitingSchedule,
                'running_count' => max($measuredRunning, $running ? 1 : 0),
                'measurement_state' => $measurementState,
                'measurement_error_at' => $row['last_observation_error_at'] ?? null,
                'attention_count' => $this->issueMetricsAvailable ? $issues : null,
                'pending_label' => $measurementState === 'partial'
                    ? number_format($eligibleNow, 0, ',', '.') . ' ' . ($eligibleNow === 1 ? $unitSingular : $unitPlural) . ' elegibles; total pendiente no disponible'
                    : number_format($pending, 0, ',', '.') . ' ' . $unit . ' por atender',
                'unit_singular' => $unitSingular,
                'unit_plural' => $unitPlural,
                'batch_limit' => $batchLimit,
                'batch_label' => (string) ($definition['batch_label'] ?? 'Límite propio de la función'),
                'batch_configured' => $batchConfigured,
                'batch_effective' => $batchEffective,
                'batch_executed' => $batchExecuted,
                'batch_limit_reason' => $batchReason,
                'batch_metrics_state' => $this->batchMetricsAvailable ? 'complete' : 'unavailable',
                'issue_metrics_state' => $this->issueMetricsAvailable ? 'complete' : 'unavailable',
                'batch_summary_label' => $this->batchMetricsAvailable
                    ? $this->batchSummaryLabel($batchConfigured, $batchEffective, $batchExecuted, $batchReason)
                    : null,
                'minimum_batches' => $batchLimit && $pending > 0 ? (int) ceil($pending / $batchLimit) : null,
                'hour_metrics_state' => $this->hourMetricsAvailable ? 'complete' : 'unavailable',
                'finalized_last_hour' => $this->hourMetricsAvailable ? $hourlyFinalized : null,
                'remote_calls_last_hour' => $this->hourMetricsAvailable ? max(0, (int) $hour['remote']) : null,
                'known_responses_last_hour' => $this->hourMetricsAvailable ? max(0, (int) $hour['known_responses']) : null,
                'resources_received_last_hour' => $this->hourMetricsAvailable ? max(0, (int) $hour['resources_received']) : null,
                'newly_discovered_last_hour' => $this->hourMetricsAvailable && $hour['newly_discovered'] !== null
                    ? max(0, (int) $hour['newly_discovered']) : null,
                'deduplicated_last_hour' => $this->hourMetricsAvailable && $hour['deduplicated'] !== null
                    ? max(0, (int) $hour['deduplicated']) : null,
                'deferred_last_hour' => $this->hourMetricsAvailable ? max(0, (int) $hour['deferred']) : null,
                'eta_hours' => $etaHours,
                'eta_label' => $pending === 0
                    ? 'Sin pendientes'
                    : ($etaHours !== null ? number_format($etaHours, 1, ',', '.') . ' h al ritmo observado' : 'Todavía no se puede estimar'),
                'observed_at' => $row['last_work_observed_at'] ?? $row['updated_at'] ?? null,
                'observed_label' => $this->clock->toBogota($row['last_work_observed_at'] ?? $row['updated_at'] ?? null),
                'oldest_due_at' => $row['oldest_due_at'] ?? null,
                'state' => $stateAvailable ? $state : null,
                'state_label' => $stateAvailable ? $this->stateLabel($state) : null,
                'last_action_at' => $row['last_finished_at'] ?? $row['last_started_at'] ?? null,
                'last_action_label' => $this->clock->toBogota($row['last_finished_at'] ?? $row['last_started_at'] ?? null),
                'last_processed' => $lastProcessed,
                'last_batch_label' => $lastProcessed > 0
                    ? $lastProcessed . ' ' . ($lastProcessed === 1 ? $unitSingular : $unitPlural) . ' en el último lote'
                    : ($lastDeferred > 0 ? $lastDeferred . ' aplazado(s); no terminó recursos' : 'El último turno no terminó recursos'),
                'last_batch_remote_calls' => $lastRemote,
                'last_result' => $this->reasonLabel((string) ($row['last_selection_reason'] ?? '')),
                'next_at' => $row['next_run_at'] ?? null,
                'next_label' => $due ? 'Ahora' : ($this->clock->toBogota($row['next_run_at'] ?? null) ?? 'Por comprobar'),
                'error' => !empty($row['last_error_message']) ? Logger::redactString((string) $row['last_error_message']) : null,
                'url' => $key === 'notification_spool'
                    ? null
                    : ($key === 'manual_campaign' && $campaign !== null
                    ? InternalUrl::to('/settings/manual-processing/session?id=' . (int) $campaign['id'])
                    : InternalUrl::to('/settings/cron/queue?' . http_build_query(['queue_key' => $key, 'group' => 'all']))),
                'detail_message' => $key === 'notification_spool'
                    ? 'Entrada temporal en archivos; no existe una lista de filas navegable todavía.'
                    : null,
            ];
        }
        usort($result, static fn (array $a, array $b): int =>
            ((in_array($a['state'], ['running', 'advancing_with_issues'], true) ? 0 : ($a['state'] === 'action_required' ? 1 : 2))
                <=> (in_array($b['state'], ['running', 'advancing_with_issues'], true) ? 0 : ($b['state'] === 'action_required' ? 1 : 2)))
            ?: ((int) $b['pending'] <=> (int) $a['pending'])
        );
        return $result;
    }

    /** @return array<string,mixed>|null */
    public function run(string $token): ?array
    {
        (new CronOperationalAccessScope())->assertGlobal();
        if ($token === '' || strlen($token) > 100 || !(new SchemaInspectorService())->hasTable('system_work_queue_runs')) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare('SELECT * FROM system_work_queue_runs WHERE run_token=? LIMIT 1');
        $stmt->execute([$token]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($run) ? $this->envelope($this->presentRun($run, $this->runItems((int) $run['id']))) : null;
    }

    /** @return array<string,mixed>|null */
    private function latestRun(bool $finished): ?array
    {
        if (!(new SchemaInspectorService())->hasTable('system_work_queue_runs')) {
            return null;
        }
        $where = $finished
            ? 'origin="scheduled_cli" AND finished_at IS NOT NULL AND status<>"running"'
            : 'origin="scheduled_cli" AND finished_at IS NULL AND status="running" '
                . 'AND started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 SECOND)';
        $row = Database::connectionFresh()->query(
            'SELECT * FROM system_work_queue_runs WHERE ' . $where . ' ORDER BY started_at DESC,id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function runItems(int $runId): array
    {
        if ($runId < 1) {
            return [];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM system_work_queue_run_items WHERE work_queue_run_id=? ORDER BY position_no ASC,id ASC'
        );
        $stmt->execute([$runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $run @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function presentRun(array $run, array $items): array
    {
        return [
            'token' => (string) ($run['run_token'] ?? ''),
            'status' => (string) ($run['status'] ?? ''),
            'started_at' => $run['started_at'] ?? null,
            'started_label' => $this->clock->toBogota($run['started_at'] ?? null),
            'finished_at' => $run['finished_at'] ?? null,
            'finished_label' => $this->clock->toBogota($run['finished_at'] ?? null),
            'candidates' => (int) ($run['candidate_count'] ?? $run['selected_count'] ?? 0),
            'selected' => (int) ($run['selected_count'] ?? 0),
            'started' => (int) ($run['started_count'] ?? 0),
            'inspected' => (int) ($run['inspected_count'] ?? 0),
            'deferred' => (int) ($run['deferred_count'] ?? 0),
            'attempted_remote_calls' => (int) ($run['attempted_remote_call_count'] ?? $run['remote_call_count'] ?? $run['api_calls_used'] ?? 0),
            'remote_calls' => (int) ($run['remote_call_count'] ?? $run['api_calls_used'] ?? 0),
            'blocked_remote_calls' => (int) ($run['blocked_remote_call_count'] ?? 0),
            'checkpoint_approved' => (int) ($run['checkpoint_approved_count'] ?? 0),
            'completed' => (int) ($run['completed_count'] ?? 0),
            'failed' => (int) ($run['failed_count'] ?? 0),
            'not_started' => (int) ($run['not_started_count'] ?? 0),
            'duration_ms' => (int) ($run['duration_ms'] ?? 0),
            'items' => array_map(fn (array $item): array => $this->presentRunItem($item), $items),
        ];
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function presentRunItem(array $item): array
    {
        $key = (string) ($item['queue_key'] ?? '');
        return [
            'queue' => $key,
            'label' => $this->label($key, null),
            'lane' => (string) ($item['lane'] ?? ''),
            'result' => (string) ($item['result'] ?? ''),
            'execution_result' => (string) ($item['execution_result'] ?? ''),
            'selection_reason' => $this->reasonLabel((string) ($item['selection_reason'] ?? '')),
            'attempted_remote_calls' => (int) ($item['attempted_remote_calls'] ?? $item['actual_api_calls'] ?? 0),
            'remote_calls' => (int) ($item['actual_api_calls'] ?? 0),
            'known_responses' => (int) ($item['known_response_count'] ?? 0),
            'resources_received' => (int) ($item['resources_received_count'] ?? 0),
            'newly_discovered' => isset($item['newly_discovered_count']) ? (int) $item['newly_discovered_count'] : null,
            'deduplicated' => isset($item['deduplicated_count']) ? (int) $item['deduplicated_count'] : null,
            'blocked_remote_calls' => (int) ($item['blocked_remote_calls'] ?? 0),
            'inspected' => (int) ($item['inspected_count'] ?? 0),
            'deferred' => (int) ($item['deferred_count'] ?? 0),
            'checkpoint_approved' => (int) ($item['checkpoint_approved_count'] ?? 0),
            'completed' => (int) ($item['completed_count'] ?? ((string) ($item['result'] ?? '') === 'completed' ? 1 : 0)),
            'backlog_before' => isset($item['backlog_before']) ? (int) $item['backlog_before'] : null,
            'backlog_after' => isset($item['backlog_after']) ? (int) $item['backlog_after'] : null,
            'backlog_after_state' => (string) ($item['backlog_after_state'] ?? 'unavailable'),
            'backlog_after_measured_at' => $item['backlog_after_measured_at'] ?? null,
            'campaign_id' => isset($item['campaign_id']) ? (int) $item['campaign_id'] : null,
            'campaign_item_id' => isset($item['campaign_item_id']) ? (int) $item['campaign_item_id'] : null,
            'started_label' => $this->clock->toBogota($item['started_at'] ?? null),
            'finished_label' => $this->clock->toBogota($item['finished_at'] ?? null),
            'next_label' => $this->clock->toBogota($item['next_opportunity_at'] ?? null),
            'message' => (string) ($item['safe_message'] ?? ''),
        ];
    }

    /** @return array<string,mixed>|null */
    private function activeCampaignSummary(): ?array
    {
        if (!(new SchemaInspectorService())->hasTable('manual_campaigns')) {
            return null;
        }
        $scope = new BusinessScopeContext();
        $accountIds = $scope->accountIds((int) (Auth::id() ?? 0));
        $companyIds = $scope->companyIds((int) (Auth::id() ?? 0));
        if ($accountIds === [] && $companyIds === []) {
            return null;
        }
        $scopeSql = [];
        $scopeParams = [];
        if ($accountIds !== []) {
            $scopeSql[] = 'i.meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            array_push($scopeParams, ...$accountIds);
        }
        if ($companyIds !== []) {
            $scopeSql[] = '(i.meli_account_id IS NULL AND i.company_id IN ('
                . implode(',', array_fill(0, count($companyIds), '?')) . '))';
            array_push($scopeParams, ...$companyIds);
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT c.id,c.status,c.last_scheduler_started_at,c.last_scheduler_result,c.next_action_at,
                    SUM(i.status IN ("pending","waiting","retry","running")) pending,
                    SUM(i.status="running" AND i.lease_expires_at>UTC_TIMESTAMP(3)) running
             FROM manual_campaigns c
             JOIN manual_campaign_items i ON i.manual_campaign_id=c.id
             WHERE c.execution_mode="directed_cli" AND c.status IN ("active","pausing","paused","finishing")
               AND (' . implode(' OR ', $scopeSql) . ')
             GROUP BY c.id
             ORDER BY c.id ASC LIMIT 1'
        );
        $stmt->execute($scopeParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $state = (int) ($row['running'] ?? 0) > 0
            ? 'running'
            : ($this->clock->isDue($row['next_action_at'] ?? null) ? 'ready' : 'waiting_schedule');
        return [
            'id' => (int) $row['id'],
            'pending' => (int) $row['pending'],
            'state' => $state,
            'last_started_at' => $row['last_scheduler_started_at'] ?? null,
            'last_result' => $row['last_scheduler_result'] ?? null,
            'next_at' => $row['next_action_at'] ?? null,
        ];
    }

    private function label(string $key, ?array $campaign): string
    {
        return match ($key) {
            'notification_spool' => 'Recepción local de webhooks',
            'notification_backfill' => 'Normalización de notificaciones',
            'notification_fallback' => 'Ventas y notificaciones urgentes',
            'orders_sync' => 'Ventas nuevas',
            'manual_campaign' => $campaign ? 'Campaña #' . (int) $campaign['id'] : 'Campaña dirigida',
            'order_enrichment' => 'Enriquecimiento de órdenes',
            'questions' => 'Preguntas',
            'financial_recalc' => 'Recálculo financiero',
            'sale_financial_reconciliation' => 'Conciliación financiera de ventas',
            'sales_repair' => 'Reparación de ventas',
            'sale_pack_reconciliation' => 'Reconstrucción de packs',
            'sales_audit' => 'Control de ventas',
            'sales_fiscal' => 'Preparación fiscal',
            'catalog_descriptions' => 'Descripciones',
            'items_sync' => 'Productos',
            'module_jobs' => 'Módulos',
            'recurring_sync' => 'Sincronizaciones programadas',
            'order_date_repair' => 'Corrección de fechas',
            'operational_maintenance' => 'Mantenimiento local',
            'monthly_report_maintenance' => 'Reportes mensuales',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'running' => 'Procesando ahora',
            'ready' => 'Lista',
            'advancing_with_issues' => 'Avanzando con incidencias',
            'waiting_schedule' => 'Programada',
            'waiting_api' => 'Esperando API',
            'waiting_automation' => 'Esperando automatización',
            'waiting_budget' => 'Esperando presupuesto',
            'action_required' => 'Necesita intervención',
            'empty' => 'Sin trabajo listo',
            'unknown' => 'No se pudo medir',
            default => 'Por comprobar',
        };
    }

    /** @return array<string,int> */
    private function queueIssueCounts(): array
    {
        if (!(new SchemaInspectorService())->hasTable('system_work_queue_projection')) {
            $this->issueMetricsAvailable = false;
            $this->markPartial();
            return [];
        }
        try {
            $userId = (int) (Auth::id() ?? 0);
            $companies = (new BusinessScopeContext())->companyIds($userId);
            $accounts = (new BusinessScopeContext())->accountIds($userId);
            if ($userId > 0 && $companies === []) {
                return [];
            }
            $where = ['display_status="error"'];
            $params = [];
            if ($userId > 0) {
                $where[] = '(company_id IS NULL OR company_id IN ('
                    . implode(',', array_fill(0, count($companies), '?')) . '))';
                array_push($params, ...$companies);
                $where[] = $accounts === []
                    ? 'meli_account_id IS NULL'
                    : '(meli_account_id IS NULL OR meli_account_id IN ('
                        . implode(',', array_fill(0, count($accounts), '?')) . '))';
                array_push($params, ...$accounts);
            }
            $stmt = Database::connectionFresh()->prepare(
                'SELECT queue_key,COUNT(*) total FROM system_work_queue_projection WHERE '
                . implode(' AND ', $where) . ' GROUP BY queue_key'
            );
            $stmt->execute($params);
            $result = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(string) $row['queue_key']] = max(0, (int) $row['total']);
            }
            return $result;
        } catch (Throwable) {
            $this->issueMetricsAvailable = false;
            $this->markPartial();
            return [];
        }
    }

    /** @return array<string,array{configured:?int,effective:?int,executed:int,limit_reason:string}> */
    private function latestBatchMetrics(): array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_work_queue_run_items')) {
                $this->batchMetricsAvailable = false;
                $this->markPartial();
                return [];
            }
            $rows = Database::connectionFresh()->query(
                'SELECT item.queue_key,item.batch_configured,item.batch_effective,item.batch_executed,item.batch_limit_reason
                 FROM system_work_queue_run_items item
                 INNER JOIN (
                    SELECT queue_key,MAX(id) latest_id
                    FROM system_work_queue_run_items
                    WHERE finished_at IS NOT NULL
                    GROUP BY queue_key
                 ) latest ON latest.latest_id=item.id'
            )->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row['queue_key']] = [
                    'configured' => $row['batch_configured'] !== null ? (int) $row['batch_configured'] : null,
                    'effective' => $row['batch_effective'] !== null ? (int) $row['batch_effective'] : null,
                    'executed' => max(0, (int) ($row['batch_executed'] ?? 0)),
                    'limit_reason' => (string) ($row['batch_limit_reason'] ?? ''),
                ];
            }
            return $result;
        } catch (Throwable) {
            $this->batchMetricsAvailable = false;
            $this->markPartial();
            return [];
        }
    }

    private function batchSummaryLabel(?int $configured, ?int $effective, ?int $executed, string $reason): string
    {
        if ($effective === null) {
            return 'El callback avanza dentro de su ventana segura y conserva checkpoint.';
        }
        $parts = ['Lote permitido: ' . $effective];
        if ($configured !== null && $configured !== $effective) {
            $parts[] = 'solicitado: ' . $configured;
        }
        $parts[] = $executed === null ? 'último lote: por comprobar' : 'último lote: ' . $executed;
        if ($reason === 'safe_cap') {
            $parts[] = 'tope seguro aplicado';
        }
        return implode(' · ', $parts);
    }

    private function markPartial(): void
    {
        if ($this->snapshotState === 'complete') {
            $this->snapshotState = 'partial';
        }
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'urgent_lane' => 'Prioridad por venta nueva',
            'directed_lane_reserved', 'directed_lane_guaranteed' => 'Turno reservado para campaña',
            'directed_debt_repayment' => 'Turno garantizado por espera del ciclo anterior',
            'directed_debt_accrued' => 'Campaña prioritaria para el siguiente ciclo',
            'local_capacity' => 'Capacidad local disponible',
            'normal_api_capacity' => 'Turno API normal',
            'not_started_deadline' => 'Seleccionada, pero no comenzó por cierre seguro',
            'no_due_work' => 'Sin recursos listos',
            'candidate' => 'Pendiente de siguiente ciclo',
            '' => 'Sin acción reciente',
            default => str_replace('_', ' ', $reason),
        };
    }

    /** @param list<array<string,mixed>> $tasks @return array<string,mixed> */
    private function workloadSnapshot(array $tasks): array
    {
        $backlogTasks = array_values(array_filter(
            $tasks,
            static fn(array $row): bool => CronBacklogSnapshotService::countsTowardBacklog((string) ($row['key'] ?? ''))
        ));
        $missingQueues = count(array_filter(
            $backlogTasks,
            static fn(array $row): bool => (string) ($row['measurement_state'] ?? 'unavailable') !== 'complete'
        ));
        $measuredQueues = max(0, count($backlogTasks) - $missingQueues);
        $pending = array_sum(array_map(static fn(array $row): int => max(0, (int) ($row['pending'] ?? 0)), $backlogTasks));
        $remotePending = array_sum(array_map(
            static fn(array $row): int => !empty($row['remote']) ? max(0, (int) ($row['pending'] ?? 0)) : 0,
            $backlogTasks
        ));
        $hourMetricsComplete = array_filter(
            $backlogTasks,
            static fn(array $row): bool => (string) ($row['hour_metrics_state'] ?? 'complete') !== 'complete'
        ) === [];
        $finalized = $hourMetricsComplete
            ? array_sum(array_map(static fn(array $row): int => max(0, (int) ($row['finalized_last_hour'] ?? 0)), $backlogTasks))
            : null;
        $remote = $hourMetricsComplete
            ? array_sum(array_map(static fn(array $row): int => max(0, (int) ($row['remote_calls_last_hour'] ?? 0)), $backlogTasks))
            : null;
        $deferred = $hourMetricsComplete
            ? array_sum(array_map(static fn(array $row): int => max(0, (int) ($row['deferred_last_hour'] ?? 0)), $backlogTasks))
            : null;
        $backlogService = new CronBacklogSnapshotService();
        $samples = $backlogService->recentTotals(2);
        $latest = $samples[0] ?? null;
        $previous = $samples[1] ?? null;
        $comparable = is_array($latest) && is_array($previous)
            && $latest['measurement_state'] === 'complete'
            && $previous['measurement_state'] === 'complete'
            && $latest['coverage_signature'] === $previous['coverage_signature']
            && (int) $latest['measured_queues'] > 0
            && (int) $latest['measured_queues'] === (int) $previous['measured_queues'];
        $delta = $comparable
            ? (int) $latest['total_pending'] - (int) $previous['total_pending']
            : null;
        $lastCycleTrend = $comparable
            ? CronBacklogSnapshotService::trend((int) $previous['total_pending'], (int) $latest['total_pending'])
            : ($pending === 0 ? 'empty' : 'unknown');
        $windows = $backlogService->trendWindows();
        $trend15 = (string) ($windows['minutes_15']['trend'] ?? 'unknown');
        $trend60 = (string) ($windows['minutes_60']['trend'] ?? 'unknown');
        $trend = $pending === 0
            ? 'empty'
            : ($trend15 === 'draining' && $trend60 === 'draining'
                ? 'draining'
                : (($trend15 === 'growing' || $trend60 === 'growing') ? 'growing'
                    : (($trend15 === 'stable' && $trend60 === 'stable') ? 'stable' : 'unknown')));
        return [
            'pending' => $pending,
            'remote_pending' => $remotePending,
            'coverage_state' => $missingQueues === 0 ? 'complete' : ($measuredQueues > 0 ? 'partial' : 'unavailable'),
            'measured_queues' => $measuredQueues,
            'missing_queues' => $missingQueues,
            'finalized_last_hour' => $finalized,
            'remote_calls_last_hour' => $remote,
            'deferred_last_hour' => $deferred,
            'hour_metrics_state' => $hourMetricsComplete ? 'complete' : 'unavailable',
            'trend' => $trend,
            'last_cycle_trend' => $lastCycleTrend,
            'backlog_delta' => $delta,
            'trend_15m' => $windows['minutes_15'],
            'trend_60m' => $windows['minutes_60'],
            'previous_pending' => $latest['previous_pending'] ?? null,
            'newly_discovered' => $latest['newly_discovered'] ?? null,
            'deduplicated' => $latest['deduplicated'] ?? null,
            'finalized' => $latest['finalized'] ?? null,
            'current_pending' => $latest['current_pending'] ?? $pending,
            'http_dispatched' => $latest['http_dispatched'] ?? null,
            'known_responses' => $latest['known_responses'] ?? null,
            'resources_received' => $latest['resources_received'] ?? null,
            'equation_state' => empty($latest)
                ? 'unavailable'
                : ((int) ($latest['equation_partial_queues'] ?? 0) === 0
                    && (int) ($latest['equation_complete_queues'] ?? 0) === (int) ($latest['measured_queues'] ?? -1)
                    ? 'complete'
                    : ((int) ($latest['equation_partial_queues'] ?? 0) > 0 ? 'partial' : 'unavailable')),
            'measured_at' => $latest['measured_at'] ?? null,
            'trend_label' => match ($trend) {
                'empty' => 'Sin trabajo pendiente',
                'draining' => 'La cola está avanzando',
                'growing' => 'El trabajo pendiente está creciendo',
                'stable' => 'El trabajo pendiente se mantiene estable',
                default => 'Todavía no hay evidencia suficiente',
            },
        ];
    }

    /**
     * Usa el mismo selector persistente del CLI. La vista no inventa un orden
     * a partir de la tabla ya presentada ni crea/reclama trabajo.
     *
     * @param list<array<string,mixed>> $taskRows
     * @return list<array<string,mixed>>
     */
    private function nextTasksFromRuntimeSelector(array $taskRows): array
    {
        $presented = [];
        foreach ($taskRows as $row) {
            $presented[(string) ($row['key'] ?? '')] = $row;
        }
        $definitions = [];
        foreach ($this->definitions as $key => $definition) {
            $row = $presented[$key] ?? null;
            $definitions[] = array_merge($definition, [
                'known' => $row !== null && (string) ($row['measurement_state'] ?? 'complete') !== 'unavailable',
                'work_count' => max(0, (int) ($row['eligible_now'] ?? 0)),
                'total_pending' => max(0, (int) ($row['pending'] ?? 0)),
                'waiting_schedule' => max(0, (int) ($row['waiting_schedule'] ?? 0)),
                'oldest_due_at' => $row['oldest_due_at'] ?? null,
            ]);
        }
        try {
            $settings = new AppSettingsService();
            $maxTasks = max(1, min(10, $settings->int('cron.max_tasks_per_run', 4)));
            $selected = (new CronTaskStateService())->preview($definitions, $maxTasks, $maxTasks);
            $result = [];
            foreach ($selected as $task) {
                $key = (string) ($task['key'] ?? '');
                if (isset($presented[$key])) {
                    $result[] = $presented[$key];
                }
            }
            return $result;
        } catch (Throwable) {
            // Una vista parcial sigue siendo honesta: no predice un orden que
            // el selector real no confirmó.
            $this->selectorAvailable = false;
            $this->markPartial();
            return [];
        }
    }

    /** @return array<string,array<string,int|null>> */
    private function queueMetricsLastHour(): array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_work_queue_run_items')) {
                $this->hourMetricsAvailable = false;
                $this->markPartial();
                return [];
            }
            $rows = Database::connectionFresh()->query(
                'SELECT queue_key,
                        COALESCE(SUM(completed_count),0) finalized,
                        COALESCE(SUM(actual_api_calls),0) remote_calls,
                        COALESCE(SUM(known_response_count),0) known_responses,
                        COALESCE(SUM(resources_received_count),0) resources_received,
                        SUM(newly_discovered_count) newly_discovered,
                        SUM(deduplicated_count) deduplicated,
                        COALESCE(SUM(deferred_count),0) deferred
                 FROM system_work_queue_run_items
                 WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)
                 GROUP BY queue_key'
            )->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row['queue_key']] = [
                    'finalized' => max(0, (int) $row['finalized']),
                    'remote' => max(0, (int) $row['remote_calls']),
                    'known_responses' => max(0, (int) $row['known_responses']),
                    'resources_received' => max(0, (int) $row['resources_received']),
                    'newly_discovered' => $row['newly_discovered'] !== null ? max(0, (int) $row['newly_discovered']) : null,
                    'deduplicated' => $row['deduplicated'] !== null ? max(0, (int) $row['deduplicated']) : null,
                    'deferred' => max(0, (int) $row['deferred']),
                ];
            }
            return $result;
        } catch (Throwable) {
            $this->hourMetricsAvailable = false;
            $this->markPartial();
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function recentRuns(int $limit): array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_work_queue_runs')) {
                $this->historyAvailable = false;
                $this->markPartial();
                return [];
            }
            $stmt = Database::connectionFresh()->prepare(
                'SELECT * FROM system_work_queue_runs
                 WHERE origin="scheduled_cli" AND finished_at IS NOT NULL
                 ORDER BY finished_at DESC,id DESC LIMIT :limit'
            );
            $stmt->bindValue(':limit', max(1, min(20, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return array_map(
                fn(array $run): array => $this->presentRun($run, []),
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        } catch (Throwable) {
            $this->historyAvailable = false;
            $this->markPartial();
            return [];
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function envelope(array $payload): array
    {
        $manifest = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/resources/runtime-manifest.json'), true);
        $state = $this->snapshotState;
        return array_merge([
            'ok' => true,
            'snapshot_state' => $state,
            'authoritative' => in_array($state, ['complete', 'authoritative_empty'], true),
            'version' => is_array($manifest) ? (string) ($manifest['version'] ?? '') : '',
            'build' => is_array($manifest) ? (string) ($manifest['build_id'] ?? '') : '',
            'generated_at' => gmdate('c'),
        ], $payload);
    }

    private function readCacheKey(): string
    {
        $safety = (new SystemSafetyStatusService())->status();
        return implode(':', [
            AppVersionService::fileVersion(),
            (string) (Auth::id() ?? 0),
            (string) Auth::role(),
            (string) ($safety['api'] ?? 'unknown'),
            (string) ($safety['automation'] ?? 'unknown'),
            (string) ($safety['changed_at'] ?? ''),
        ]);
    }
}
