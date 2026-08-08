<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\InternalUrl;
use PDO;
use Throwable;

final class WorkQueueRunService
{
    public function begin(string $runToken, string $origin): ?int
    {
        if (!(new SchemaInspectorService())->hasTable('system_work_queue_runs')) {
            return null;
        }
        try {
            $pdo = Database::connectionFresh();
            $stmt = $pdo->prepare(
                'INSERT INTO system_work_queue_runs (run_token,origin,status,started_at)
                 VALUES (?,?,"running",UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),status="running"'
            );
            $stmt->execute([$runToken, $origin]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable) {
            return null;
        }
    }

    public function candidates(?int $runId, int $count): void
    {
        if (!$runId) {
            return;
        }
        try {
            Database::connectionFresh()->prepare(
                'UPDATE system_work_queue_runs SET candidate_count=? WHERE id=?'
            )->execute([max(0, $count), $runId]);
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $definition */
    public function selected(?int $runId, array $definition, int $position): void
    {
        if (!$runId) {
            return;
        }
        try {
            $policy = (new CronBatchPolicyService())->policy((string) $definition['key']);
            Database::connectionFresh()->prepare(
                'INSERT INTO system_work_queue_run_items
                 (work_queue_run_id,queue_key,lane,source_table,source_id,result,position_no,selection_reason,
                  backlog_before,batch_limit,batch_configured,batch_effective,batch_limit_reason,work_unit,created_at,updated_at)
                 VALUES (?,?,?,?,?,"selected",?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute([
                $runId,
                (string) $definition['key'],
                (string) ($definition['lane'] ?? (!empty($definition['api']) ? 'normal' : 'local')),
                'cron_task_state',
                (string) $definition['key'],
                $position,
                (string) ($definition['selection_reason'] ?? ''),
                isset($definition['total_pending'])
                    ? max(0, (int) $definition['total_pending'])
                    : (isset($definition['work_count']) ? max(0, (int) $definition['work_count']) : null),
                $policy['effective'],
                $policy['configured'],
                $policy['effective'],
                (string) $policy['limit_reason'],
                $this->workUnit((string) $definition['key']),
            ]);
        } catch (Throwable) {
        }
    }

    public function started(?int $runId, string $queueKey): void
    {
        if (!$runId) {
            return;
        }
        try {
            Database::connectionFresh()->prepare(
                'UPDATE system_work_queue_run_items
                 SET result="started",started_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE work_queue_run_id=? AND queue_key=? AND result="selected"'
            )->execute([$runId, $queueKey]);
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed>|null $definition */
    public function notStarted(
        ?int $runId,
        string $queueKey,
        string $reason,
        ?array $definition = null,
        int $position = 0
    ): void
    {
        if (!$runId) {
            return;
        }
        try {
            $pdo = Database::connectionFresh();
            $stmt = $pdo->prepare(
                'UPDATE system_work_queue_run_items
                 SET result="not_started",execution_result="not_started",safe_message=?,finished_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE work_queue_run_id=? AND queue_key=? AND result="selected"'
            );
            $safeReason = mb_substr(Logger::redactString($reason), 0, 500);
            $stmt->execute([$safeReason, $runId, $queueKey]);
            if ($stmt->rowCount() === 0 && $definition !== null) {
                $policy = (new CronBatchPolicyService())->policy($queueKey);
                $pdo->prepare(
                    'INSERT INTO system_work_queue_run_items
                     (work_queue_run_id,queue_key,lane,source_table,source_id,result,execution_result,position_no,
                      selection_reason,backlog_before,batch_limit,batch_configured,batch_effective,batch_limit_reason,
                      work_unit,safe_message,finished_at,created_at,updated_at)
                     VALUES (?,?,?,?,?,"not_started","not_started",?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))'
                )->execute([
                    $runId,
                    $queueKey,
                    (string) ($definition['lane'] ?? (!empty($definition['api']) ? 'normal' : 'local')),
                    'cron_task_state',
                    $queueKey,
                    max(1, $position),
                    (string) ($definition['selection_reason'] ?? $reason),
                    isset($definition['total_pending']) ? max(0, (int) $definition['total_pending']) : null,
                    $policy['effective'],
                    $policy['configured'],
                    $policy['effective'],
                    (string) $policy['limit_reason'],
                    $this->workUnit($queueKey),
                    $safeReason,
                ]);
            }
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $result */
    public function result(?int $runId, string $queueKey, array $result, ?array $measurement = null): void
    {
        if (!$runId) {
            return;
        }
        try {
            $outcome = (string) ($result['status'] ?? '');
            $completedCount = max(0, (int) ($result['completed'] ?? $result['processed'] ?? 0));
            $inspectedCount = max(0, (int) ($result['inspected'] ?? $result['processed'] ?? 0));
            $deferredCount = max(0, (int) ($result['deferred'] ?? 0));
            $newlyDiscovered = $this->optionalCount($result, ['newly_discovered', 'discovered', 'enqueued']);
            $deduplicated = $this->optionalCount($result, ['deduplicated', 'duplicates']);
            $batchExecuted = max($inspectedCount, $completedCount + $deferredCount);
            $started = max(0, (int) ($result['started'] ?? (empty($result['not_started']) ? 1 : 0)));
            $notStarted = max(0, (int) ($result['not_started'] ?? ($started === 0 ? 1 : 0)));
            $status = $started === 0 && $notStarted > 0
                ? 'not_started'
                : (in_array($outcome, ['failed', 'action_required'], true)
                ? 'failed'
                : ($outcome === 'partial'
                    ? 'partial'
                    : (CronWorkOutcome::isWaiting($outcome) ? 'deferred' : ($completedCount > 0 ? 'completed' : 'inspected'))));
            $measurement ??= (new CronWorkAvailabilityService())->snapshot([$queueKey])[$queueKey] ?? [
                    'known' => false,
                    'measurement_state' => 'unavailable',
                ];
            $backlogAfter = !empty($measurement['known'])
                ? max(0, (int) $measurement['total_pending'])
                : null;
            Database::connectionFresh()->prepare(
                'UPDATE system_work_queue_run_items
                 SET result=?,execution_result=?,inspected_count=?,deferred_count=?,completed_count=?,batch_executed=?,
                     newly_discovered_count=?,deduplicated_count=?,
                     attempted_remote_calls=?,actual_api_calls=?,known_response_count=?,resources_received_count=?,blocked_remote_calls=?,
                     checkpoint_approved_count=?,campaign_id=?,campaign_item_id=?,next_opportunity_at=?,
                     backlog_after=?,backlog_after_state=?,backlog_after_measured_at=?,
                     duration_ms=?,safe_message=?,started_at=IF(?=1,NULL,started_at),finished_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE work_queue_run_id=? AND queue_key=?'
            )->execute([
                $status,
                $outcome,
                $inspectedCount,
                max($deferredCount, $status === 'deferred' ? 1 : 0),
                $completedCount,
                $batchExecuted,
                $newlyDiscovered,
                $deduplicated,
                max(0, (int) ($result['attempted_remote_calls'] ?? $result['remote_calls'] ?? 0)),
                max(0, (int) ($result['remote_calls'] ?? 0)),
                max(0, (int) ($result['known_responses'] ?? 0)),
                max(0, (int) ($result['resources_received'] ?? 0)),
                max(0, (int) ($result['blocked_remote_calls'] ?? 0)),
                max(0, (int) ($result['checkpoint_approved'] ?? $result['approved'] ?? 0)),
                !empty($result['campaign_id']) ? (int) $result['campaign_id'] : null,
                !empty($result['campaign_item_id']) ? (int) $result['campaign_item_id'] : null,
                $result['campaign_next_at'] ?? $result['next_safe_at'] ?? $result['next_eligible_at'] ?? null,
                $backlogAfter,
                (string) $measurement['measurement_state'],
                !empty($measurement['known']) ? gmdate('Y-m-d H:i:s') : null,
                max(0, (int) ($result['duration_ms'] ?? 0)),
                !empty($result['message']) ? mb_substr(Logger::redactString((string) $result['message']), 0, 500) : null,
                $started === 0 && $notStarted > 0 ? 1 : 0,
                $runId,
                $queueKey,
            ]);
            $this->persistTraceMetadata($runId, $queueKey, $result);
            (new CronProducerMetricService())->recordForRunId(
                $runId,
                $queueKey,
                $newlyDiscovered,
                $deduplicated,
                (string) ($result['producer_key'] ?? 'callback')
            );
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $payload @param list<string> $keys */
    private function optionalCount(array $payload, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && is_numeric($payload[$key])) {
                return max(0, (int) $payload[$key]);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $summary */
    public function finish(?int $runId, array $summary): void
    {
        if (!$runId) {
            return;
        }
        try {
            $steps = $summary['coordinator']['steps'] ?? [];
            $completed = $deferred = $failed = $fatalSteps = $started = $inspected = $attemptedRemote = $remote = $blockedRemote = $approved = $notStarted = 0;
            foreach ($steps as $step) {
                $outcome = (string) ($step['status'] ?? '');
                $isFailed = (int) ($step['errors'] ?? 0) > 0
                    || in_array($outcome, ['failed', 'action_required'], true);
                $fatalSteps += in_array($outcome, ['failed', 'action_required'], true) ? 1 : 0;
                $failed += max(0, (int) ($step['failed'] ?? ($isFailed ? 1 : 0)));
                $deferred += max(0, (int) ($step['deferred'] ?? (CronWorkOutcome::isWaiting($outcome) ? 1 : 0)));
                $completed += max(0, (int) ($step['completed'] ?? 0));
                $started += max(0, (int) ($step['started'] ?? 0));
                $inspected += max(0, (int) ($step['inspected'] ?? 0));
                $remote += max(0, (int) ($step['remote_calls'] ?? 0));
                $attemptedRemote += max(0, (int) ($step['attempted_remote_calls'] ?? $step['remote_calls'] ?? 0));
                $blockedRemote += max(0, (int) ($step['blocked_remote_calls'] ?? 0));
                $approved += max(0, (int) ($step['checkpoint_approved'] ?? 0));
                $notStarted += max(0, (int) ($step['not_started'] ?? 0));
            }
            $summaryNotStarted = max($notStarted, (int) ($summary['not_started'] ?? 0));
            $status = $fatalSteps > 0
                ? 'failed'
                : ($completed === 0 && $deferred === 0 && $failed === 0 && $summaryNotStarted === 0
                    ? 'empty'
                    : (($deferred > 0 || $failed > 0 || $summaryNotStarted > 0) ? 'partial' : 'completed'));
            Database::connectionFresh()->prepare(
                'UPDATE system_work_queue_runs
                 SET status=?,selected_count=?,started_count=?,inspected_count=?,attempted_remote_call_count=?,remote_call_count=?,blocked_remote_call_count=?,checkpoint_approved_count=?,
                     completed_count=?,deferred_count=?,failed_count=?,not_started_count=?,
                     duration_ms=?,safe_summary=?,finished_at=UTC_TIMESTAMP()
                 WHERE id=?'
            )->execute([
                $status,
                max(count($steps), (int) ($summary['selected'] ?? 0)),
                max($started, (int) ($summary['started'] ?? 0)),
                max($inspected, (int) ($summary['inspected'] ?? 0)),
                max($attemptedRemote, (int) ($summary['attempted_remote_calls'] ?? $summary['remote_calls'] ?? 0)),
                max($remote, (int) ($summary['remote_calls'] ?? 0)),
                max($blockedRemote, (int) ($summary['blocked_remote_calls'] ?? 0)),
                max($approved, (int) ($summary['checkpoint_approved'] ?? 0)),
                max($completed, (int) ($summary['completed'] ?? 0)),
                max($deferred, (int) ($summary['deferred'] ?? 0)),
                max($failed, (int) ($summary['errors'] ?? 0)),
                $summaryNotStarted,
                max(0, (int) ($summary['coordinator']['used_ms'] ?? 0)),
                mb_substr('Final: ' . (string) ($summary['end_reason'] ?? 'sin identificar'), 0, 500),
                $runId,
            ]);
        } catch (Throwable) {
        }
    }

    /** @return array{runs:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function historyPage(int $page = 1, int $perPage = 50): array
    {
        (new CronOperationalAccessScope())->assertGlobal();
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        if (!(new SchemaInspectorService())->hasTable('system_work_queue_runs')) {
            return ['runs' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }
        $pdo = Database::connectionFresh();
        $countSnapshot = (new ReadModelCacheService())->rememberArray(
            'cron-history-count',
            'global',
            60,
            static fn(): array => [
                'total' => (int) Database::connectionFresh()->query(
                    'SELECT COUNT(*) FROM system_work_queue_runs'
                )->fetchColumn(),
            ]
        );
        $total = max(0, (int) $countSnapshot['value']['total']);
        $runs = $pdo->query(
            'SELECT * FROM system_work_queue_runs ORDER BY started_at DESC,id DESC LIMIT ' . $perPage
            . ' OFFSET ' . (($page - 1) * $perPage)
        )->fetchAll(PDO::FETCH_ASSOC);
        $itemsByRun = $this->readItemsByRun($pdo, array_map(static fn (array $run): int => (int) $run['id'], $runs));
        foreach ($runs as &$run) {
            $items = $itemsByRun[(int) $run['id']] ?? [];
            $run['items'] = $items;
            $realErrors = array_values(array_filter($items, static fn (array $item): bool => !empty($item['real_error'])));
            $run['real_error_count'] = count($realErrors);
            $run['primary_error'] = $realErrors[0] ?? null;
            $run['run_url'] = InternalUrl::to('/settings/cron/run?token=' . rawurlencode((string) $run['run_token']));
        }
        unset($run);
        return ['runs' => $runs, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @return array<string,mixed>|null */
    public function detail(string $token): ?array
    {
        (new CronOperationalAccessScope())->assertGlobal();
        if ($token === '' || strlen($token) > 100 || preg_match('/^[a-zA-Z0-9:_-]+$/', $token) !== 1
            || !(new SchemaInspectorService())->hasTable('system_work_queue_runs')) {
            return null;
        }
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare('SELECT * FROM system_work_queue_runs WHERE run_token=? LIMIT 1');
        $stmt->execute([$token]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($run)) {
            return null;
        }
        $byRun = $this->readItemsByRun($pdo, [(int) $run['id']]);
        $items = $byRun[(int) $run['id']] ?? [];
        $run['items'] = $items;
        $run['real_error_count'] = count(array_filter($items, static fn (array $item): bool => !empty($item['real_error'])));
        $run['automatic_deferred_count'] = count(array_filter($items, static fn (array $item): bool => !empty($item['automatic'])));
        $run['json_url'] = InternalUrl::to('/settings/cron/run.json?token=' . rawurlencode($token));
        return $run;
    }

    /** @param list<int> $runIds @return array<int,list<array<string,mixed>>> */
    private function readItemsByRun(PDO $pdo, array $runIds): array
    {
        if ($runIds === [] || !(new SchemaInspectorService())->hasTable('system_work_queue_run_items')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($runIds), '?'));
        $stmt = $pdo->prepare(
            'SELECT i.*,p.company_id projection_company_id,p.meli_account_id projection_account_id,
                    p.account_name projection_account_name,p.human_label projection_label,
                    p.content_summary projection_summary,p.reached_remote projection_reached_remote,
                    p.next_eligible_at projection_next_at
             FROM system_work_queue_run_items i
             LEFT JOIN system_work_queue_projection p ON p.id=i.projection_id
             WHERE i.work_queue_run_id IN (' . $placeholders . ')
             ORDER BY i.work_queue_run_id,i.position_no,i.id'
        );
        $stmt->execute($runIds);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['work_queue_run_id']][] = $this->presentReadItem($row);
        }
        return $result;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function presentReadItem(array $row): array
    {
        $outcome = strtolower((string) ($row['execution_result'] ?? $row['result'] ?? ''));
        $policy = (new WorkResolutionPolicyRegistry())->resolve([
            'source_status' => $outcome === 'failed' ? 'error' : $outcome,
            'normalized_error_code' => $outcome,
            'safe_error_message' => $row['safe_message'] ?? null,
            'reached_remote' => $row['reached_remote'] ?? $row['projection_reached_remote'] ?? null,
        ]);
        $automatic = !empty($policy['automatic']) || in_array($outcome, [
            'deferred','not_started','planned_not_started','waiting_window','waiting_deadline',
            'waiting_budget','waiting_rhythm','waiting_api','account_paused','account_stopped','lock_busy',
        ], true);
        $failed = (string) ($row['result'] ?? '') === 'failed' || $outcome === 'failed';
        $queueKey = (string) ($row['queue_key'] ?? '');
        $sourceId = (string) ($row['work_source_id'] ?? $row['source_id'] ?? '');
        $hasExactResource = $sourceId !== '' && (string) ($row['source_table'] ?? '') !== 'cron_task_state'
            && $sourceId !== $queueKey;
        $diagnostic = trim((string) ($row['diagnostic_id'] ?? ''));
        $definitions = (new WorkQueueRegistry())->definitionsByKey();
        $safeMessage = trim((string) ($row['safe_message'] ?? ''));
        $legacyNeedsDiagnosis = $failed && !$automatic && $diagnostic === '';
        $detailUrl = $hasExactResource
            ? InternalUrl::to('/settings/cron/work?' . http_build_query(['queue_key' => $queueKey, 'source_id' => $sourceId]))
            : InternalUrl::to('/settings/cron/queue?' . http_build_query(['queue_key' => $queueKey, 'group' => 'all']));
        return $row + [
            'function_label' => (string) ($row['projection_label'] ?? $definitions[$queueKey]['label'] ?? $queueKey),
            'resource_label' => $hasExactResource ? $sourceId : (string) ($row['campaign_item_id'] ?? $queueKey),
            'account_label' => (string) ($row['account_name'] ?? $row['projection_account_name'] ?? 'Alcance general'),
            'what_happened' => $safeMessage !== '' ? $safeMessage : (string) ($policy['whatHappened'] ?? 'Sin detalle disponible.'),
            'transport_started' => ($row['reached_remote'] ?? $row['projection_reached_remote'] ?? null),
            'diagnostic_label' => $diagnostic !== '' ? $diagnostic : ($legacyNeedsDiagnosis ? 'legacy_needs_diagnosis' : 'No aplica'),
            'next_opportunity' => $row['next_opportunity_at'] ?? $row['projection_next_at'] ?? null,
            'automatic' => $automatic,
            'legacy_needs_diagnosis' => $legacyNeedsDiagnosis,
            'real_error' => $failed && !$automatic && !$legacyNeedsDiagnosis,
            'detail_url' => $detailUrl,
        ];
    }

    private function workUnit(string $queueKey): string
    {
        return match ($queueKey) {
            'notification_spool' => 'eventos',
            'notification_backfill', 'notification_fallback' => 'notificaciones',
            'manual_campaign' => 'trabajos',
            'sales_audit' => 'páginas',
            'module_jobs' => 'módulos',
            'items_sync' => 'productos',
            default => 'recursos',
        };
    }

    /** @param array<string,mixed> $result */
    private function persistTraceMetadata(int $runId, string $queueKey, array $result): void
    {
        $schema = new SchemaInspectorService();
        $scope = is_array($result['scope'] ?? null) ? $result['scope'] : [];
        $values = [
            'company_id' => ($result['company_id'] ?? $scope['company_id'] ?? null),
            'meli_account_id' => ($result['meli_account_id'] ?? $scope['meli_account_id'] ?? null),
            'work_source_id' => ($result['work_source_id'] ?? $result['source_id'] ?? $result['campaign_item_id'] ?? null),
            'reached_remote' => array_key_exists('reached_remote', $result)
                ? ($result['reached_remote'] === null ? null : (!empty($result['reached_remote']) ? 1 : 0))
                : (max(0, (int) ($result['remote_calls'] ?? 0)) > 0 ? 1 : null),
            'failure_class' => ($result['failure_class'] ?? $result['stop_reason'] ?? null),
            'diagnostic_id' => ($result['diagnostic_id'] ?? null),
        ];
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            if (!$schema->hasColumn('system_work_queue_run_items', $column)) {
                continue;
            }
            $sets[] = $column . '=?';
            $params[] = is_string($value) ? mb_substr(Logger::redactString($value), 0, 120) : $value;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $runId;
        $params[] = $queueKey;
        Database::connectionFresh()->prepare(
            'UPDATE system_work_queue_run_items SET ' . implode(',', $sets) . '
             WHERE work_queue_run_id=? AND queue_key=?'
        )->execute($params);
    }
}
