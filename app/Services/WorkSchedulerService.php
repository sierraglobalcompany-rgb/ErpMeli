<?php

declare(strict_types=1);

namespace App\Services;

final class WorkSchedulerService
{
    /** @return array{selected:list<array<string,mixed>>,waiting:list<array<string,mixed>>,reason:string} */
    public function preview(): array
    {
        $definitions = (new CronTaskDefinitionRegistry())->all();
        $availability = (new CronWorkAvailabilityService())->snapshot();
        foreach ($definitions as &$definition) {
            $definition = array_merge($definition, $availability[$definition['key']] ?? [
                'known' => false,
                'work_count' => 0,
                'oldest_due_at' => null,
            ]);
        }
        unset($definition);
        $settings = new AppSettingsService();
        $maxTasks = max(1, min(10, $settings->int('cron.max_tasks_per_run', 4)));
        // cron.max_api_tasks_per_run queda como clave histórica. La vista
        // previa usa la misma capacidad del CLI y el ritmo limita transportes.
        $maxApiTasks = $maxTasks;
        $runtimeSelected = (new CronTaskStateService())->preview(
            $definitions,
            $maxTasks,
            $maxApiTasks
        );
        $runtimeSelected = array_map([$this, 'presentRuntimeTask'], $runtimeSelected);
        $campaignNotice = $this->campaignNotice(
            $runtimeSelected,
            $availability['manual_campaign'] ?? ['known' => false, 'work_count' => 0, 'oldest_due_at' => null]
        );

        $page = (new WorkQueueProjectionService())->page(['active_only' => true, 'per_page' => 100]);
        $selected = [];
        $waiting = [];
        $apiCount = 0;
        $attentionPresenter = new WorkAttentionPresenter();
        $eligibilityService = new WorkEligibilityService();
        foreach ($page['rows'] as $item) {
            $eligibility = $eligibilityService->inspect($item);
            $item['eligibility'] = $eligibility;
            $state = $eligibility['state'];
            if ($state === 'action_required') {
                $item['decision_reason'] = 'action_required';
                $item['attention'] = $attentionPresenter->present($item, 'action_required');
                $waiting[] = $item;
                continue;
            }
            if ($state === 'paused') {
                $item['decision_reason'] = 'paused';
                $item['attention'] = $attentionPresenter->present($item, 'paused');
                $waiting[] = $item;
                continue;
            }
            if ($state === 'waiting_budget') {
                $item['decision_reason'] = 'waiting_budget';
                $item['attention'] = $attentionPresenter->present($item, 'waiting_budget');
                $waiting[] = $item;
                continue;
            }
            if ($state === 'future') {
                $item['decision_reason'] = 'not_yet_eligible';
                $item['attention'] = $attentionPresenter->present($item, 'not_yet_eligible');
                $waiting[] = $item;
                continue;
            }
            if (!$eligibility['eligible']) {
                $item['decision_reason'] = $state === 'running' ? 'already_running' : 'unverifiable';
                $item['attention'] = $attentionPresenter->present($item, (string) $item['decision_reason']);
                $waiting[] = $item;
                continue;
            }
            if (count($selected) >= $maxTasks) {
                $item['decision_reason'] = 'cycle_capacity';
                $item['attention'] = $attentionPresenter->present($item, 'cycle_capacity');
                $waiting[] = $item;
                continue;
            }
            if (!empty($item['is_api_task']) && $apiCount >= $maxApiTasks) {
                $item['decision_reason'] = 'api_slot_capacity';
                $item['attention'] = $attentionPresenter->present($item, 'api_slot_capacity');
                $waiting[] = $item;
                continue;
            }
            $item['decision_reason'] = (string) ($item['display_status'] ?? '') === 'retry'
                ? 'automatic_retry'
                : 'selected_by_priority';
            $item['attention'] = $attentionPresenter->present($item, (string) $item['decision_reason']);
            $selected[] = $item;
            if (!empty($item['is_api_task'])) {
                $apiCount++;
            }
        }
        $unavailableQueues = $this->unavailableQueueCount();
        return [
            // Backward-compatible key. These are examples of eligible resources,
            // not a second prediction of the task-lane selector above.
            'selected' => $selected,
            'eligible_examples' => $selected,
            'runtime_selected' => $runtimeSelected,
            'campaign_notice' => $campaignNotice,
            'waiting' => array_slice($waiting, 0, 10),
            'all_checked' => $unavailableQueues === 0,
            'unavailable_queues' => $unavailableQueues,
            'reason' => $selected === []
                ? ($unavailableQueues > 0
                    ? $unavailableQueues . ' colas no pudieron comprobarse. No es seguro afirmar que no exista trabajo.'
                    : ($waiting === []
                    ? 'Todas las colas se comprobaron y no hay trabajo listo en este momento.'
                    : 'No hay trabajo listo ahora. Revise las causas concretas en la lista de espera.'))
                : 'Prioridad comercial y antigüedad dentro de cada nivel.',
        ];
    }

