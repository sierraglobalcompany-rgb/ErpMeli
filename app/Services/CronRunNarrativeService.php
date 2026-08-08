<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class CronRunNarrativeService
{
    /** @return array<string,mixed> */
    public function latest(): array
    {
        try {
            $health = (new CronHealthService())->latestAutomaticAnyBuild('process_sync_queue');
            if (!is_array($health)) {
                return [
                    'available' => false,
                    'label' => 'Sin señal automática reciente',
                    'message' => 'Hostinger todavía no ha dejado una ejecución automática para narrar.',
                    'steps' => [],
                    'manual_campaign' => null,
                ];
            }

            $steps = $this->steps((int) ($health['id'] ?? 0), (string) ($health['run_token'] ?? ''));
            $manual = $this->manualCampaignTrace($steps['manual_campaign'] ?? null);
            $mainStep = $this->mainStep($steps);
            $endReason = (string) ($health['end_reason'] ?? $health['result_state'] ?? $health['status'] ?? '');
            $label = $mainStep !== null
                ? 'Cron ejecutó: ' . $this->stepLabel((string) ($mainStep['step_name'] ?? ''))
                : 'Cron ejecutó sin seleccionar trabajo visible';
            $message = $this->message($mainStep, $manual, $endReason);

            return [
                'available' => true,
                'label' => $label,
                'message' => $message,
                'finished_at' => $health['finished_at'] ?? $health['heartbeat_at'] ?? $health['started_at'] ?? null,
                'finished_label' => DateTimePresenter::formatQueue(
                    $health['finished_at'] ?? $health['heartbeat_at'] ?? $health['started_at'] ?? null,
                    'd/m/Y H:i:s'
                ),
                'end_reason' => $endReason,
                'end_reason_label' => $this->endReasonLabel($endReason),
                'steps' => array_values($steps),
                'manual_campaign' => $manual,
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'label' => 'No se pudo narrar el último Cron',
                'message' => 'El trabajo queda seguro, pero la vista no pudo leer la trazabilidad rápida.',
                'steps' => [],
                'manual_campaign' => null,
            ];
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function steps(int $healthId, string $runToken): array
    {
        if ($healthId < 1 || !(new SchemaInspectorService())->hasTable('system_cron_run_steps')) {
            return [];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT step_name,priority,status,processed_count,error_count,duration_ms,stop_reason,safe_message,finished_at,
                    campaign_id,campaign_item_id,selection_reason,execution_result,selected_count,started_count,
                    inspected_count,deferred_count,remote_call_count,checkpoint_approved_count,completed_count,
                    not_started_count,next_opportunity_at
             FROM system_cron_run_steps
             WHERE cron_health_check_id=?
             ORDER BY priority ASC,id ASC'
        );
        $stmt->execute([$healthId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) ($row['step_name'] ?? '');
            if ($key === '') {
                continue;
            }
            $row['label'] = $this->stepLabel($key);
            $row['finished_label'] = DateTimePresenter::formatQueue($row['finished_at'] ?? null, 'd/m/Y H:i:s');
            $row['reason_label'] = $this->endReasonLabel((string) ($row['stop_reason'] ?? ''));
            $rows[$key] = $row;
        }
        return $rows;
    }

    /** @param array<string,mixed>|null $manualStep @return array<string,mixed>|null */
    private function manualCampaignTrace(?array $manualStep): ?array
    {
        $campaignId = (int) ($manualStep['campaign_id'] ?? 0);
        if ($manualStep === null || $campaignId < 1 || !(new SchemaInspectorService())->hasTable('manual_campaigns')) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT id,status,next_action_at,last_scheduler_selected_at,last_scheduler_reason,
                    last_scheduler_run_token,last_scheduler_result,last_result_message,safe_message,current_item_id,worker_heartbeat_at
             FROM manual_campaigns
             WHERE id=? AND execution_mode="directed_cli" LIMIT 1'
        );
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($campaign)) {
            return null;
        }
        return [
            'campaign_id' => (int) ($campaign['id'] ?? 0),
            'status' => (string) ($campaign['status'] ?? ''),
            'step_status' => $manualStep['status'] ?? null,
            'selected' => (int) ($manualStep['selected_count'] ?? 0),
            'started' => (int) ($manualStep['started_count'] ?? 0),
            'inspected' => (int) ($manualStep['inspected_count'] ?? 0),
            'deferred' => (int) ($manualStep['deferred_count'] ?? 0),
            'remote_calls' => (int) ($manualStep['remote_call_count'] ?? 0),
            'completed' => (int) ($manualStep['completed_count'] ?? 0),
            'duration_ms' => (int) ($manualStep['duration_ms'] ?? 0),
            'last_selected_at' => $campaign['last_scheduler_selected_at'] ?? null,
            'last_selected_label' => !empty($campaign['last_scheduler_selected_at'])
                ? DateTimePresenter::formatQueue($campaign['last_scheduler_selected_at'], 'd/m/Y H:i:s')
                : 'Sin selección registrada',
            'reason' => (string) ($campaign['last_scheduler_reason'] ?? ''),
            'reason_label' => $this->campaignReasonLabel((string) ($campaign['last_scheduler_reason'] ?? '')),
            'next_at' => $campaign['next_action_at'] ?? null,
            'next_label' => !empty($campaign['next_action_at'])
                ? DateTimePresenter::formatQueue($campaign['next_action_at'], 'd/m/Y H:i:s')
                : null,
            'message' => $campaign['last_result_message'] ?? $campaign['safe_message'] ?? null,
        ];
    }

    /** @param array<string,array<string,mixed>> $steps @return array<string,mixed>|null */
    private function mainStep(array $steps): ?array
    {
        foreach ($steps as $step) {
            if ((int) ($step['processed_count'] ?? 0) > 0 || (string) ($step['status'] ?? '') !== 'empty') {
                return $step;
            }
        }
        return $steps !== [] ? reset($steps) : null;
    }

    /** @param array<string,mixed>|null $step @param array<string,mixed>|null $manual */
    private function message(?array $step, ?array $manual, string $endReason): string
    {
        if ($step === null) {
            return 'No hubo un paso de trabajo registrado en la última ejecución.';
        }
        if ((string) ($step['step_name'] ?? '') === 'manual_campaign' && $manual !== null) {
            $next = !empty($manual['next_label']) ? ' Próxima oportunidad: ' . $manual['next_label'] . ' Bogotá.' : '';
            return 'Cron revisó la campaña #' . (int) $manual['campaign_id'] . '. '
                . $manual['reason_label'] . $next;
        }
        $processed = (int) ($step['processed_count'] ?? 0);
        $reason = (string) ($step['stop_reason'] ?? $endReason);
        return $processed > 0
            ? 'Cron procesó ' . $processed . ' recurso(s) en ' . $this->stepLabel((string) $step['step_name']) . '. ' . $this->endReasonLabel($reason)
            : 'Cron revisó ' . $this->stepLabel((string) $step['step_name']) . '. ' . $this->endReasonLabel($reason);
    }

    private function stepLabel(string $step): string
    {
        return match ($step) {
            'manual_campaign' => 'Campaña dirigida',
            'notification_fallback' => 'Ventas y notificaciones urgentes',
            'orders_sync' => 'Sincronización de ventas',
            'order_enrichment' => 'Enriquecimiento de órdenes',
            'operational_maintenance' => 'Mantenimiento local',
            'monthly_report_maintenance' => 'Reportes mensuales',
            default => ucwords(str_replace('_', ' ', $step)),
        };
    }

    private function campaignReasonLabel(string $reason): string
    {
        return match ($reason) {
            'future', 'item_future', 'waiting_schedule' => 'El recurso quedó aplazado por una fecha segura.',
            'deadline_too_short', 'time_budget' => 'No quedaba ventana segura suficiente para iniciar otra consulta.',
            'waiting_or_complete', 'no_due_work' => 'No había otro recurso listo en la campaña durante ese ciclo.',
            'locked', 'lease_active' => 'Hay una reserva vigente de otro intento.',
            'action_required' => 'La campaña necesita intervención.',
            'completed', 'skipped' => 'El último recurso cerró correctamente.',
            '' => 'Aún no hay causa registrada para esta campaña.',
            default => 'Causa registrada: ' . $reason . '.',
        };
    }

    private function endReasonLabel(string $reason): string
    {
        return match ($reason) {
            'deadline_reached', 'deadline_reached_after_progress' => 'Cerró por límite seguro de tiempo.',
            'deadline_too_short', 'time_budget' => 'Se dejó para el próximo ciclo por ventana insuficiente.',
            'work_completed' => 'Terminó el trabajo permitido para este ciclo.',
            'window_complete' => 'Registro histórico sin causa detallada; revise el ciclo y el recurso asociado.',
            'no_progress_observed' => 'El ciclo no registró una unidad verificable; requiere diagnóstico local.',
            'no_due_work_after_refresh' => 'Al releer la campaña ya no había un recurso elegible.',
            'operation_window_too_short' => 'La ventana restante no alcanzaba para iniciar de forma segura.',
            'eligible_work_not_selected' => 'Hay trabajo pendiente, pero no cupo seguro en este ciclo.',
            'waiting_or_complete', 'no_due_work', 'queue_empty' => 'No encontró trabajo listo.',
            'budget_exhausted' => 'Está esperando presupuesto API.',
            'api_cooldown' => 'Está esperando protección API.',
            'work_locked' => 'Otro proceso conserva una reserva vigente.',
            '' => 'Sin causa adicional.',
            default => 'Resultado: ' . $reason . '.',
        };
    }
}
