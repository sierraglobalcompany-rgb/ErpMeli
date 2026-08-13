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
        $incidentMaterializer = $service->incidentReadModelFreshness();
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
            : (!$incidentMaterializer['current']
                ? 'partial'
                : ($accountSummary['total'] === 0 && $totals['sent'] === 0 && $activeIncidents === []
                ? 'authoritative_empty'
                : 'complete'));
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
                'label' => !$dataAvailable
                    ? 'No se pudo comprobar'
                    : ($snapshotState === 'partial'
                        ? 'Parcial: el materializador está alcanzando los eventos recientes'
                        : 'Datos comprobados'),
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
                'incident_materializer' => $incidentMaterializer + [
                    'lag' => max(0, $incidentMaterializer['latest_log_id'] - $incidentMaterializer['last_log_id']),
                ],
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
            $schema = new SchemaInspectorService();
            if (!$schema->hasTable('queue_v4_clean_attempts')
                || !$schema->hasTable('queue_v4_clean_recovery_events')) {
                return 0;
            }
            $access = (new ApiHealthAccessScope())->snapshot($accountId);
            $companyIds = array_values(array_map('intval', $access['company_ids']));
            $accountIds = array_values(array_map('intval', $access['account_ids']));
            if ($companyIds === [] || $accountIds === []) {
                return 0;
            }
            $sql = 'SELECT COUNT(*)
                    FROM queue_v4_clean_attempts a
                    INNER JOIN queue_v4_clean_jobs j
                      ON j.id=a.job_id AND j.company_id=a.company_id AND j.meli_account_id=a.meli_account_id
                    LEFT JOIN queue_v4_clean_recovery_events re
                      ON re.attempt_id=a.id AND re.job_id=a.job_id
                     AND re.company_id=a.company_id AND re.meli_account_id=a.meli_account_id
                    WHERE a.dispatch_state="PHYSICAL_STARTED"
                      AND a.response_known_at IS NULL AND re.id IS NULL
                      AND a.company_id IN (' . implode(',', array_fill(0, count($companyIds), '?')) . ')
                      AND a.meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            $stmt = Database::connectionFresh()->prepare($sql);
            $stmt->execute(array_merge($companyIds, $accountIds));
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
            fn(): array => $this->buildQueueV4AutomationEvidence($accountId)
        );
        $value = $cached['value'];
        $value['cache'] = $cached['cache'];
        return $value;
    }

    /** @return array<string,mixed> */
    private function buildQueueV4AutomationEvidence(?int $accountId = null): array
    {
        try {
            $access = (new ApiHealthAccessScope())->snapshot($accountId);
            if ($accountId !== null && !in_array($accountId, array_map('intval', $access['account_ids']), true)) {
                return $this->unavailableAutomationEvidence('La cuenta no pertenece al alcance administrativo actual.');
            }
            $snapshot = (new \App\QueueV4Clean\QueueV4CleanHealthSnapshotService(
                Database::connectionFresh()
            ))->snapshot($accountId, $access['company_ids'], $access['account_ids']);
            $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
            $runtime = is_array($snapshot['runtime'] ?? null) ? $snapshot['runtime'] : [];
            $state = (string) ($snapshot['state'] ?? 'attention');
            return [
                'available' => true,
                'state' => $state === 'healthy' ? 'progressing' : $state,
                'tone' => $state === 'healthy' ? 'success' : ($state === 'stopped' ? 'neutral' : 'warning'),
                'label' => (string) ($snapshot['state_label'] ?? 'Queue V4 por comprobar'),
                'source' => 'queue_v4_clean_authority',
                'scope' => $accountId !== null ? 'account' : 'authorized_businesses',
                'scope_label' => $accountId !== null ? 'Cuenta seleccionada' : 'Queue V4 autorizada',
                'authoritative' => true,
                'status' => (string) ($runtime['engine'] ?? 'UNKNOWN'),
                'finished_at' => $runtime['last_scheduler_at'] ?? null,
                'selected' => (int) ($totals['ready'] ?? 0),
                'started' => (int) ($totals['running'] ?? 0),
                'completed' => (int) ($totals['completed_last_hour'] ?? 0),
                'deferred' => (int) ($totals['waiting'] ?? 0),
                'remote_calls' => (int) ($totals['http_last_hour'] ?? 0),
                'attempted_remote_calls' => (int) ($totals['http_last_hour'] ?? 0),
                'blocked_remote_calls' => 0,
                'failed' => (int) ($totals['dead'] ?? 0),
                'useful_activity' => (int) ($totals['completed_last_hour'] ?? 0) > 0
                    || (int) ($totals['http_last_hour'] ?? 0) > 0,
                'backlog' => (int) ($totals['pending'] ?? 0),
                'remote_calls_15m' => null,
                'completed_15m' => null,
                'deferred_15m' => null,
                'age_seconds' => $runtime['heartbeat_age_seconds'] ?? null,
                'stale_after_seconds' => 180,
                'sales_audit' => $snapshot['sales_audit'] ?? [],
                'oauth' => $snapshot['oauth'] ?? [],
                'materializer' => $snapshot['materializer'] ?? [],
                'message' => (string) ($snapshot['state_message'] ?? 'Queue V4 no pudo comprobarse.'),
            ];
        } catch (Throwable) {
            return $this->unavailableAutomationEvidence('No se pudo comprobar Queue V4 Clean.');
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
