<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Selecciona tareas vencidas en rotación persistente.
 *
 * No reemplaza las colas de negocio: solo evita que un cron corto intente
 * atenderlas todas en cada ejecución.
 */
final class CronTaskStateService
{
    public function available(): bool
    {
        return (new SchemaInspectorService())->hasTable('cron_task_state');
    }

    /**
     * @param list<array{key:string,api:bool,interval:int,priority:int,lane?:string,known?:bool,measurement_state?:string,work_count?:int,eligible_count?:int,total_pending?:int,waiting_schedule?:int,running_count?:int,attention_count?:int,oldest_due_at?:?string}> $definitions
     * @return list<array{key:string,api:bool,interval:int,priority:int,lane?:string,known?:bool,work_count?:int,eligible_count?:int,total_pending?:int,waiting_schedule?:int,running_count?:int,attention_count?:int,oldest_due_at?:?string,selection_reason?:string,_directed_missed_cycles?:int,_last_finished_at?:mixed,_score?:int}>
     */
    public function due(array $definitions, int $maxTasks, int $maxApiTasks): array
    {
        $maxTasks = max(1, min(10, $maxTasks));
        $maxApiTasks = max(0, min($maxTasks, $maxApiTasks));
        if (!$this->available()) {
            return $this->limit($definitions, $maxTasks, $maxApiTasks);
        }

        $pdo = Database::connectionFresh();
        $schema = new SchemaInspectorService();
        $hasDirectedDebt = $schema->hasColumn('cron_task_state', 'directed_missed_cycles')
            && $schema->hasColumn('cron_task_state', 'last_directed_miss_at');
        $insertParts = [];
        $insertParams = [];
        foreach ($definitions as $definition) {
            $insertParts[] = '(?,?,?,"ready",UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())';
            array_push(
                $insertParams,
                (string) $definition['key'],
                (int) $definition['priority'],
                !empty($definition['api']) ? 1 : 0
            );
        }
        if ($insertParts !== []) {
            $pdo->prepare(
                'INSERT INTO cron_task_state
                 (task_key,priority,is_api_task,status,next_run_at,created_at,updated_at)
                 VALUES ' . implode(',', $insertParts) . '
                 ON DUPLICATE KEY UPDATE
                    priority=VALUES(priority),
                    is_api_task=VALUES(is_api_task),
                    updated_at=UTC_TIMESTAMP()'
            )->execute($insertParams);

            $rows = [];
            $stateParams = [];
            foreach ($definitions as $definition) {
                $known = !empty($definition['known']);
                $measurementState = $known && (string) ($definition['measurement_state'] ?? 'complete') === 'partial'
                    ? 'partial'
                    : ($known ? 'complete' : 'unavailable');
                $rows[] = 'SELECT ? task_key,? known_state,? measurement_state,? eligible_count,? total_pending,? waiting_schedule,? running_count,? attention_count,? oldest_due_at,? reason';
                array_push(
                    $stateParams,
                    (string) $definition['key'],
                    $known ? 1 : 0,
                    $measurementState,
                    max(0, (int) ($definition['eligible_count'] ?? $definition['work_count'] ?? 0)),
                    max(0, (int) ($definition['total_pending'] ?? $definition['work_count'] ?? 0)),
                    max(0, (int) ($definition['waiting_schedule'] ?? 0)),
                    max(0, (int) ($definition['running_count'] ?? 0)),
                    max(0, (int) ($definition['attention_count'] ?? 0)),
                    $definition['oldest_due_at'] ?? null,
                    !$known
                        ? 'measurement_unavailable'
                        : (!empty($definition['known'])
                        && (int) ($definition['work_count'] ?? 0) === 0
                            ? 'no_due_work'
                            : 'candidate')
                );
            }
            $pdo->prepare(
                'UPDATE cron_task_state state
                 JOIN (' . implode(' UNION ALL ', $rows) . ') observed
                   ON observed.task_key=state.task_key
                 SET state.measurement_state=observed.measurement_state,
                     state.last_eligible_count=IF(observed.known_state=1,observed.eligible_count,state.last_eligible_count),
                     state.last_total_pending=IF(observed.known_state=1,observed.total_pending,state.last_total_pending),
                     state.last_waiting_schedule=IF(observed.known_state=1,observed.waiting_schedule,state.last_waiting_schedule),
                     state.last_running_count=IF(observed.known_state=1,observed.running_count,state.last_running_count),
                     state.last_attention_count=IF(observed.known_state=1,observed.attention_count,state.last_attention_count),
                     state.last_work_count=IF(observed.known_state=1,observed.total_pending,state.last_work_count),
                     state.oldest_due_at=IF(observed.known_state=1,observed.oldest_due_at,state.oldest_due_at),
                     state.last_selection_reason=observed.reason,
                     state.last_work_observed_at=IF(observed.known_state=1,UTC_TIMESTAMP(3),state.last_work_observed_at),
                     state.last_observation_error_at=IF(observed.known_state=0,UTC_TIMESTAMP(3),state.last_observation_error_at),
                     state.updated_at=UTC_TIMESTAMP()'
            )->execute($stateParams);
        }

        $keys = array_column($definitions, 'key');
        $directedReadyKeys = array_values(array_map(
            static fn (array $definition): string => (string) $definition['key'],
            array_filter(
                $definitions,
                static fn (array $definition): bool => (string) ($definition['lane'] ?? '') === 'directed'
                    && !empty($definition['known'])
                    && (int) ($definition['work_count'] ?? 0) > 0
            )
        ));
        $quoted = implode(',', array_fill(0, count($keys), '?'));
        $directedClause = $directedReadyKeys !== []
            ? ' OR (status="ready" AND task_key IN (' . implode(',', array_fill(0, count($directedReadyKeys), '?')) . '))'
            : '';
        $stmt = $pdo->prepare(
            'SELECT task_key,last_finished_at,last_started_at,status,'
                . ($hasDirectedDebt ? 'directed_missed_cycles' : '0 directed_missed_cycles') . '
             FROM cron_task_state
             WHERE task_key IN (' . $quoted . ')
               AND status IN ("ready","deferred")
               AND (next_run_at IS NULL OR next_run_at<=UTC_TIMESTAMP()' . $directedClause . ')
             ORDER BY COALESCE(last_finished_at,"1970-01-01") ASC,priority ASC,task_key ASC'
        );
        $stmt->execute(array_merge($keys, $directedReadyKeys));
        $order = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition['key']] = $definition;
        }
        $due = [];
        $coordination = new ExecutionCoordinationService();
        $orderedKeys = array_values(array_map(static fn (array $row): string => (string) $row['task_key'], $order));
        $reservedQueues = $coordination->reservedQueueKeysReadOnly($orderedKeys);
        foreach ($order as $stateRow) {
            $key = (string) $stateRow['task_key'];
            if (isset($byKey[(string) $key])) {
                $candidate = $byKey[(string) $key];
                if (empty($candidate['known'])) {
                    continue;
                }
                if (isset($reservedQueues[(string) $key])) {
                    $pdo->prepare(
                        'UPDATE cron_task_state SET last_selection_reason="reserved_by_manual_session",updated_at=UTC_TIMESTAMP()
                         WHERE task_key=?'
                    )->execute([(string) $key]);
                    continue;
                }
                if ((int) ($candidate['work_count'] ?? 0) === 0) {
                    continue;
                }
                $candidate['_score'] = $this->score($candidate);
                $candidate['_last_finished_at'] = $stateRow['last_finished_at'] ?? null;
                $candidate['_directed_missed_cycles'] = max(0, (int) ($stateRow['directed_missed_cycles'] ?? 0));
                $due[] = $candidate;
            }
        }
        usort($due, static fn (array $a, array $b): int =>
            ((int) $b['_score'] <=> (int) $a['_score'])
            ?: ((int) $a['priority'] <=> (int) $b['priority'])
        );
        $selected = $this->limit($due, $maxTasks, $maxApiTasks);
        if ($hasDirectedDebt) {
            $selectedKeys = array_fill_keys(array_map(
                static fn (array $definition): string => (string) $definition['key'],
                $selected
            ), true);
            foreach ($due as $candidate) {
                if ((string) ($candidate['lane'] ?? '') !== 'directed') {
                    continue;
                }
                $candidateKey = (string) $candidate['key'];
                if ($candidateKey === '' || isset($selectedKeys[$candidateKey])) {
                    continue;
                }
                $pdo->prepare(
                    'UPDATE cron_task_state
                     SET directed_missed_cycles=LEAST(65535,directed_missed_cycles+1),
                         last_directed_miss_at=UTC_TIMESTAMP(3),
                         last_selection_reason="directed_debt_accrued",updated_at=UTC_TIMESTAMP()
                     WHERE task_key=? AND status IN ("ready","deferred")'
                )->execute([$candidateKey]);
            }
        }
        foreach ($selected as &$definition) {
            $definition['selection_reason'] = match ((string) ($definition['lane'] ?? 'normal')) {
                'urgent' => 'urgent_lane',
                'directed' => !empty($definition['_directed_missed_cycles'])
                    ? 'directed_debt_repayment'
                    : 'directed_lane_reserved',
                'local' => 'local_capacity',
                default => 'normal_api_capacity',
            };
        }
        unset($definition);
        return $selected;
    }

    /**
     * Simulación de solo lectura con el mismo selector de carriles del runtime.
     *
     * @param list<array<string,mixed>> $definitions
     * @return list<array<string,mixed>>
     */
    public function preview(array $definitions, int $maxTasks, int $maxApiTasks): array
    {
        $state = [];
        if ($this->available()) {
            $keys = array_values(array_filter(array_map(
                static fn (array $definition): string => (string) ($definition['key'] ?? ''),
                $definitions
            )));
            if ($keys !== []) {
                $stmt = Database::connectionFresh()->prepare(
                    'SELECT task_key,status,next_run_at,last_started_at,last_finished_at,last_selection_reason,'
                        . ((new SchemaInspectorService())->hasColumn('cron_task_state', 'directed_missed_cycles')
                            ? 'directed_missed_cycles'
                            : '0 directed_missed_cycles') . '
                     FROM cron_task_state WHERE task_key IN ('
                    . implode(',', array_fill(0, count($keys), '?')) . ')'
                );
                $stmt->execute($keys);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $state[(string) $row['task_key']] = $row;
                }
            }
        }

        $eligible = [];
        $coordination = new ExecutionCoordinationService();
        $reservedQueues = $coordination->reservedQueueKeysReadOnly(array_map(
            static fn (array $definition): string => (string) ($definition['key'] ?? ''),
            $definitions
        ));
        foreach ($definitions as $definition) {
            $key = (string) ($definition['key'] ?? '');
            $row = $state[$key] ?? [];
            $definition['runtime_state'] = $row;
            if (empty($definition['known'])) {
                $definition['selection_reason'] = 'measurement_unavailable';
                continue;
            }
            if ((int) ($definition['work_count'] ?? 0) === 0) {
                $definition['selection_reason'] = 'no_due_work';
                continue;
            }
            $directedDue = (string) ($definition['lane'] ?? '') === 'directed'
                && (int) ($definition['work_count'] ?? 0) > 0
                && (string) ($row['status'] ?? 'ready') === 'ready';
            if (!$directedDue
                && !empty($row['next_run_at'])
                && !(new SystemDatabaseUtcClock())->isDue((string) $row['next_run_at'])) {
                $definition['selection_reason'] = 'not_yet_eligible';
                continue;
            }
            $runtimeStatus = (string) ($row['status'] ?? 'ready');
            if ($runtimeStatus === 'running') {
                $definition['selection_reason'] = 'already_running';
                continue;
            }
            if (!in_array($runtimeStatus, ['ready', 'deferred'], true)) {
                $definition['selection_reason'] = $runtimeStatus === 'error'
                    ? 'action_required'
                    : 'not_runnable_state';
                continue;
            }
            if (isset($reservedQueues[$key])) {
                $definition['selection_reason'] = 'reserved_by_manual_session';
                continue;
            }
            $definition['_score'] = $this->score($definition);
            $definition['_directed_missed_cycles'] = max(0, (int) ($row['directed_missed_cycles'] ?? 0));
            $definition['selection_reason'] = match ((string) ($definition['lane'] ?? 'normal')) {
                'urgent' => 'urgent_lane',
                'directed' => (int) $definition['_directed_missed_cycles'] > 0
                    ? 'directed_debt_repayment'
                    : 'directed_lane_guaranteed',
                'local' => 'local_capacity',
                default => 'normal_api_capacity',
            };
            $eligible[] = $definition;
        }
        usort($eligible, static fn (array $a, array $b): int =>
            ((int) $b['_score'] <=> (int) $a['_score'])
            ?: ((int) $a['priority'] <=> (int) $b['priority'])
        );
        return $this->limit($eligible, $maxTasks, $maxApiTasks);
    }

    public function claim(string $taskKey, string $runToken, bool $allowEarlyDirected = false): bool
    {
        if (!$this->available()) {
            return true;
        }
        $schema = new SchemaInspectorService();
        $hasDirectedDebt = $schema->hasColumn('cron_task_state', 'directed_missed_cycles')
            && $schema->hasColumn('cron_task_state', 'last_directed_miss_at');
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE cron_task_state
             SET status="running",last_started_at=UTC_TIMESTAMP(),last_run_token=:token,
                 attempts=attempts+1,'
                . ($hasDirectedDebt ? 'directed_missed_cycles=0,' : '') . '
                 updated_at=UTC_TIMESTAMP()
             WHERE task_key=:task
               AND status IN ("ready","deferred")
               AND (next_run_at IS NULL OR next_run_at<=UTC_TIMESTAMP() OR :allow_early=1)'
        );
        $stmt->execute([
            'token' => $runToken,
            'task' => $taskKey,
            'allow_early' => $allowEarlyDirected ? 1 : 0,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function started(string $taskKey, string $runToken, bool $allowEarlyDirected = false): bool
    {
        return $this->claim($taskKey, $runToken, $allowEarlyDirected);
    }

    public function notStarted(string $taskKey, string $reason, bool $directed = false): void
    {
        if (!$this->available()) {
            return;
        }
        $schema = new SchemaInspectorService();
        $hasDirectedDebt = $directed
            && $schema->hasColumn('cron_task_state', 'directed_missed_cycles')
            && $schema->hasColumn('cron_task_state', 'last_directed_miss_at');
        Database::connectionFresh()->prepare(
            'UPDATE cron_task_state SET '
            . ($hasDirectedDebt
                ? 'directed_missed_cycles=LEAST(65535,directed_missed_cycles+1),last_directed_miss_at=UTC_TIMESTAMP(3),'
                : '')
            . 'last_selection_reason=?,updated_at=UTC_TIMESTAMP()
             WHERE task_key=? AND status IN ("ready","deferred")'
        )->execute([mb_substr(Logger::redactString($reason), 0, 120), $taskKey]);
    }

    /** @param array<string,mixed> $result */
    public function finished(string $taskKey, int $intervalSeconds, array $result, string $runToken): bool
    {
        if (!$this->available()) {
            return true;
        }
        if ($runToken === '') {
            return false;
        }
        $errors = max(0, (int) ($result['errors'] ?? 0));
        $outcome = (string) ($result['status'] ?? 'empty');
        $stopReason = strtolower(trim((string) ($result['stop_reason'] ?? '')));
        $systemicFailure = !empty($result['systemic_failure'])
            || in_array($stopReason, [
                'schema', 'schema_error', 'adapter_missing', 'adapter_failure',
                'database', 'database_unavailable', 'integrity', 'integrity_failure',
            ], true);
        // Un recurso aislado que requiere intervención no detiene la función:
        // queda visible en su cola mientras Cron continúa con los demás.
        $taskFailure = $systemicFailure
            && in_array($outcome, ['action_required', 'failed'], true);
        $waiting = CronWorkOutcome::isWaiting($outcome);
        $started = max(0, (int) ($result['started'] ?? (empty($result['not_started']) ? 1 : 0)));
        $notStarted = max(0, (int) ($result['not_started'] ?? ($started === 0 ? 1 : 0)));
        $restoreTaskAttempt = $started === 0 && $notStarted > 0;
        $delay = $waiting ? 60 : max(5, min(86400, $intervalSeconds));
        if ($notStarted > 0 && $started === 0) {
            // Un candidato que no alcanzó a iniciar no debe bloquear un minuto
            // completo la cola. El siguiente ciclo debe poder escoger otro
            // recurso más corto o mostrar el bloqueo exacto con datos frescos.
            $delay = in_array($outcome, ['waiting_source', 'waiting_schedule'], true)
                ? 60
                : 5;
        }
        if (in_array($outcome, ['waiting_rhythm', 'waiting_budget', 'waiting_schedule'], true)
            && !empty($result['next_safe_at'] ?? $result['next_eligible_at'] ?? null)) {
            $safeAt = (new SystemDatabaseUtcClock())->timestamp((string) ($result['next_safe_at'] ?? $result['next_eligible_at']));
            if ($safeAt !== null) {
                $delay = max(5, min(86400, $safeAt - time()));
            }
        }
        if ($errors > 0) {
            $delay = max($delay, min(3600, 60 * $errors));
        }
        $finished = Database::connectionFresh()->prepare(
            'UPDATE cron_task_state
             SET status=:status,last_finished_at=UTC_TIMESTAMP(),
                 next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $delay . ' SECOND),
                 last_duration_ms=:duration,last_processed=:processed,last_errors=:errors,
                 last_batch_completed=:completed,last_batch_deferred=:deferred,
                 last_batch_remote_calls=:remote,last_batch_started=:started,
                 attempts=GREATEST(0,attempts-:restore_attempt),
                 consecutive_failures=CASE WHEN :has_errors=1 THEN consecutive_failures+1 ELSE 0 END,
                 last_error_message=:message,updated_at=UTC_TIMESTAMP()
             WHERE task_key=:task AND status="running" AND last_run_token=:run_token'
        );
        $finished->execute([
            'status' => match (true) {
                $taskFailure => 'error',
                $outcome === 'waiting_rhythm' => 'deferred',
                $outcome === 'waiting_budget' => 'deferred',
                $outcome === 'waiting_api' => 'deferred',
                $outcome === 'waiting_schedule' => 'deferred',
                $outcome === 'waiting_deadline' => 'deferred',
                $outcome === 'waiting_guard' => 'deferred',
                $outcome === 'waiting_lock' => 'deferred',
                $outcome === 'retry_scheduled' => 'deferred',
                $outcome === 'deferred' => 'deferred',
                default => 'ready',
            },
            'duration' => max(0, (int) ($result['duration_ms'] ?? 0)),
            'processed' => max(0, (int) ($result['processed'] ?? 0)),
            'completed' => max(0, (int) ($result['completed'] ?? $result['processed'] ?? 0)),
            'deferred' => max(0, (int) ($result['deferred'] ?? ($waiting ? 1 : 0))),
            'remote' => max(0, (int) ($result['remote_calls'] ?? 0)),
            'started' => $started,
            'restore_attempt' => $restoreTaskAttempt ? 1 : 0,
            'errors' => $errors,
            'has_errors' => $taskFailure ? 1 : 0,
            'message' => $errors > 0
                ? mb_substr(Logger::redactString((string) ($result['message'] ?? 'Error de tarea.')), 0, 500)
                : null,
            'task' => $taskKey,
            'run_token' => $runToken,
        ]);
        return $finished->rowCount() === 1;
    }

    public function recoverAbandoned(int $seconds = 180): int
    {
        if (!$this->available()) {
            return 0;
        }
        try {
            $seconds = max(60, min(3600, $seconds));
            $stmt = Database::connectionFresh()->prepare(
                'UPDATE cron_task_state
                 SET status="ready",next_run_at=UTC_TIMESTAMP(),last_error_message="Ejecución anterior interrumpida; reprogramada.",updated_at=UTC_TIMESTAMP()
                 WHERE status="running" AND last_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $seconds . ' SECOND)'
            );
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param list<array{key:string,api:bool,interval:int,priority:int,lane?:string,known?:bool,work_count?:int,oldest_due_at?:?string,_score?:int,_directed_missed_cycles?:int,_last_finished_at?:mixed}> $items
     * @return list<array{key:string,api:bool,interval:int,priority:int,lane?:string,known?:bool,work_count?:int,oldest_due_at?:?string,_score?:int,_directed_missed_cycles?:int,_last_finished_at?:mixed}>
     */
    private function limit(array $items, int $maxTasks, int $maxApiTasks): array
    {
        return (new CronTaskLaneSelector())->select($items, $maxTasks, $maxApiTasks);
    }

    /** @param array<string,mixed> $item */
    private function score(array $item): int
    {
        $score = max(0, 1000 - ((int) $item['priority'] * 3));
        if (!empty($item['oldest_due_at'])) {
            $timestamp = (new SystemDatabaseUtcClock())->timestamp((string) $item['oldest_due_at']);
            $ageMinutes = $timestamp !== null ? max(0, (int) floor((time() - $timestamp) / 60)) : 0;
            $score += min(10000, $ageMinutes * 20);
            $maxWait = max(1, (new AppSettingsService())->int('cron.order_queue_max_wait_minutes', 10));
            if (in_array((string) $item['key'], ['orders_sync', 'notification_fallback'], true) && $ageMinutes >= $maxWait) {
                $score += 100000;
            }
        }
        return $score;
    }
}
