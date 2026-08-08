<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use Throwable;

final class ApiHealthOverviewService
{
    private bool $technicalDataAvailable = true;

    public function __construct(
        private readonly AppSettingsService $settings = new AppSettingsService(),
        private readonly ReadModelCacheService $cache = new ReadModelCacheService(),
    ) {}

    /** @return array<string,mixed> */
    public function overview(int $hours = 24, ?int $accountId = null): array
    {
        $hours = in_array($hours, [24, 168, 720], true)
            ? $hours
            : max(1, min(720, $this->settings->int('api.health.default_period_hours', 24)));
        $accountId = $accountId !== null && $accountId > 0 ? $accountId : null;
        $ttl = max(5, min(120, $this->settings->int('api.health.overview_cache_seconds', 30)));
        $key = implode(':', [$hours, $accountId ?: 'all', Auth::id() ?: 0, Auth::role()]);
        $cached = $this->cache->rememberArray('api-health-overview', $key, $ttl, function () use ($hours, $accountId): array {
            return $this->build($hours, $accountId);
        });
        $result = $cached['value'];
        $result['cache'] = $cached['cache'];
        return $result;
    }

    /** @return array<string,mixed> */
    private function build(int $hours, ?int $accountId): array
    {
        $healthScope = (new ApiHealthAccessScope())->snapshot($accountId);
        $authorizedAccountIds = $healthScope['account_ids'];
        $service = new ApiHealthService();
        $accounts = $service->accounts();
        if ($accountId !== null) {
            $accounts = array_values(array_filter($accounts, static fn(array $account): bool => (int) ($account['id'] ?? 0) === $accountId));
        }
        $stats = $service->accountStats($hours, $accountId);
        $guard = new ApiGuardService();
        $circuits = $guard->openCircuits(100, $authorizedAccountIds, $healthScope['application']);
        $manualPause = (new ApiManualPauseService())->summary($authorizedAccountIds, $healthScope['application']);
        $budgetAvailability = $service->budgetAvailability();
        $systemSafety = (new SystemSafetyStatusService())->status();
        $emergencyStop = (new MeliEmergencyStopService())->status();
        $accountSummary = (new ApiHealthAccountService())->summarize($accounts, $stats, $circuits, $manualPause);
        $apiStopped = (string) ($systemSafety['api'] ?? 'unknown') === 'stopped';
        $incidentLimit = max(1, min(10, $this->settings->int('api.health.overview_incident_limit', 3)));
        $incidentOverview = $service->incidentOverview($hours, $accountId, $incidentLimit, 5);
        $policyEvents = $incidentOverview['policy'];
        $trueActiveIncidents = $incidentOverview['active'];
        $remoteActiveIncidents = array_values(array_filter(
            $trueActiveIncidents,
            static fn(array $incident): bool => !empty($incident['reached_remote']) || !empty($incident['blocking_risk'])
        ));
        $localActiveIncidents = array_values(array_filter(
            $trueActiveIncidents,
            static fn(array $incident): bool => empty($incident['reached_remote']) && empty($incident['blocking_risk'])
        ));
        $activeIncidents = array_slice(array_merge($remoteActiveIncidents, $localActiveIncidents), 0, $incidentLimit);
        $recovered = $incidentOverview['recovered'];
        $incidentCounts = $incidentOverview['counts'];
        $activityLimit = max(1, min(10, $this->settings->int('api.health.recent_activity_limit', 5)));
        $activity = $service->recentSuccessfulActivity($hours, $activityLimit, $accountId);
        foreach ($activity as &$row) {
            $row['operation_label'] = UiLabelPresenter::apiOperation((string) ($row['method'] ?? ''), (string) ($row['endpoint_path'] ?? ''));
        }
        unset($row);
        $totals = [
            'sent' => 0, 'successful' => 0, 'raw_successful' => 0,
            'corrected_successful' => 0, 'remote_errors' => 0, 'local_failures' => 0,
        ];
        foreach ($stats as $stat) {
            foreach ($totals as $key => $unused) {
                $totals[$key] += (int) ($stat[$key] ?? 0);
            }
        }
        $manualCount = (int) $manualPause['count'];
        $pauseCount = count($circuits) + $manualCount + (!empty($emergencyStop['active']) ? 1 : 0);
        $nextSafeAt = null;
        $clock = new SystemDatabaseUtcClock();
        foreach (array_merge($circuits, $manualPause['pauses']) as $pause) {
            $candidate = $pause['blocked_until'] ?? $pause['paused_until'] ?? null;
            if ($candidate && ($nextSafeAt === null
                || $clock->timestamp((string) $candidate) > $clock->timestamp($nextSafeAt))) {
                $nextSafeAt = (string) $candidate;
            }
        }
        $signals = $service->activeSignalCounts($accountId);
        $criticalCircuit = array_filter($circuits, static fn(array $circuit): bool =>
            ($circuit['reason'] ?? '') === 'app_blocked' || ($circuit['scope'] ?? '') === 'app'
        ) !== [];
        $uncertainResults = $this->uncertainResults($accountId);
        $erpAttention = count($localActiveIncidents) > 0 || $uncertainResults > 0;
        // Capture la evidencia dentro del mismo build cacheado para que API,
        // cuentas, incidentes y automatizacion compartan una sola generacion.
        $evidence = [];
        $evidence['automation_evidence'] = $this->automationEvidence($systemSafety, $accountId);
        // Consultar disponibilidad al final. Una lectura de incidentes o actividad
        // puede fallar después de obtener cuentas/estadísticas; usar el valor
        // temprano presentaba una salud verde junto a secciones incomprobables.
        $dataAvailable = $service->dataAvailable();
        $dataAvailable = $dataAvailable
            && $guard->readAvailable()
            && $budgetAvailability['available']
            && $this->technicalDataAvailable;
        if (!$dataAvailable || $apiStopped) {
            $accountSummary['available'] = 0;
            foreach ($accountSummary['rows'] as &$accountRow) {
                $accountRow['state'] = 'unknown';
                $accountRow['state_label'] = $apiStopped
                    ? 'No comprobada durante el mantenimiento'
                    : 'No comprobada';
                $accountRow['success_rate'] = null;
            }
            unset($accountRow);
        }
        $status = (new ApiHealthStatusPresenter())->present([
            'api_state' => (string) ($systemSafety['api'] ?? 'unknown'),
            'data_available' => $dataAvailable,
            'signals' => $signals,
            'critical_circuit' => $criticalCircuit,
            'pause_count' => $pauseCount,
            'active_incident_count' => count($remoteActiveIncidents),
            'recovered_incident_count' => $incidentCounts['recovered'],
            'available_accounts' => $accountSummary['available'],
            'total_accounts' => $accountSummary['total'],
            'sent' => $totals['sent'],
            'successful' => $totals['successful'],
        ]);

        $recentSeconds = max(60, min(3600, $this->settings->int('api.health.remote_evidence_recent_seconds', 900)));
        $staleSeconds = max($recentSeconds, min(604800, $this->settings->int('api.health.remote_evidence_stale_seconds', 3600)));
        $freshnessCounts = ['recent' => 0, 'aging' => 0, 'stale' => 0, 'none' => 0];
        foreach ($accountSummary['rows'] as &$freshnessRow) {
            $lastActivity = $clock->timestamp((string) ($freshnessRow['last_activity_at'] ?? ''));
            $age = $lastActivity ? max(0, time() - $lastActivity) : null;
            $freshnessRow['evidence_age_seconds'] = $age;
            $freshnessRow['evidence_freshness'] = $age === null ? 'none' : ($age <= $recentSeconds ? 'recent' : ($age <= $staleSeconds ? 'aging' : 'stale'));
            $freshnessRow['evidence_protocol'] = $age === null ? 'authoritative_empty' : 'complete';
            $freshnessCounts[$freshnessRow['evidence_freshness']]++;
            $freshnessRow['evidence_label'] = match ($freshnessRow['evidence_freshness']) {
                'recent' => 'Evidencia reciente',
                'aging' => 'Evidencia antigua',
                'stale' => 'No comprobado recientemente',
                default => 'No comprobado recientemente',
            };
        }
        unset($freshnessRow);

        $snapshotState = !$dataAvailable
            ? 'unavailable'
            : ($accountSummary['total'] === 0 && $totals['sent'] === 0 && $activeIncidents === []
                ? 'authoritative_empty'
                : 'complete');
        $checkedAt = gmdate('Y-m-d H:i:s');
        $generation = hash('sha256', implode('|', [
            $checkedAt,
            (string) ($accountId ?? 'all'),
            implode(',', array_map('intval', $authorizedAccountIds)),
            (string) $hours,
        ]));
        return [
            'snapshot_generation' => $generation,
            'snapshot_state' => $snapshotState,
            'authoritative' => in_array($snapshotState, ['complete', 'authoritative_empty'], true),
            'data_availability' => [
                'available' => $dataAvailable,
                'label' => $dataAvailable ? 'Datos comprobados' : 'No se pudo comprobar',
                'checked_at' => $checkedAt,
                'generation' => $generation,
            ],
            'status' => $status,
            'system_safety' => $systemSafety,
            'checked_at' => $checkedAt,
            'hours' => $hours,
            'account_id' => $accountId,
            'classified' => $service->classificationAvailable(),
            'queries' => [
                'sent' => $totals['sent'],
                'successful' => $totals['successful'],
                'accepted_successful' => $totals['successful'],
                'raw_successful' => $totals['raw_successful'],
                'corrected_successful' => $totals['corrected_successful'],
                'remote_errors' => $totals['remote_errors'],
                'local_failures' => $totals['local_failures'],
                'success_rate' => $totals['sent'] > 0 ? round(($totals['successful'] / $totals['sent']) * 100, 1) : null,
                'raw_success_rate' => $totals['sent'] > 0 ? round(($totals['raw_successful'] / $totals['sent']) * 100, 1) : null,
            ],
            'accounts' => $accountSummary,
            'incidents' => [
                'active' => $activeIncidents,
                'active_count' => count($remoteActiveIncidents) + count($localActiveIncidents),
                'raw_active_count' => $incidentCounts['active'],
                'remote_active_count' => count($remoteActiveIncidents),
                'local_active_count' => count($localActiveIncidents),
                'protection_event_count' => count($policyEvents),
                'recovered' => $recovered,
                'recovered_count' => $incidentCounts['recovered'],
                'reviewed_count' => $incidentCounts['reviewed'],
                'historical_count' => $incidentCounts['historical'],
                'truncated' => $incidentOverview['truncated'],
            ],
            'protection' => [
                'pause_count' => $pauseCount,
                'manual_pause' => $manualPause,
                'emergency_stop' => $emergencyStop,
                'circuits' => $circuits,
                'next_safe_at' => $nextSafeAt,
                'erp_waits' => $policyEvents,
                'erp_wait_count' => count($policyEvents),
                'budget_availability' => $budgetAvailability,
            ],
            'freshness' => [
                'measured_at' => $checkedAt,
                'recent_seconds' => $recentSeconds,
                'stale_seconds' => $staleSeconds,
                'counts' => $freshnessCounts,
            ],
            'recent_activity' => $activity,
            'erp_processing' => [
                'status' => $erpAttention ? 'attention' : 'healthy',
                'label' => $uncertainResults > 0
                    ? $uncertainResults . ($uncertainResults === 1
                        ? ' resultado debe comprobarse después de una interrupción'
                        : ' resultados deben comprobarse después de una interrupción')
                    : (count($localActiveIncidents) > 0 ? 'Un proceso interno necesita revisión' : 'Procesos del ERP funcionando'),
                'incident_count' => count($localActiveIncidents),
                'uncertain_results' => $uncertainResults,
            ],
            'automation_evidence' => array_merge(
                $evidence['automation_evidence'],
                ['generation' => $generation]
            ),
        ];
    }

