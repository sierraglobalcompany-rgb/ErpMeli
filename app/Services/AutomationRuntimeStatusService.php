<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * Una sola interpretación del lanzador para Cron, Salud y campañas.
 */
final class AutomationRuntimeStatusService
{
    /** @return array<string,mixed> */
    public function status(): array
    {
        $health = new CronHealthService();
        $current = $health->status();
        $anyBuild = $health->latestAutomaticAnyBuild();
        $bootstrap = (new CronBootstrapJournalService())->latest();
        $entry = $this->entryState();
        $identity = (new ReleaseIntegrityService())->identity('process_sync_queue');
        $currentBuild = (string) $identity['build_id'];
        $entryCurrent = is_array($entry)
            && $currentBuild !== ''
            && hash_equals($currentBuild, (string) ($entry['build'] ?? ''));
        $entryAge = $entryCurrent ? $this->age((string) ($entry['observed_at'] ?? '')) : null;
        $hasCurrentAutomatic = is_array($current['latest_automatic'] ?? null);

        if (!$entryCurrent && !$hasCurrentAutomatic) {
            $state = 'not_invoked';
            $label = 'Automatización detenida';
            $message = $anyBuild
                ? 'Hay ejecuciones de una versión anterior, pero el ERP no recibió una invocación del build actual.'
                : 'Hostinger no ha iniciado todavía el lanzador del ERP.';
        } elseif ($entryCurrent && (string) ($entry['stage'] ?? '') === 'failed_before_bootstrap') {
            $state = 'php_before_bootstrap_failed';
            $label = 'PHP inició, pero no cargó el ERP';
            $message = 'Hostinger abrió PHP, pero el arranque se detuvo antes de cargar la aplicación. No se consultó Mercado Libre.';
        } elseif (($entryAge ?? PHP_INT_MAX) > 120) {
            $state = 'stopped_during_check';
            $label = 'Detenido durante la comprobación';
            $message = 'El lanzador entró al ERP, pero no alcanzó a preparar las colas.';
        } elseif ($hasCurrentAutomatic) {
            $state = (string) ($current['state'] ?? 'unknown');
            $label = (string) ($current['label'] ?? 'Por comprobar');
            $message = (string) ($current['message'] ?? '');
        } elseif (in_array((string) ($entry['stage'] ?? ''), [
            'queues_prepared', 'work_selected', 'finished',
        ], true)) {
            $state = (string) ($entry['stage'] ?? '') === 'finished' ? 'operational' : 'processing';
            $label = $state === 'processing' ? 'Procesando colas' : 'Esperando el siguiente ciclo';
            $message = 'El build actual alcanzó las colas. La señal completa aparecerá al cerrar el ciclo.';
        } else {
            $state = 'unknown';
            $label = 'No se pudo comprobar';
            $message = 'No hay evidencia suficiente para confirmar el estado del lanzador.';
        }

        return [
            'state' => $state,
            'label' => $label,
            'message' => $message,
            'entry' => $entry,
            'entry_is_current_build' => $entryCurrent,
            'entry_age_seconds' => $entryAge,
            'current_build' => $currentBuild,
            'latest_current_build' => $current['latest_automatic'] ?? null,
            'latest_any_build' => $anyBuild,
            'health' => $current,
            'has_recent_signal' => $hasCurrentAutomatic
                && !in_array((string) ($current['state'] ?? ''), ['missing', 'stale', 'error', 'interrupted'], true),
            'observed_interval_seconds' => (int) ($current['observed_interval_seconds'] ?? 0),
            'next_expected_at' => $current['next_expected_at'] ?? null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function entryState(): ?array
    {
        $path = AppPaths::storage('cache/cron-entry-state.json');
        if (!is_file($path)) {
            return $this->databaseEntryState();
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : $this->databaseEntryState();
    }

    /** @return array<string,mixed>|null */
    private function databaseEntryState(): ?array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_cron_entry_states')) {
                return null;
            }
            $row = Database::connectionFresh()->query(
                'SELECT release_version version,release_build_id build,stage,result_state result,
                        processed_count processed,reached_remote remote,diagnostic_id diagnostic,
                        observed_at
                 FROM system_cron_entry_states
                 WHERE component_key="process_sync_queue" LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function age(string $value): ?int
    {
        $normalized = trim($value);
        if (
            $normalized !== ''
            && preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $normalized) !== 1
        ) {
            $normalized .= ' UTC';
        }
        $timestamp = strtotime($normalized);
        return $timestamp === false ? null : max(0, time() - $timestamp);
    }
}
