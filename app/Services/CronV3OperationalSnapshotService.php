<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

/**
 * Snapshot humano de Cron V3: una sola lectura para Cron, Salud API y campaña.
 * No muta estado; si algo falla, marca la sección como partial/unavailable.
 */
final class CronV3OperationalSnapshotService
{
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $measuredAt = gmdate('Y-m-d H:i:s');
        $protocol = 'complete';
        $sections = [];
        $legacy = null;
        $tasks = [];
        try {
            $legacyService = new CronOperationalReadService();
            $tasks = $legacyService->tasks();
            $legacy = $legacyService->overview($tasks);
            $sections['legacy_v2_read'] = [
                'state' => $legacyService->snapshotState(),
                'message' => 'Lectura V2 solo para backlog e historial; no es motor si V3 operativo está activo.',
            ];
        } catch (Throwable) {
            $protocol = 'partial';
            $sections['legacy_v2_read'] = [
                'state' => 'unavailable',
                'message' => 'No se pudo leer backlog legacy sin mutar estado.',
            ];
        }

        $pdo = null;
        try {
            $pdo = Database::connectionFresh();
            $v3 = (new CronV3OperationalReadService($pdo))->overview();
            $runtime = (new CronV3RuntimeStatusService($pdo))->snapshot();
            $capabilities = (new CronV3CapabilityMatrixService($pdo))->matrix();
        } catch (Throwable) {
            $protocol = 'unavailable';
            $v3 = ['ok' => false, 'state' => 'unavailable', 'state_label' => 'Cron V3 no disponible'];
            $runtime = ['ok' => false, 'state' => 'unavailable', 'state_label' => 'No se pudo leer runtime V3'];
            $capabilities = [];
        }

        $queues = $this->queues($tasks, $capabilities);
        $totals = $this->totals($queues, $v3);
        $state = $this->state($runtime, $capabilities, $totals);
        $ratePolicy = (new CronV3RatePolicyService())->current((int) \App\Core\Env::get('CRON_V3_RATE_LIMIT', '10'));
        $rateLimitSignals = $this->rateLimitSignals();