    private function uncertainResults(?int $accountId): int
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_execution_attempts')) {
                return 0;
            }
            $scope = new BusinessScopeContext();
            $ids = $accountId !== null ? [(int) $scope->account($accountId)['id']] : $scope->accountIds();
            if ($ids === []) {
                return 0;
            }
            $sql = 'SELECT COUNT(*) FROM system_execution_attempts
                    WHERE state="uncertain"
                      AND NOT (reached_remote=1 AND response_status BETWEEN 200 AND 299)
                      AND meli_account_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')';
            $stmt = Database::connectionFresh()->prepare($sql);
            $stmt->execute($ids);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            $this->technicalDataAvailable = false;
            return 0;
        }
    }

    /** @return array<string,mixed> */
    private function automationEvidence(array $systemSafety, ?int $accountId = null): array
    {
        $automationState = (string) ($systemSafety['automation'] ?? 'unknown');
        if ($automationState === 'stopped') {
            return $this->stoppedAutomationEvidence();
        }

        $cacheKey = implode(':', [
            Auth::id() ?: 0,
            Auth::role(),
            $automationState,
            (string) ($systemSafety['changed_at'] ?? ''),
            $accountId ?: 'all',
        ]);
        $cached = $this->cache->rememberArray(
            'api-health-automation-evidence',
            $cacheKey,
            10,
            fn(): array => $this->buildAutomationEvidence($accountId)
        );
        $value = $cached['value'];
        $value['cache'] = $cached['cache'];
        return $value;
    }

    /** @return array<string,mixed> */
    private function buildAutomationEvidence(?int $accountId = null): array
    {
        try {
            $access = (new ApiHealthAccessScope())->snapshot($accountId);
            // Los ciclos históricos anteriores al contrato por tenant no guardan
            // empresa/cuenta en la cabecera ni en todos sus pasos. Por tanto solo
            // son una lectura válida para quien tiene alcance explícito de toda la
            // aplicación. Una cuenta seleccionada o un administrador parcial nunca
            // debe recibir totales globales disfrazados de métricas filtradas.
            if ($accountId !== null || !$access['application']) {
                $evidence = $this->unavailableAutomationEvidence(
                    $accountId !== null
                        ? 'La automatización global no puede atribuirse con seguridad a esta cuenta.'
                        : 'La automatización global solo está disponible con alcance administrativo completo.'
                );
                $evidence['scope'] = $accountId !== null ? 'account' : 'authorized_businesses';
                $evidence['scope_label'] = $accountId !== null
                    ? 'Cuenta seleccionada; métricas globales protegidas'
                    : 'Empresas autorizadas; métricas globales protegidas';
                $evidence['authoritative'] = false;
                return $evidence;
            }
            $row = Database::connectionFresh()->query(
                'SELECT latest.*,recent.remote_calls recent_remote_calls,
                        recent.completed recent_completed,recent.deferred recent_deferred,
                        backlog.pending backlog
                 FROM system_work_queue_runs latest
                 CROSS JOIN (
                     SELECT COALESCE(SUM(remote_call_count),0) remote_calls,
                            COALESCE(SUM(completed_count),0) completed,
                            COALESCE(SUM(deferred_count),0) deferred
                       FROM system_work_queue_runs
                      WHERE origin="scheduled_cli"
                        AND finished_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)
                 ) recent
                 CROSS JOIN (
                     SELECT COALESCE(SUM(last_work_count),0) pending FROM cron_task_state
                 ) backlog
                 WHERE latest.origin="scheduled_cli" AND latest.finished_at IS NOT NULL
                 ORDER BY latest.finished_at DESC,latest.id DESC
                 LIMIT 1'
            )->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return $this->unavailableAutomationEvidence('Todavía no hay un ciclo automático finalizado');
            }

            $backlog = max(0, (int) ($row['backlog'] ?? 0));

            $remoteCalls = max(0, (int) ($row['remote_call_count'] ?? $row['api_calls_used'] ?? 0));
            $attemptedRemoteCalls = max(0, (int) ($row['attempted_remote_call_count'] ?? $remoteCalls));
            $blockedRemoteCalls = max(0, (int) ($row['blocked_remote_call_count'] ?? 0));
            $completed = max(0, (int) ($row['completed_count'] ?? $row['processed_count'] ?? 0));
            $deferred = max(0, (int) ($row['deferred_count'] ?? 0));
            $failed = max(0, (int) ($row['failed_count'] ?? $row['error_count'] ?? 0));
            $finishedAt = isset($row['finished_at']) ? (string) $row['finished_at'] : '';
            $finishedTimestamp = (new SystemDatabaseUtcClock())->timestamp($finishedAt);
            $expectedInterval = max(1, min(60, $this->settings->int('cron.main_interval_minutes', 1)));
            $staleAfter = max(180, ($expectedInterval * 60 * 3));
            $ageSeconds = $finishedTimestamp > 0 ? max(0, time() - $finishedTimestamp) : null;
            $isLate = $ageSeconds === null || $ageSeconds > $staleAfter;
            $hasFailure = $failed > 0 || (string) ($row['status'] ?? '') === 'error';
            $recentRemote = max(0, (int) ($row['recent_remote_calls'] ?? 0));
            $recentCompleted = max(0, (int) ($row['recent_completed'] ?? 0));
            $recentDeferred = max(0, (int) ($row['recent_deferred'] ?? 0));
            $state = match (true) {
                $hasFailure => 'attention',
                $isLate => 'delayed',
                $recentRemote > 0 => 'progressing',
                $recentCompleted > 0 => 'local_only',
                $backlog > 0 && $recentDeferred > 0 => 'deferred',
                $backlog > 0 => 'stalled',
                default => 'signal_only',
            };
            $label = match ($state) {
                'attention' => 'Necesita revisión',
                'delayed' => 'Automatización atrasada',
                'progressing' => 'Automatización avanzando',
                'local_only' => 'Avance local; sin transporte remoto',
                'deferred' => 'Activa, pero aplazando trabajo',
                'stalled' => 'Activa, pero sin avance comprobado',
                default => 'Señal activa; sin trabajo pendiente',
            };
            $tone = match ($state) {
                'attention' => 'danger',
                'delayed', 'deferred', 'stalled' => 'warning',
                default => 'success',
            };

            return [
                'available' => true,
                'state' => $state,
                'tone' => $tone,
                'label' => $label,
                'source' => 'latest_finished_scheduled_cli',
                'scope' => 'global',
                'scope_label' => 'Automatización global autorizada',
                'authoritative' => true,
                'status' => (string) ($row['status'] ?? 'unknown'),
                'finished_at' => $row['finished_at'] ?? null,
                'selected' => max(0, (int) ($row['selected_count'] ?? 0)),
                'started' => max(0, (int) ($row['started_count'] ?? 0)),
                'completed' => $completed,
                'deferred' => $deferred,
                'remote_calls' => $remoteCalls,
                'attempted_remote_calls' => $attemptedRemoteCalls,
                'blocked_remote_calls' => $blockedRemoteCalls,
                'failed' => $failed,
                'useful_activity' => $completed > 0 || $remoteCalls > 0,
                'backlog' => $backlog,
                'remote_calls_15m' => $recentRemote,
                'completed_15m' => $recentCompleted,
                'deferred_15m' => $recentDeferred,
                'age_seconds' => $ageSeconds,
                'stale_after_seconds' => $staleAfter,
                'message' => $isLate
                    ? 'La conexión con Mercado Libre puede estar sana, pero Cron no registra un ciclo reciente. Revise Automatización.'
                    : ($hasFailure
                        ? 'Cron recibió señal, pero el último ciclo terminó con asuntos por revisar.'
                        : match ($state) {
                            'progressing' => 'Cron confirmó consultas remotas durante los últimos 15 minutos.',
                            'local_only' => 'Cron completó trabajo local, pero no confirmó consultas remotas durante los últimos 15 minutos.',
                            'deferred' => 'Cron recibe señal, pero las protecciones están aplazando el trabajo pendiente.',
                            'stalled' => 'Cron recibe señal y existe backlog, pero no hay avance comprobado en los últimos 15 minutos.',
                            default => 'Cron recibe señal y no hay trabajo pendiente que completar.',
                        }),
            ];
        } catch (Throwable) {
            return $this->unavailableAutomationEvidence('No se pudo comprobar el último ciclo automático');
        }
    }

    /** @return array<string,mixed> */
    private function unavailableAutomationEvidence(string $message): array
    {
        return [
            'available' => false,
            'state' => 'unknown',
            'tone' => 'neutral',
            'label' => 'Automatización no comprobada',
            'source' => 'latest_finished_scheduled_cli',
            'scope' => 'unknown',
            'scope_label' => 'Alcance no comprobado',
            'authoritative' => false,
            'status' => 'unknown',
            'finished_at' => null,
            'selected' => 0,
            'started' => 0,
            'completed' => 0,
            'deferred' => 0,
            'remote_calls' => 0,
            'attempted_remote_calls' => 0,
            'blocked_remote_calls' => 0,
            'failed' => 0,
            'useful_activity' => false,
            'message' => $message,
        ];
    }

    /** @return array<string,mixed> */
    private function stoppedAutomationEvidence(): array
    {
        $evidence = $this->unavailableAutomationEvidence(
            'La conexión con Mercado Libre se informa por separado. Cron está detenido por el freno de mano y no avanzará colas.'
        );
        $evidence['state'] = 'stopped';
        $evidence['tone'] = 'warning';
        $evidence['label'] = 'Automatización detenida';
        return $evidence;
    }
}
