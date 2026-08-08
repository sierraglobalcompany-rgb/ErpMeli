<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

/**
 * Diagnóstico operativo de Cron estrictamente read-only.
 *
 * No reclama leases, no libera reservas, no crea snapshots y no consulta
 * Mercado Libre. Su trabajo es explicar por qué el ritmo observado está por
 * debajo del permitido.
 */
final class CronDoctorService
{
    private SystemDatabaseUtcClock $clock;
    private SchemaInspectorService $schema;

    public function __construct()
    {
        $this->clock = new SystemDatabaseUtcClock();
        $this->schema = new SchemaInspectorService();
    }

    /** @return array<string,mixed> */
    public function snapshot(string $source = 'web'): array
    {
        $rhythm = $this->rhythm();
        $observed = $this->observed();
        $queues = $this->queueStates();
        $campaign = $this->campaignProjection(6);
        $leases = $this->leases();
        $blockers = $this->blockers($rhythm, $observed, $queues, $campaign, $leases);

        return [
            'ok' => true,
            'snapshot_state' => 'complete',
            'read_only' => true,
            'source' => $source,
            'version' => $this->version(),
            'build' => $this->build(),
            'measured_at' => gmdate('Y-m-d H:i:s'),
            'measured_at_bogota' => $this->clock->toBogota(gmdate('Y-m-d H:i:s')),
            'safety' => $this->safety(),
            'requested' => [
                'profile' => $rhythm['profile'],
                'target_http_per_minute' => $rhythm['target_http_per_minute'],
            ],
            'ramp' => [
                'adaptive_enabled' => $rhythm['adaptive_enabled'],
                'current_http_per_minute' => $rhythm['current_adaptive_limit'],
            ],
            'effective' => [
                'estimated_http_per_minute' => $rhythm['effective_http_per_minute'],
                'limit_reason' => $rhythm['limit_reason'],
                'endpoint_budget_per_15m' => $rhythm['endpoint_budget_per_15m'],
            ],
            'observed' => $observed,
            'leases' => $leases,
            'queue_states' => $queues,
            'campaign_projection' => $campaign,
            'blockers' => $blockers,
            'diagnosis' => $this->diagnosisText($rhythm, $observed, $blockers),
        ];
    }