    /** @param list<array<string,mixed>> $selected @param array<string,mixed> $availability */
    private function campaignNotice(array $selected, array $availability): ?array
    {
        foreach ($selected as $task) {
            if ((string) ($task['key'] ?? '') === 'manual_campaign') {
                return [
                    'state' => 'selected',
                    'label' => 'La campaña dirigida recibirá turno',
                    'message' => 'Tiene una plaza reservada en la primera ronda remota del siguiente ciclo.',
                    'next_at' => null,
                ];
            }
        }
        if (empty($availability['known']) || (int) ($availability['work_count'] ?? 0) < 1) {
            return null;
        }
        try {
            $stmt = \App\Core\Database::connectionFresh()->prepare(
                'SELECT status,next_run_at,last_selection_reason
                 FROM cron_task_state WHERE task_key="manual_campaign" LIMIT 1'
            );
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $nextTimestamp = !empty($row['next_run_at'])
                ? (new SystemDatabaseUtcClock())->timestamp((string) $row['next_run_at'])
                : null;
            $future = $nextTimestamp !== null && $nextTimestamp > time();
            return [
                'state' => $future ? 'waiting' : 'blocked',
                'label' => $future ? 'La campaña está esperando su próxima oportunidad' : 'La campaña fue detectada, pero no quedó seleccionada',
                'message' => $future
                    ? 'Conserva su turno y será elegible en la hora indicada.'
                    : 'Abra el diagnóstico: el selector detectó trabajo, pero una protección o reserva impidió elegirlo.',
                'next_at' => $future ? (string) $row['next_run_at'] : null,
            ];
        } catch (\Throwable) {
            return [
                'state' => 'unknown',
                'label' => 'La campaña existe, pero no se pudo comprobar su turno',
                'message' => 'Revise el diagnóstico del lanzador.',
                'next_at' => null,
            ];
        }
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function presentRuntimeTask(array $task): array
    {
        $key = (string) ($task['key'] ?? '');
        $labels = [
            'notification_fallback' => 'Ventas y notificaciones urgentes',
            'orders_sync' => 'Sincronización de ventas',
            'manual_campaign' => 'Campaña dirigida activa',
            'operational_maintenance' => 'Mantenimiento local',
            'notification_spool' => 'Recepción de notificaciones',
            'notification_backfill' => 'Recuperación local de notificaciones',
        ];
        return [
            'key' => $key,
            'label' => $labels[$key] ?? ucwords(str_replace('_', ' ', $key)),
            'lane' => (string) ($task['lane'] ?? 'normal'),
            'work_count' => max(0, (int) ($task['work_count'] ?? 0)),
            'known' => !empty($task['known']),
            'is_api' => !empty($task['api']),
            'reason' => match ((string) ($task['selection_reason'] ?? '')) {
                'urgent_lane' => 'Entra primero por tratarse de ventas o notificaciones recientes.',
                'directed_lane_guaranteed' => 'Tiene un turno reservado después de la urgencia.',
                'directed_lane_reserved' => 'Tiene una plaza dirigida reservada en el ciclo.',
                'normal_api_capacity' => 'Fue seleccionada por antigüedad y capacidad remota disponible.',
                'local_capacity' => 'Aprovecha el tiempo restante sin consumir consultas.',
                default => 'Fue seleccionada por prioridad, antigüedad y capacidad real del ciclo.',
            },
        ];
    }

    private function unavailableQueueCount(): int
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_queue_health')) {
                return 1;
            }
            return (int) \App\Core\Database::connectionFresh()->query(
                'SELECT COUNT(*) FROM system_queue_health WHERE status<>"healthy"'
            )->fetchColumn();
        } catch (\Throwable) {
            return 1;
        }
    }
}