        return [
            'ok' => $protocol !== 'unavailable',
            'protocol' => $protocol,
            'snapshot_state' => $protocol,
            'read_only' => true,
            'generation' => sha1($measuredAt . ':' . json_encode($totals, JSON_UNESCAPED_SLASHES)),
            'measured_at' => $measuredAt,
            'state' => $state['state'],
            'state_label' => $state['label'],
            'state_message' => $state['message'],
            'runtime' => $runtime,
            'v3' => $v3,
            'legacy_overview' => $legacy,
            'capabilities' => array_values($capabilities),
            'queues' => $queues,
            'totals' => $totals,
            'rate_policy' => $ratePolicy,
            'api_rate_limit_signals' => $rateLimitSignals,
            'drainage' => $this->drainage($totals),
            'sections' => $sections,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rateLimitSignals(): array
    {
        try {
            return array_slice((new ApiHealthService())->incidents([
                'hours' => 24,
                'origin' => 'remote',
                'http_status' => 429,
            ], 5), 0, 5);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $totals @return array<string,mixed> */
    private function drainage(array $totals): array
    {
        $pending = (int) ($totals['legacy_pending_visible'] ?? 0);
        $finalized = (int) ($totals['v3_completed_last_hour'] ?? 0);
        $unknown = (int) ($totals['legacy_pending_unknown_queues'] ?? 0);
        $complete = $unknown === 0;
        $criticalGaps = (int) ($totals['waiting_capability_queues'] ?? 0)
            + (int) ($totals['review_unsupported_queues'] ?? 0)
            + (int) ($totals['v3_waiting_capability'] ?? 0);
        $canClaimDecreasing = $complete && $criticalGaps === 0 && $finalized > 0;

        return [
            'measurement_state' => $complete ? 'complete' : 'partial',
            'previous_pending' => null,
            'newly_discovered' => null,
            'finalized' => $finalized,
            'current_pending' => $pending,
            'formula' => 'pendientes anteriores + entradas nuevas - finalizados = pendientes actuales',
            'trend' => $canClaimDecreasing ? 'draining_observed' : 'unknown',
            'can_claim_decreasing' => $canClaimDecreasing,
            'explanation' => match (true) {
                !$complete => 'Snapshot parcial: no se declarará que la cola baja ni sube hasta medir todas las colas.',
                $criticalGaps > 0 => 'Hay trabajo parqueado o colas críticas sin capacidad V3; no se subirá el ritmo todavía.',
                $finalized < 1 => 'No hay finalizaciones recientes suficientes para certificar drenaje.',
                default => 'Hay finalizaciones recientes y no se observan brechas críticas en el snapshot completo.',
            },
        ];
    }

    /** @param list<array<string,mixed>> $tasks @param array<string,array<string,mixed>> $capabilities @return list<array<string,mixed>> */
    private function queues(array $tasks, array $capabilities): array
    {
        $rows = [];
        $seen = [];
        foreach ($tasks as $task) {
            $key = (string) ($task['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $capability = $capabilities[$key] ?? null;
            $row = $task;
            if (is_array($capability)) {
                $row['capability_state'] = (string) ($capability['capability_state'] ?? $capability['state'] ?? 'unavailable');
                $row['runtime_state'] = (string) ($capability['runtime_state'] ?? 'unavailable');
                $row['v3_human_state'] = (string) ($capability['human_state'] ?? 'Por comprobar');
                $row['v3_reason'] = (string) ($capability['reason'] ?? '');
                $row['v3_work_types'] = array_values((array) ($capability['work_types'] ?? []));
                if (!in_array($row['capability_state'], ['v3_active', 'v3_local_only'], true)) {
                    $row['state'] = 'waiting_capability';
                    $row['state_label'] = $row['v3_human_state'];
                }
            } else {
                $row['capability_state'] = 'legacy_readonly_backlog';
                $row['runtime_state'] = 'not_mapped';
                $row['v3_human_state'] = 'Histórico sin dueño V3 declarado';
                $row['v3_reason'] = 'Esta cola no está en la matriz V3 2.31.0.';
            }
            $rows[] = $row;
            $seen[$key] = true;
        }

        foreach ($capabilities as $key => $capability) {
            if (isset($seen[$key])) {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'label' => (string) ($capability['label'] ?? $key),
                'pending' => null,
                'eligible_now' => null,
                'attention_count' => null,
                'remote' => (string) ($capability['lane'] ?? '') === 'remote',
                'capability_state' => (string) ($capability['capability_state'] ?? $capability['state'] ?? 'unavailable'),
                'runtime_state' => (string) ($capability['runtime_state'] ?? 'unavailable'),
                'v3_human_state' => (string) ($capability['human_state'] ?? 'Por comprobar'),
                'v3_reason' => (string) ($capability['reason'] ?? ''),
                'v3_work_types' => array_values((array) ($capability['work_types'] ?? [])),
                'state' => (string) ($capability['capability_state'] ?? $capability['state'] ?? '') === 'review_unsupported'
                    ? 'review_unsupported'
                    : 'waiting_capability',
                'state_label' => (string) ($capability['human_state'] ?? 'Por comprobar'),
            ];
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $queues @param array<string,mixed> $v3 @return array<string,mixed> */
    private function totals(array $queues, array $v3): array
    {
        $pending = 0;
        $unknownPending = 0;
        $waitingCapability = 0;
        $reviewUnsupported = 0;
        $v3ActiveQueues = 0;
        foreach ($queues as $queue) {
            if (is_numeric($queue['pending'] ?? null)) {
                $pending += max(0, (int) $queue['pending']);
            } else {
                $unknownPending++;
            }
            $capability = (string) ($queue['capability_state'] ?? '');
            if ($capability === 'waiting_capability') {
                $waitingCapability++;
            } elseif ($capability === 'review_unsupported') {
                $reviewUnsupported++;
            } elseif (in_array($capability, ['v3_active', 'v3_local_only'], true)) {
                $v3ActiveQueues++;
            }
        }
        $v3Totals = is_array($v3['totals'] ?? null) ? $v3['totals'] : [];

        return [
            'legacy_pending_visible' => $pending,
            'legacy_pending_unknown_queues' => $unknownPending,
            'v3_ready' => (int) ($v3Totals['ready'] ?? 0),
            'fifo_ready_remote' => (int) ($v3Totals['remote_ready'] ?? $v3Totals['ready_remote'] ?? $v3Totals['ready'] ?? 0),
            'fifo_ready_local' => (int) ($v3Totals['local_ready'] ?? $v3Totals['ready_local'] ?? 0),
            'v3_deferred' => (int) ($v3Totals['deferred'] ?? 0),
            'v3_waiting_capability' => (int) ($v3Totals['waiting_capability'] ?? 0),
            'v3_waiting_identity' => (int) ($v3Totals['waiting_identity'] ?? 0),
            'v3_waiting_rate' => (int) ($v3Totals['waiting_rate'] ?? 0),
            'v3_waiting_budget' => (int) ($v3Totals['waiting_budget'] ?? 0),
            'v3_waiting_api' => (int) ($v3Totals['waiting_api'] ?? 0),
            'v3_parked' => (int) ($v3Totals['parked'] ?? 0),
            'v3_review' => (int) ($v3Totals['review'] ?? 0),
            'v3_dead' => (int) ($v3Totals['dead'] ?? 0),
            'v3_http_last_hour' => (int) ($v3Totals['http_last_hour'] ?? 0),
            'v3_completed_last_hour' => (int) ($v3Totals['throughput_last_hour'] ?? 0),
            'v3_active_queues' => $v3ActiveQueues,
            'waiting_capability_queues' => $waitingCapability,
            'review_unsupported_queues' => $reviewUnsupported,
        ];
    }

    /** @param array<string,mixed> $runtime @param array<string,array<string,mixed>> $capabilities @param array<string,mixed> $totals @return array{state:string,label:string,message:string} */
    private function state(array $runtime, array $capabilities, array $totals): array
    {
        if (($runtime['state'] ?? '') !== 'operational') {
            return [
                'state' => 'blocked',
                'label' => (string) ($runtime['state_label'] ?? 'V3 no operativo'),
                'message' => 'Revise Hostinger, Doctor y configuración antes de evaluar drenaje.',
            ];
        }
        if ((int) $totals['waiting_capability_queues'] > 0 || (int) $totals['review_unsupported_queues'] > 0) {
            return [
                'state' => 'operational_with_gaps',
                'label' => 'V3 operativo con colas sin camino completo',
                'message' => 'V3 está drenando la fila lista. Los trabajos sin identidad o capacidad quedan parqueados y no bloquean el FIFO.',
            ];
        }
        if ((int) $totals['v3_review'] > 0 || (int) $totals['v3_dead'] > 0) {
            return [
                'state' => 'attention',
                'label' => 'V3 requiere revisión',
                'message' => 'Hay trabajos V3 en revisión o muertos con diagnóstico seguro.',
            ];
        }

        return [
            'state' => 'healthy',
            'label' => 'V3 operativo',
            'message' => 'V3 está drenando la fila lista y tomará el trabajo ejecutable más antiguo.',
        ];
    }
}