    public function textReport(): string
    {
        $snapshot = $this->snapshot('cli');
        $lines = [
            'ERP_CRON_DOCTOR version=' . $snapshot['version']['files']
                . ' schema=' . $snapshot['version']['app_version']
                . ' build=' . $snapshot['build'],
            'requested=' . $snapshot['requested']['target_http_per_minute'] . '/min'
                . ' ramp=' . $snapshot['ramp']['current_http_per_minute'] . '/min'
                . ' effective=' . $snapshot['effective']['estimated_http_per_minute'] . '/min'
                . ' observed_60m=' . $snapshot['observed']['http_per_minute_60m'] . '/min',
            'blockers=' . implode(',', array_map(
                static fn (array $b): string => (string) $b['code'],
                (array) $snapshot['blockers']
            )),
            'campaign_6=' . ($snapshot['campaign_projection']['state'] ?? 'unknown')
                . ' executable_now=' . (int) ($snapshot['campaign_projection']['executable_now'] ?? 0)
                . ' completed_http=' . (int) ($snapshot['campaign_projection']['completed_with_http'] ?? 0)
                . ' closed_local=' . (int) ($snapshot['campaign_projection']['closed_locally'] ?? 0),
        ];

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** @return array<string,mixed> */
    private function version(): array
    {
        $root = AppPaths::releaseRoot();
        return [
            'files' => trim((string) @file_get_contents($root . '/VERSION')) ?: 'unknown',
            'app_version' => (new AppSettingsService())->get('app.version', 'unknown'),
            'manifest_version' => (string) ($this->manifest()['version'] ?? 'unknown'),
            'minimum_migration' => (string) ($this->manifest()['minimum_migration'] ?? ''),
        ];
    }

    private function build(): string
    {
        return (string) ($this->manifest()['build_id'] ?? 'unknown');
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        $manifest = json_decode((string) @file_get_contents(AppPaths::releaseRoot() . '/resources/runtime-manifest.json'), true);
        return is_array($manifest) ? $manifest : [];
    }

    /** @return array<string,mixed> */
    private function safety(): array
    {
        $emergency = (new EmergencyControlService())->status();
        return [
            'api' => (string) ($emergency['api'] ?? 'unknown'),
            'automation' => (string) ($emergency['automation'] ?? 'unknown'),
            'maintenance' => $emergency['maintenance'] ?? null,
            'ml_write_enabled' => Env::bool('ML_WRITE_ENABLED', false),
        ];
    }

    /** @return array<string,mixed> */
    private function rhythm(): array
    {
        $settings = new AppSettingsService();
        $profile = (string) $settings->get('api.rhythm.profile', $settings->get('api.rhythm.mode', 'balanced'));
        $target = max(1, $settings->int('api.rhythm.target_http_per_minute', match ($profile) {
            'conservative' => 10,
            'fast' => 30,
            'maximum' => 40,
            default => 20,
        }));
        $ramp = max(1, $settings->int('api.rhythm.current_adaptive_limit', $target));
        $endpointBudget = max(1, $settings->int('api.budget.endpoint_requests_per_15m', 50));
        $effective = round(min($target, $ramp, $endpointBudget / 15), 2);

        return [
            'profile' => $profile,
            'target_http_per_minute' => $target,
            'current_adaptive_limit' => $ramp,
            'adaptive_enabled' => $settings->bool('api.rhythm.adaptive_enabled', true),
            'endpoint_budget_per_15m' => $endpointBudget,
            'effective_http_per_minute' => $effective,
            'limit_reason' => $effective < min($target, $ramp) ? 'endpoint_budget' : 'profile_ramp',
        ];
    }

    /** @return array<string,mixed> */
    private function observed(): array
    {
        $values = [
            'http_15m' => 0,
            'http_60m' => 0,
            'known_15m' => 0,
            'known_60m' => 0,
            'resources_finalized_15m' => 0,
            'resources_finalized_60m' => 0,
            'not_started_deadline_15m' => 0,
            'not_started_deadline_60m' => 0,
            'latest_run_token' => null,
            'latest_run_at' => null,
        ];
        try {
            $pdo = Database::connectionFresh();
            if ($this->schema->hasTable('system_work_queue_runs')) {
                $row = $pdo->query(
                    'SELECT run_token,finished_at FROM system_work_queue_runs
                     ORDER BY COALESCE(finished_at,started_at) DESC,id DESC LIMIT 1'
                )->fetch(PDO::FETCH_ASSOC) ?: [];
                $values['latest_run_token'] = $row['run_token'] ?? null;
                $values['latest_run_at'] = $row['finished_at'] ?? null;
                $values['latest_run_at_bogota'] = $this->clock->toBogota($row['finished_at'] ?? null);
            }
            if ($this->schema->hasTable('system_cron_run_steps')) {
                foreach ([15, 60] as $minutes) {
                    $stmt = $pdo->prepare(
                        'SELECT
                            COALESCE(SUM(remote_call_count),0) http,
                            COALESCE(SUM(attempted_remote_call_count),0) attempted,
                            COALESCE(SUM(completed_count),0) completed,
                            COALESCE(SUM(CASE WHEN stop_reason="not_started_deadline" OR status="waiting_deadline" THEN not_started_count ELSE 0 END),0) nsd
                         FROM system_cron_run_steps
                         WHERE finished_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . (int) $minutes . ' MINUTE)'
                    );
                    $stmt->execute();
                    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $suffix = $minutes . 'm';
                    $values['http_' . $suffix] = (int) ($row['http'] ?? 0);
                    $values['known_' . $suffix] = (int) ($row['attempted'] ?? 0);
                    $values['resources_finalized_' . $suffix] = (int) ($row['completed'] ?? 0);
                    $values['not_started_deadline_' . $suffix] = (int) ($row['nsd'] ?? 0);
                }
            }
        } catch (Throwable) {
            $values['snapshot_state'] = 'partial';
        }

        $values['http_per_minute_15m'] = round(((int) $values['http_15m']) / 15, 2);
        $values['http_per_minute_60m'] = round(((int) $values['http_60m']) / 60, 2);
        $values['resources_per_hour'] = (int) $values['resources_finalized_60m'];
        return $values;
    }

    /** @return list<array<string,mixed>> */
    private function queueStates(): array
    {
        $rows = [];
        try {
            if (!$this->schema->hasTable('cron_task_state')) {
                return $rows;
            }
            $stmt = Database::connectionFresh()->query(
                'SELECT task_key,status,next_run_at,last_started_at,last_finished_at,last_selection_reason,
                        last_eligible_count,last_total_pending,last_attention_count,last_batch_remote_calls,
                        last_batch_completed,last_batch_started,measurement_state
                 FROM cron_task_state
                 ORDER BY COALESCE(last_total_pending,0) DESC,task_key ASC'
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = [
                    'queue_key' => (string) $row['task_key'],
                    'state' => (string) $row['status'],
                    'pending' => is_numeric($row['last_total_pending'] ?? null) ? (int) $row['last_total_pending'] : null,
                    'eligible_now' => is_numeric($row['last_eligible_count'] ?? null) ? (int) $row['last_eligible_count'] : null,
                    'attention' => is_numeric($row['last_attention_count'] ?? null) ? (int) $row['last_attention_count'] : null,
                    'last_http' => (int) ($row['last_batch_remote_calls'] ?? 0),
                    'last_completed' => (int) ($row['last_batch_completed'] ?? 0),
                    'last_started' => (int) ($row['last_batch_started'] ?? 0),
                    'next_run_at' => $row['next_run_at'] ?? null,
                    'next_run_at_bogota' => $this->clock->toBogota($row['next_run_at'] ?? null),
                    'last_selection_reason' => $row['last_selection_reason'] ?? null,
                    'measurement_state' => $row['measurement_state'] ?? 'unknown',
                ];
            }
        } catch (Throwable) {
            return [[
                'queue_key' => 'cron_task_state',
                'state' => 'unavailable',
                'message' => 'No se pudo leer el estado de colas.',
            ]];
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function campaignProjection(int $campaignId): array
    {
        $projection = [
            'campaign_id' => $campaignId,
            'state' => 'unavailable',
            'total' => null,
            'pending' => null,
            'executable_now' => 0,
            'waiting_source' => 0,
            'errors' => 0,
            'completed_with_http' => 0,
            'closed_locally' => 0,
            'last_event_at' => null,
        ];
        try {
            if (!$this->schema->hasTable('manual_campaigns') || !$this->schema->hasTable('manual_campaign_items')) {
                return $projection;
            }
            $pdo = Database::connectionFresh();
            $stmt = $pdo->prepare(
                'SELECT status,total_items,total_units,completed_items,failed_items,skipped_items,last_engine_state,last_scheduler_reason
                 FROM manual_campaigns WHERE id=? LIMIT 1'
            );
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) {
                $projection['state'] = 'not_found';
                return $projection;
            }
            $projection['state'] = (string) ($campaign['status'] ?? 'unknown');
            $projection['total'] = (int) ($campaign['total_items'] ?? 0);
            $projection['total_units'] = (int) ($campaign['total_units'] ?? 0);
            $projection['completed_counter'] = (int) ($campaign['completed_items'] ?? 0);
            $projection['failed_counter'] = (int) ($campaign['failed_items'] ?? 0);
            $projection['last_engine_state'] = $campaign['last_engine_state'] ?? null;
            $projection['last_scheduler_reason'] = $campaign['last_scheduler_reason'] ?? null;

            $hasRemoteCallCount = $this->schema->hasColumn('manual_campaign_items', 'remote_call_count');
            $completedHttpExpr = $hasRemoteCallCount
                ? 'SUM(CASE WHEN status IN ("completed","approved") AND COALESCE(remote_call_count,0)>0 THEN 1 ELSE 0 END)'
                : '0';
            $closedLocalExpr = $hasRemoteCallCount
                ? 'SUM(CASE WHEN status IN ("completed","approved","skipped","returned") AND COALESCE(remote_call_count,0)=0 THEN 1 ELSE 0 END)'
                : 'SUM(CASE WHEN status IN ("completed","approved","skipped","returned") THEN 1 ELSE 0 END)';
            $counts = $pdo->prepare(
                'SELECT
                    COUNT(*) total,
                    SUM(CASE WHEN status IN ("pending","waiting","retry") THEN 1 ELSE 0 END) pending,
                    SUM(CASE WHEN status IN ("pending","retry") AND (next_eligible_at IS NULL OR next_eligible_at<=UTC_TIMESTAMP()) AND COALESCE(source_state,"") NOT IN ("waiting_source","source_attention") THEN 1 ELSE 0 END) executable,
                    SUM(CASE WHEN COALESCE(source_state,"")="waiting_source" THEN 1 ELSE 0 END) waiting_source,
                    SUM(CASE WHEN status IN ("failed","error","action_required") THEN 1 ELSE 0 END) errors,
                    ' . $completedHttpExpr . ' completed_http,
                    ' . $closedLocalExpr . ' closed_local
                 FROM manual_campaign_items WHERE manual_campaign_id=?'
            );
            $counts->execute([$campaignId]);
            $row = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
            $projection['total'] = (int) ($row['total'] ?? $projection['total']);
            $projection['pending'] = (int) ($row['pending'] ?? 0);
            $projection['executable_now'] = (int) ($row['executable'] ?? 0);
            $projection['waiting_source'] = (int) ($row['waiting_source'] ?? 0);
            $projection['errors'] = (int) ($row['errors'] ?? 0);
            $projection['completed_with_http'] = (int) ($row['completed_http'] ?? 0);
            $projection['closed_locally'] = (int) ($row['closed_local'] ?? 0);
            if ($this->schema->hasTable('manual_campaign_events')) {
                $event = $pdo->prepare(
                    'SELECT created_at,event_type,safe_message
                     FROM manual_campaign_events WHERE manual_campaign_id=?
                     ORDER BY created_at DESC,id DESC LIMIT 1'
                );
                $event->execute([$campaignId]);
                $latest = $event->fetch(PDO::FETCH_ASSOC) ?: [];
                $projection['last_event_at'] = $latest['created_at'] ?? null;
                $projection['last_event_at_bogota'] = $this->clock->toBogota($latest['created_at'] ?? null);
                $projection['last_event_type'] = $latest['event_type'] ?? null;
                $projection['last_message'] = $latest['safe_message'] ?? null;
            }
        } catch (Throwable) {
            $projection['state'] = 'partial';
        }

        return $projection;
    }

    /** @return array<string,mixed> */
    private function leases(): array
    {
        $data = ['active_remote_permits' => 0, 'running_tasks' => 0, 'oldest_running_at' => null];
        try {
            $pdo = Database::connectionFresh();
            if ($this->schema->hasTable('api_remote_permits')) {
                $data['active_remote_permits'] = (int) $pdo->query(
                    'SELECT COUNT(*) FROM api_remote_permits
                     WHERE status IN ("reserved","dispatched") AND expires_at>UTC_TIMESTAMP(3)'
                )->fetchColumn();
            }
            if ($this->schema->hasTable('cron_task_state')) {
                $row = $pdo->query(
                    'SELECT COUNT(*) running,MIN(last_started_at) oldest
                     FROM cron_task_state WHERE status="running"'
                )->fetch(PDO::FETCH_ASSOC) ?: [];
                $data['running_tasks'] = (int) ($row['running'] ?? 0);
                $data['oldest_running_at'] = $row['oldest'] ?? null;
                $data['oldest_running_at_bogota'] = $this->clock->toBogota($row['oldest'] ?? null);
            }
        } catch (Throwable) {
            $data['state'] = 'partial';
        }
        return $data;
    }

    /**
     * @param array<string,mixed> $rhythm
     * @param array<string,mixed> $observed
     * @param list<array<string,mixed>> $queues
     * @param array<string,mixed> $campaign
     * @param array<string,mixed> $leases
     * @return list<array<string,mixed>>
     */
    private function blockers(array $rhythm, array $observed, array $queues, array $campaign, array $leases): array
    {
        $blockers = [];
        $effective = (float) ($rhythm['effective_http_per_minute'] ?? 0.0);
        $observed60 = (float) ($observed['http_per_minute_60m'] ?? 0.0);
        if ($effective > 0 && $observed60 < ($effective * 0.65)) {
            $blockers[] = [
                'code' => 'planner_underutilization',
                'severity' => 'S3',
                'message' => 'El ritmo observado está por debajo del permitido; el bloqueo ocurre antes del transporte HTTP.',
            ];
        }
        if ((int) ($observed['not_started_deadline_15m'] ?? 0) >= 3) {
            $blockers[] = [
                'code' => 'deadline_planning_waste',
                'severity' => 'S3',
                'message' => 'Muchos candidatos se aplazan por ventana segura; el planificador debe elegir recursos más cortos.',
            ];
        }
        if ((int) ($leases['active_remote_permits'] ?? 0) > 0) {
            $blockers[] = [
                'code' => 'active_remote_permit',
                'severity' => 'S2',
                'message' => 'Existe un permiso HTTP vivo que puede estar espaciando el siguiente transporte.',
            ];
        }
        if ((int) ($campaign['completed_counter'] ?? 0) > 0 && (int) ($campaign['completed_with_http'] ?? 0) === 0) {
            $blockers[] = [
                'code' => 'campaign_local_completion_without_http',
                'severity' => 'S3',
                'message' => 'La campaña registra trabajos resueltos sin HTTP confirmado; los contadores deben separar cierres locales.',
            ];
        }
        if ((int) ($campaign['waiting_source'] ?? 0) > 0 && (int) ($campaign['executable_now'] ?? 0) === 0) {
            $blockers[] = [
                'code' => 'campaign_waiting_source',
                'severity' => 'S2',
                'message' => 'La campaña tiene ítems esperando fuente/módulo y no debe consumir ventana dirigida.',
            ];
        }
        foreach ($queues as $queue) {
            if (($queue['state'] ?? '') === 'running') {
                $blockers[] = [
                    'code' => 'queue_running_lease',
                    'severity' => 'S2',
                    'queue_key' => $queue['queue_key'] ?? '',
                    'message' => 'Una cola conserva lease running; solo debe recuperarse si venció.',
                ];
                break;
            }
        }
        if ($blockers === []) {
            $blockers[] = [
                'code' => 'no_primary_blocker_detected',
                'severity' => 'S0',
                'message' => 'No se detectó bloqueo evidente con las lecturas disponibles.',
            ];
        }
        return $blockers;
    }

    /** @param array<string,mixed> $rhythm @param array<string,mixed> $observed @param list<array<string,mixed>> $blockers */
    private function diagnosisText(array $rhythm, array $observed, array $blockers): string
    {
        $codes = array_map(static fn (array $b): string => (string) $b['code'], $blockers);
        return sprintf(
            'Solicitado %s/min, rampa %s/min, permitido %.2f/min, observado %.2f/min. Bloqueo principal: %s.',
            (string) ($rhythm['target_http_per_minute'] ?? '?'),
            (string) ($rhythm['current_adaptive_limit'] ?? '?'),
            (float) ($rhythm['effective_http_per_minute'] ?? 0.0),
            (float) ($observed['http_per_minute_60m'] ?? 0.0),
            $codes[0] ?? 'unknown'
        );
    }
}
