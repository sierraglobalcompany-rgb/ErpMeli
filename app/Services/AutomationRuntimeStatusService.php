<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanReadinessService;
use Throwable;

/**
 * Una sola interpretación del lanzador para Cron, Salud y campañas.
 */
final class AutomationRuntimeStatusService
{
    /** @return array<string,mixed> */
    public function status(): array
    {
        try {
            $snapshot = (new QueueV4CleanReadinessService(Database::connectionFresh()))->snapshot();
            $engine = (string) ($snapshot['engine'] ?? 'UNKNOWN');
            $readiness = (string) ($snapshot['state'] ?? 'UNKNOWN');
            $physical = (string) ($snapshot['physical_cron_observed'] ?? 'UNKNOWN');
            $oauth = max(0, (int) ($snapshot['accounts_oauth'] ?? 0));
            $recent = $engine === 'ACTIVE'
                && $readiness === 'CERTIFIED'
                && $physical === 'RECENT'
                && $oauth === 3;
            $state = $recent ? 'operational' : ($physical === 'STALE' ? 'stale' : 'attention');
            return [
                'state' => $state,
                'label' => $recent ? 'Queue V4 operativa' : 'Queue V4 requiere revisión',
                'message' => $recent
                    ? 'El único lanzador Queue V4 registró heartbeat reciente.'
                    : 'Revise el estado V4, OAuth y el Cron físico antes de iniciar trabajo manual.',
                'entry' => null,
                'entry_is_current_build' => null,
                'entry_age_seconds' => null,
                'current_build' => (string) ((new ReleaseIntegrityService())->identity('queue_v4_clean')['build_id'] ?? ''),
                'latest_current_build' => null,
                'latest_any_build' => null,
                'health' => $snapshot,
                'has_recent_signal' => $recent,
                'observed_interval_seconds' => null,
                'next_expected_at' => null,
                'runtime' => [
                    'engine' => $engine,
                    'readiness' => $readiness,
                    'scheduler' => (string) ($snapshot['scheduler'] ?? 'inactive'),
                    'heartbeat_at' => $snapshot['last_scheduler_heartbeat'] ?? null,
                    'physical_cron_observed' => $physical,
                ],
            ];
        } catch (Throwable) {
            return [
                'state' => 'unknown',
                'label' => 'Queue V4 no se pudo comprobar',
                'message' => 'La comprobación read-only de Queue V4 no devolvió evidencia suficiente.',
                'entry' => null,
                'entry_is_current_build' => null,
                'entry_age_seconds' => null,
                'current_build' => '',
                'latest_current_build' => null,
                'latest_any_build' => null,
                'health' => null,
                'has_recent_signal' => false,
                'observed_interval_seconds' => null,
                'next_expected_at' => null,
            ];
        }
    }
}
