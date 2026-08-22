<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class ApiHealthService
{
    private bool $dataAvailable = true;
    private int $lastIncidentTotal = 0;

    /** @return array{current:bool,last_log_id:int,latest_log_id:int} */
    public function incidentReadModelFreshness(): array
    {
        return (new ApiIncidentReadModelService())->freshness();
    }

    public function dashboard(): array
    {
        $summary = $this->summary();
        $summary['risk_label'] = match ($summary['risk']) {
            'critical' => 'Crítico',
            'high' => 'Alto',
            'medium' => 'Atención',
            'recovered' => 'Recuperado',
            default => 'Bajo',
        };
        return $summary;
    }

    public function summary(int $hours = 24, ?int $accountId = null): array
    {
        $hours = max(1, min(720, $hours));
        $accountId = $accountId !== null && $accountId > 0 ? $accountId : null;
        if ($accountId !== null) {
            (new AuthorizedBusinessScope())->account($accountId);
        }
        if (!$this->supportsOutcomeClassification()) {
            if (PHP_SAPI !== 'cli') {
                return $this->unavailableMaterializedSummary();
            }
            return $this->legacySummary($hours, $accountId);
        }

        $accessScope = (new ApiHealthAccessScope())->snapshot($accountId);
        $accountIds = $accessScope['account_ids'];
        $guard = new ApiGuardService();
        $circuits = $guard->openCircuits(100, $accountIds, $accessScope['application']);
        if (!$guard->readAvailable()) {
            $this->dataAvailable = false;
        }
        $manualPause = (new ApiManualPauseService())->summary($accountIds, $accessScope['application']);
        $stats = $this->stats($hours, $accountId);
        $incidentFilters = ['hours' => $hours];
        if ($accountId !== null) {
            $incidentFilters['account_id'] = $accountId;
        }
        $incidents = $this->incidents($incidentFilters, 100);
        $incidentCounts = $this->fastIncidentStateCounts($hours, $accountId);
        $activeIncidents = array_values(array_filter($incidents, static fn(array $incident): bool => $incident['state'] === 'active'));
        $recoveredIncidents = array_values(array_filter($incidents, static fn(array $incident): bool => $incident['state'] === 'recovered'));
        $budget = $this->scopedBudgetSummary($accountId);
        if (($budget['available'] ?? false) !== true) {
            $this->dataAvailable = false;
        }

        $totals = [
            'attempts' => 0,
            'sent' => 0,
            'successful' => 0,
            'raw_successful' => 0,
            'corrected_successful' => 0,
            'remote_errors' => 0,
            'local_failures' => 0,
            'policy_delays' => 0,
            'expected_absence' => 0,
            'http_400' => 0,
            'http_401' => 0,
            'http_403' => 0,
            'http_429' => 0,
            'http_5xx' => 0,
        ];
        foreach ($stats as $stat) {
            foreach ($totals as $key => $value) {
                $totals[$key] += (int) ($stat[$key] ?? 0);
            }
        }

        $activeSignals = $this->activeSignals($accountId);
        $criticalCircuits = array_values(array_filter(
            $circuits,
            static fn(array $circuit): bool => ($circuit['reason'] ?? '') === 'app_blocked' || ($circuit['scope'] ?? '') === 'app'
        ));
        $risk = 'low';
        if ($criticalCircuits !== [] || (int) ($activeSignals['blocked_signals'] ?? 0) > 0 || (int) ($activeSignals['unauthorized'] ?? 0) >= 2) {
            $risk = 'critical';
        } elseif ((int) ($activeSignals['rate_limited'] ?? 0) > 0
            || (int) ($activeSignals['forbidden'] ?? 0) >= 2
            || (int) ($activeSignals['bad_requests'] ?? 0) >= 5
            || count($circuits) >= 3) {
            $risk = 'high';
        } elseif ($manualPause['active']) {
            $risk = 'medium';
        } elseif ($activeIncidents !== [] || $circuits !== []) {
            $risk = 'medium';
        } elseif ($recoveredIncidents !== []) {
            $risk = 'recovered';
        }
        if (!$this->dataAvailable) {
            $risk = 'unknown';
        }

        return [
            'risk' => $risk,
            'data_available' => $this->dataAvailable,
            'requests' => $totals['sent'],
            'errors' => $totals['remote_errors'],
            'attempts' => $totals['attempts'],
            'sent' => $totals['sent'],
            'successful' => $totals['successful'],
            'raw_successful' => $totals['raw_successful'],
            'corrected_successful' => $totals['corrected_successful'],
            'remote_errors' => $totals['remote_errors'],
            'local_failures' => $totals['local_failures'],
            'policy_delays' => $totals['policy_delays'],
            'expected_absence' => $totals['expected_absence'],
            'active_incident_count' => $incidentCounts['active'] ?? count($activeIncidents),
            'recovered_incident_count' => $incidentCounts['recovered'] ?? count($recoveredIncidents),
            'rate_limited' => $totals['http_429'],
            'forbidden' => $totals['http_403'],
            'bad_requests' => $totals['http_400'],
            'unauthorized' => $totals['http_401'],
            'open_circuits' => $circuits,
            'manual_pause' => $manualPause,
            'stats' => $stats,
            'incidents' => array_slice($incidents, 0, 5),
            'problem_endpoints' => $incidents,
            'budget' => $budget,
            'classified' => true,
        ];
    }

    /**
     * @param array{hours?:int,account_id?:int,status?:string,origin?:string,severity?:string,operation?:string,http_status?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function incidents(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        if (!$this->supportsOutcomeClassification()) {
            return [];
        }
        $hours = max(1, min(720, (int) ($filters['hours'] ?? 24)));
        $accountId = max(0, (int) ($filters['account_id'] ?? 0));
        if ($accountId > 0) {
            (new BusinessScopeContext())->account($accountId);
        }
        [$scopeSql, $scopeParams] = $this->telemetryScopeSql('l', 'incident_scope', $accountId ?: null);
        $origin = trim((string) ($filters['origin'] ?? ''));
        $operation = trim((string) ($filters['operation'] ?? ''));
        $where = [
            'l.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL :hours HOUR)',
            'l.incident_key IS NOT NULL',
            'l.outcome_class NOT IN ("success","expected_absence")',
            $scopeSql,
        ];
        $params = $scopeParams;
        if ($origin === 'protection') {
            $where[] = 'l.outcome_class="policy_delay"';
        } elseif ($origin !== 'all_with_protection') {
            $where[] = 'l.outcome_class<>"policy_delay"';
        }
        if ($origin === 'internal') {
            $where[] = 'l.reached_remote=0';
        } elseif ($origin === 'remote') {
            $where[] = 'l.reached_remote=1';
        }
        if ($operation !== '') {
            $where[] = 'l.endpoint_path LIKE :operation';
            $params['operation'] = '%' . $operation . '%';
        }
        $httpStatusFilter = max(0, (int) ($filters['http_status'] ?? 0));
        if ($httpStatusFilter > 0) {
            $where[] = 'l.http_status=:http_status_filter';
            $params['http_status_filter'] = $httpStatusFilter;
            if ($httpStatusFilter === 429) {
                $where[] = 'l.reached_remote=1';
            }
        }
        $statusFilter = in_array((string) ($filters['status'] ?? ''), ['active', 'recovered', 'reviewed', 'historical'], true)
            ? (string) $filters['status']
            : '';
        $severityFilter = in_array((string) ($filters['severity'] ?? ''), ['critical', 'high', 'medium', 'low'], true)
            ? (string) $filters['severity']
            : '';
        if ($severityFilter === 'critical') {
            $where[] = '(l.outcome_class="blocked_signal" OR l.http_status=401)';
        } elseif ($severityFilter === 'high') {
            $where[] = '((l.http_status=403 AND l.reached_remote=1) OR (l.http_status=429 AND l.reached_remote=1))';
        } elseif ($severityFilter === 'medium') {
            $where[] = 'l.outcome_class="local_failure"';
        } elseif ($severityFilter === 'low') {
            $where[] = 'l.outcome_class="remote_error" AND COALESCE(l.http_status,0) NOT IN (401,403,429)';
        }

        $materialized = (new ApiIncidentReadModelService())->page($filters, $limit, $offset);
        if (is_array($materialized)) {
            $this->lastIncidentTotal = (int) $materialized['total'];
            return array_map(fn(array $row): array => $this->presentIncident($row), $materialized['rows']);
        }

        // Web/API Health is materialized-only. Raw GROUP_CONCAT over the request
        // log is a CLI rebuild concern and must never hold an administrative GET.
        if (PHP_SAPI !== 'cli') {
            $this->dataAvailable = false;
            $this->lastIncidentTotal = 0;
            return [];
        }

        // The bounded CLI path remains available to rebuild/verify the read
        // model without making the browser wait on raw telemetry aggregation.
        try {
            $schema = new SchemaInspectorService();
            $hasAcknowledgements = $schema->hasTable('api_incident_acknowledgements');
            $hasScopedAcknowledgements = $hasAcknowledgements && $schema->hasColumn('api_incident_acknowledgements', 'scope_key');
            $scopeKeySql = 'CASE
                WHEN l.meli_account_id IS NOT NULL THEN CONCAT("account:",l.meli_account_id)
                WHEN l.scope_kind="company" AND l.company_id IS NOT NULL THEN CONCAT("company:",l.company_id)
                ELSE "application" END';
            $ackSelect = $hasScopedAcknowledgements
                ? 'MAX(ack.acknowledged_at) acknowledged_at,
                   MIN(CASE WHEN ack.acknowledged_through_at>=l.created_at THEN 1 ELSE 0 END) acknowledged_all,'
                : ($hasAcknowledgements
                ? 'MAX(ack.acknowledged_at) acknowledged_at,
                   MAX(CASE WHEN ack.acknowledged_at IS NOT NULL THEN 1 ELSE 0 END) acknowledged_all,'
                : 'NULL acknowledged_at,');
            if (!$hasAcknowledgements) {
                $ackSelect .= '0 acknowledged_all,';
            }
            $ackJoin = $hasScopedAcknowledgements
                ? ' LEFT JOIN api_incident_acknowledgements ack
                      ON ack.incident_key=l.incident_key AND ack.scope_key=' . $scopeKeySql . ' '
                : ($hasAcknowledgements
                ? ' LEFT JOIN api_incident_acknowledgements ack ON ack.incident_key=l.incident_key '
                : '');
            $activeMinutes = $this->activeWindowMinutes();
            $having = match ($statusFilter) {
                'reviewed' => 'acknowledged_all=1',
                'active' => 'acknowledged_all=0 AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $activeMinutes . ' MINUTE)',
                'historical' => 'acknowledged_all=0 AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)',
                'recovered' => 'acknowledged_all=0 AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $activeMinutes . ' MINUTE) AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)',
                default => '1=1',
            };
            $stmt = Database::connection()->prepare(
                'SELECT SQL_CALC_FOUND_ROWS l.incident_key,l.outcome_class,l.method,
                        SUBSTRING_INDEX(GROUP_CONCAT(l.endpoint_path ORDER BY l.created_at DESC,l.id DESC SEPARATOR "\n"),"\n",1) endpoint_path,
                        CAST(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.http_status,0) ORDER BY l.created_at DESC,l.id DESC SEPARATOR ","),",",1) AS UNSIGNED) http_status,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.error_type,"") ORDER BY l.created_at DESC,l.id DESC SEPARATOR "\n"),"\n",1) error_type,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.error_code,"") ORDER BY l.created_at DESC,l.id DESC SEPARATOR "\n"),"\n",1) error_code,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.safe_message,"") ORDER BY l.created_at DESC,l.id DESC SEPARATOR "\n"),"\n",1) safe_message,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.diagnostic_id,"") ORDER BY l.created_at DESC,l.id DESC SEPARATOR "\n"),"\n",1) diagnostic_id,
                        MIN(l.created_at) first_seen_at,
                        MAX(l.created_at) last_seen_at,COUNT(*) repetitions,
                        CAST(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.reached_remote,0) ORDER BY l.created_at DESC,l.id DESC SEPARATOR ","),",",1) AS UNSIGNED) reached_remote,MAX(l.actionable) actionable,
                        MAX(l.risk_signal) risk_signal,' . $ackSelect . '
                        COUNT(DISTINCT CONCAT(COALESCE(l.scope_kind,"application"),":",COALESCE(l.meli_account_id,l.company_id,0))) account_count,
                        GROUP_CONCAT(DISTINCT CASE
                            WHEN l.meli_account_id IS NOT NULL THEN COALESCE(a.account_name,"Cuenta")
                            WHEN l.scope_kind="company" AND l.company_id IS NOT NULL THEN CONCAT("Empresa: ",COALESCE(c.name,"sin nombre"))
                            ELSE "Aplicación"
                        END ORDER BY l.scope_kind,l.company_id,l.meli_account_id SEPARATOR ", ") account_names
                 FROM api_request_logs l
                 LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
                 LEFT JOIN companies c ON c.id=l.company_id' . $ackJoin . '
                 WHERE ' . implode(' AND ', $where) . '
                 GROUP BY l.incident_key,l.outcome_class,l.method
                 HAVING ' . $having . '
                 ORDER BY last_seen_at DESC,repetitions DESC
                 LIMIT :offset,:limit'
            );
            $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
            $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->lastIncidentTotal = (int) Database::connection()->query('SELECT FOUND_ROWS()')->fetchColumn();
        } catch (Throwable) {
            $this->dataAvailable = false;
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $presented = $this->presentIncident($row);
            $result[] = $presented;
        }
        return $result;
    }

    public function dataAvailable(): bool
    {
        return $this->dataAvailable;
    }

    /** @return array{available:bool,protocol:string,enabled:bool} */
    public function budgetAvailability(): array
    {
        return (new ApiBudgetService())->availability();
    }

    public function lastIncidentTotal(): int
    {
        return $this->lastIncidentTotal;
    }

    /**
     * @param array{hours?:int,account_id?:int,status?:string,origin?:string,severity?:string,operation?:string,http_status?:int} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,limit:int,offset:int,truncated:bool,protocol:string}
     */
    public function incidentPage(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $rows = $this->incidents($filters, $limit, $offset);
        $total = $this->lastIncidentTotal();
        $available = $this->dataAvailable();
        $truncated = $available && $offset + count($rows) < $total;
        return [
            'rows' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'truncated' => $truncated,
            'protocol' => !$available
                ? 'unavailable'
                : ($total === 0 ? 'authoritative_empty' : ($truncated || $offset > 0 ? 'partial' : 'complete')),
        ];
    }

    /**
     * Agrega incidentes activos, recuperados y conteos desde una sola lectura
     * agrupada. Evita repetir el GROUP BY pesado de api_request_logs.
     *
     * @return array{active:list<array<string,mixed>>,recovered:list<array<string,mixed>>,policy:list<array<string,mixed>>,counts:array{active:int,recovered:int,reviewed:int,historical:int},truncated:bool}
     */
    public function incidentOverview(int $hours, ?int $accountId, int $activeLimit, int $recoveredLimit): array
    {
        $filters = ['hours' => max(1, min(720, $hours)), 'origin' => 'all_with_protection'];
        if ($accountId !== null && $accountId > 0) {
            $filters['account_id'] = $accountId;
        }
        $limit = 500;
        $rows = $this->incidents($filters, $limit);
        if (!$this->dataAvailable()) {
            return [
                'active' => [], 'recovered' => [], 'policy' => [],
                'counts' => ['active' => 0, 'recovered' => 0, 'reviewed' => 0, 'historical' => 0],
                'truncated' => false,
            ];
        }
        $counts = ['active' => 0, 'recovered' => 0, 'reviewed' => 0, 'historical' => 0];
        $active = [];
        $recovered = [];
        $policy = [];
        foreach ($rows as $row) {
            $state = (string) ($row['state'] ?? '');
            $isPolicy = (string) ($row['outcome_class'] ?? '') === 'policy_delay'
                || in_array((string) ($row['error_type'] ?? ''), ['api_budget_exhausted', 'api_rhythm_deferred'], true);
            if ($isPolicy && $state === 'active') {
                $policy[] = $row;
                continue;
            }
            if (array_key_exists($state, $counts)) {
                $counts[$state]++;
            }
            if ($state === 'active' && count($active) < max(1, $activeLimit)) {
                $active[] = $row;
            } elseif ($state === 'recovered' && count($recovered) < max(1, $recoveredLimit)) {
                $recovered[] = $row;
            }
        }
        $authoritativeCounts = $this->fastIncidentStateCounts($filters['hours'], $accountId);
        if ($authoritativeCounts !== null) {
            $counts = $authoritativeCounts;
        }
        return [
            'active' => $active,
            'recovered' => $recovered,
            'policy' => $policy,
            'counts' => $counts,
            'truncated' => $this->lastIncidentTotal() > count($rows),
        ];
    }

    /** @return array{active:int,recovered:int,reviewed:int,historical:int}|null */
    private function fastIncidentStateCounts(int $hours, ?int $accountId): ?array
    {
        $materialized = (new ApiIncidentReadModelService())->stateCounts($hours, $accountId);
        if ($materialized !== null) {
            return $materialized;
        }
        if (PHP_SAPI !== 'cli') {
            $this->dataAvailable = false;
            return null;
        }
        try {
            [$scopeSql, $scopeParams] = $this->telemetryScopeSql('l', 'count_scope', $accountId);
            $schema = new SchemaInspectorService();
            $scopedAcks = $schema->hasTable('api_incident_acknowledgements')
                && $schema->hasColumn('api_incident_acknowledgements', 'scope_key');
            $scopeKeySql = 'CASE
                WHEN l.meli_account_id IS NOT NULL THEN CONCAT("account:",l.meli_account_id)
                WHEN l.scope_kind="company" AND l.company_id IS NOT NULL THEN CONCAT("company:",l.company_id)
                ELSE "application" END';
            $ackJoin = $scopedAcks
                ? ' LEFT JOIN api_incident_acknowledgements ack
                      ON ack.incident_key=l.incident_key AND ack.scope_key=' . $scopeKeySql . ' '
                : '';
            $ackValue = $scopedAcks
                ? 'MIN(CASE WHEN ack.acknowledged_through_at>=l.created_at THEN 1 ELSE 0 END)'
                : '0';
            $activeMinutes = $this->activeWindowMinutes();
            $stmt = Database::connection()->prepare(
                'SELECT
                    SUM(acknowledged_all=0 AND outcome_class<>"policy_delay" AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $activeMinutes . ' MINUTE)) active,
                    SUM(acknowledged_all=0 AND outcome_class<>"policy_delay" AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $activeMinutes . ' MINUTE) AND last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)) recovered,
                    SUM(acknowledged_all=1 AND outcome_class<>"policy_delay") reviewed,
                    SUM(acknowledged_all=0 AND outcome_class<>"policy_delay" AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)) historical
                 FROM (
                    SELECT l.incident_key,l.outcome_class,MAX(l.created_at) last_seen_at,' . $ackValue . ' acknowledged_all
                    FROM api_request_logs l' . $ackJoin . '
                    WHERE l.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL :hours HOUR)
                      AND l.incident_key IS NOT NULL
                      AND l.outcome_class NOT IN ("success","expected_absence")
                      AND ' . $scopeSql . '
                    GROUP BY l.incident_key,l.outcome_class
                 ) scoped_incidents'
            );
            $stmt->bindValue(':hours', max(1, min(720, $hours)), PDO::PARAM_INT);
            foreach ($scopeParams as $key => $value) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? [
                'active' => (int) ($row['active'] ?? 0),
                'recovered' => (int) ($row['recovered'] ?? 0),
                'reviewed' => (int) ($row['reviewed'] ?? 0),
                'historical' => (int) ($row['historical'] ?? 0),
            ] : null;
        } catch (Throwable) {
            $this->dataAvailable = false;
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    public function incident(string $key): ?array
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-f0-9]{64}$/', $key) || !$this->supportsOutcomeClassification()) {
            return null;
        }
        $materialized = (new ApiIncidentReadModelService())->byKey($key);
        if (is_array($materialized)) {
            if ($materialized === []) {
                return null;
            }
            $incident = $this->presentIncident($materialized[0]);
            $incident['accounts'] = array_map(static fn(array $row): array => [
                'meli_account_id' => $row['meli_account_id'] ?? null,
                'company_id' => $row['company_id'] ?? null,
                'scope_kind' => $row['scope_kind'] ?? 'application',
                'scope_key' => $row['scope_key'] ?? 'application',
                'account_name' => $row['account_names'] ?? 'Aplicación',
                'acknowledged_all' => (int) ($row['acknowledged_all'] ?? 0),
                'repetitions' => (int) ($row['repetitions'] ?? 0),
                'first_seen_at' => $row['first_seen_at'] ?? null,
                'last_seen_at' => $row['last_seen_at'] ?? null,
            ], $materialized);
            $incident['samples'] = array_map(static fn(array $row): array => [
                'created_at' => $row['last_seen_at'] ?? null,
                'account_name' => $row['account_names'] ?? 'Aplicación',
                'method' => $row['method'] ?? null,
                'endpoint_path' => $row['endpoint_path'] ?? null,
                'http_status' => $row['http_status'] ?? null,
                'error_type' => $row['error_type'] ?? null,
                'error_code' => $row['error_code'] ?? null,
                'safe_message' => $row['safe_message'] ?? null,
                'diagnostic_id' => $row['diagnostic_id'] ?? null,
                'request_id' => null,
            ], $materialized);
            $incident['acknowledgement'] = (int) ($materialized[0]['acknowledged_all'] ?? 0) === 1
                ? $this->incidentAcknowledgement($key)
                : null;
            return $incident;
        }
        if (PHP_SAPI !== 'cli') {
            $this->dataAvailable = false;
            return null;
        }
        $incidents = $this->incidentsByKey($key);
        if ($incidents === []) {
            return null;
        }
        $incident = $this->presentIncident($incidents[0]);
        $incident['accounts'] = $this->incidentAccounts($key);
        $incident['samples'] = $this->incidentSamples($key);
        $allScopedEvidenceAcknowledged = (int) ($incidents[0]['acknowledged_all'] ?? 0) === 1;
        $incident['acknowledgement'] = $allScopedEvidenceAcknowledged
            ? $this->incidentAcknowledgement($key)
            : null;
        return $incident;
    }

    /** @return array<string,mixed>|null */
    private function incidentAcknowledgement(string $key): ?array
    {
        try {
            $schema = new SchemaInspectorService();
            if (!$schema->hasTable('api_incident_acknowledgements')) {
                return null;
            }
            if ($schema->hasColumn('api_incident_acknowledgements', 'scope_key')) {
                $keys = (new ApiHealthAccessScope())->acknowledgementKeys();
                if ($keys === []) {
                    return null;
                }
                $tokens = implode(',', array_fill(0, count($keys), '?'));
                $stmt = Database::connection()->prepare(
                    'SELECT MAX(a.acknowledged_at) acknowledged_at,
                            MAX(a.acknowledged_through_at) acknowledged_through_at,
                            MAX(a.note) note,MAX(u.name) acknowledged_by_name
                     FROM api_incident_acknowledgements a
                     LEFT JOIN users u ON u.id=a.acknowledged_by
                     WHERE a.incident_key=? AND a.scope_key IN (' . $tokens . ')
                     HAVING acknowledged_at IS NOT NULL'
                );
                $stmt->execute(array_merge([$key], $keys));
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return is_array($row) ? $row : null;
            }
            $stmt = Database::connection()->prepare(
                'SELECT a.acknowledged_at,a.note,u.name acknowledged_by_name
                 FROM api_incident_acknowledgements a
                 LEFT JOIN users u ON u.id=a.acknowledged_by
                 WHERE a.incident_key=? LIMIT 1'
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            $this->dataAvailable = false;
            return null;
        }
    }

    public function accounts(): array
    {
        try {
            $scope = (new BusinessScopeContext())->accountPredicate('id');
            $stmt = Database::connection()->prepare('SELECT id,company_id,account_name,status FROM meli_accounts WHERE ' . $scope['sql'] . ' ORDER BY account_name');
            $stmt->execute($scope['params']);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $this->dataAvailable = false;
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function accountStats(int $hours = 24, ?int $accountId = null): array
    {
        return $this->stats(max(1, min(720, $hours)), $accountId);
    }

    /** @return array<string,int> */
    public function activeSignalCounts(?int $accountId = null): array
    {
        return $this->activeSignals($accountId);
    }

    public function classificationAvailable(): bool
    {
        return $this->supportsOutcomeClassification();
    }

    /** @return array{active:int,recovered:int,reviewed:int,historical:int} */
    public function incidentStatusCounts(int $hours = 24, ?int $accountId = null): array
    {
        if (!$this->supportsOutcomeClassification()) {
            return ['active' => 0, 'recovered' => 0, 'reviewed' => 0, 'historical' => 0];
        }
        if ($accountId !== null && $accountId > 0) {
            (new BusinessScopeContext())->account($accountId);
        }
        $filters = ['hours' => max(1, min(720, $hours))];
        if ($accountId !== null && $accountId > 0) {
            $filters['account_id'] = $accountId;
        }
        $counts = ['active' => 0, 'recovered' => 0, 'reviewed' => 0, 'historical' => 0];
        foreach (array_keys($counts) as $state) {
            $stateFilters = $filters;
            $stateFilters['status'] = $state;
            $this->incidents($stateFilters, 1);
            $counts[$state] = $this->lastIncidentTotal();
        }
        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function recentSuccessfulActivity(int $hours = 24, int $limit = 5, ?int $accountId = null): array
    {
        if (!$this->supportsOutcomeClassification()) {
            return [];
        }
        if (PHP_SAPI !== 'cli') {
            try {
                $access = (new ApiHealthAccessScope())->snapshot($accountId);
                $snapshot = (new \App\QueueV4Clean\QueueV4CleanHealthSnapshotService(
                    Database::connectionFresh()
                ))->snapshot($accountId, $access['company_ids'], $access['account_ids'], $hours);
                return array_slice((array) ($snapshot['recent_activity'] ?? []), 0, max(1, min(20, $limit)));
            } catch (Throwable) {
                $this->dataAvailable = false;
                return [];
            }
        }
        try {
            if ($accountId !== null && $accountId > 0) {
                (new BusinessScopeContext())->account($accountId);
            }
            [$scopeSql, $scopeParams] = $this->telemetryScopeSql('l', 'activity_scope', $accountId);
            $stmt = Database::connection()->prepare(
                'SELECT l.created_at,l.method,l.endpoint_path,l.meli_account_id,a.account_name
                 FROM api_request_logs l
                 LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
                 WHERE l.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL :hours HOUR)
                   AND l.outcome_class="success" AND ' . $scopeSql . '
                 ORDER BY l.created_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':hours', max(1, min(720, $hours)), PDO::PARAM_INT);
            foreach ($scopeParams as $key => $value) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            }
            $stmt->bindValue(':limit', max(1, min(20, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $this->dataAvailable = false;
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function exportRows(int $hours = 24): array
    {
        try {
            $classified = $this->supportsOutcomeClassification();
            $extra = $classified
                ? ',l.outcome_class,l.reached_remote,l.actionable,l.risk_signal,l.incident_key'
                : '';
            [$scopeSql, $scopeParams] = $this->telemetryScopeSql('l', 'export_scope');
            $stmt = Database::connection()->prepare(
                'SELECT l.created_at,a.account_name,l.method,l.endpoint_path,l.http_status,l.error_type,l.error_code,l.retry_after_seconds,l.attempt,l.was_blocked,l.safe_message' . $extra . '
                 FROM api_request_logs l
                 LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
                 WHERE l.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL :hours HOUR) AND ' . $scopeSql . '
                 ORDER BY l.created_at DESC
                 LIMIT 5000'
            );
            $stmt->bindValue(':hours', max(1, min(720, $hours)), PDO::PARAM_INT);
            foreach ($scopeParams as $key => $value) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                if ($classified) {
                    $row['transport_class'] = $this->transportClass(
                        (int) ($row['reached_remote'] ?? 0) === 1,
                        (int) ($row['http_status'] ?? 0),
                        (string) ($row['outcome_class'] ?? '')
                    );
                }
                $row = Logger::redact($row);
                $presented = (new ApiHealthSafeMessageService())->present(
                    (string) ($row['safe_message'] ?? ''),
                    (string) ($row['error_code'] ?? '')
                );
                $row['safe_message'] = $presented['safe_message'] ?? '';
                $row['error_code'] = $presented['normalized_error_code'] ?? '';
                foreach ($row as $key => $value) {
                    if (is_string($value)) {
                        $row[$key] = $this->csvSafe($value);
                    }
                }
            }
            return $rows;
        } catch (Throwable) {
            $this->dataAvailable = false;
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function stats(int $hours, ?int $accountId = null): array
    {
        if (PHP_SAPI !== 'cli') {
            try {
                $access = (new ApiHealthAccessScope())->snapshot($accountId);
                $snapshot = (new \App\QueueV4Clean\QueueV4CleanHealthSnapshotService(
                    Database::connectionFresh()
                ))->snapshot($accountId, $access['company_ids'], $access['account_ids'], $hours);
                return (array) ($snapshot['account_stats'] ?? []);
            } catch (Throwable) {
                $this->dataAvailable = false;
                return [];
            }
        }
        try {
            $activeWindowMinutes = $this->activeWindowMinutes();
            [$scopeSql, $scopeParams] = $this->telemetryScopeSql('l', 'stats_scope', $accountId);
            $hasCorrections = (new SchemaInspectorService())->hasTable('api_health_correction_events');
            $correctionJoin = $hasCorrections
                ? ' LEFT JOIN (
                        SELECT api_request_log_id,
                               MAX(corrected_outcome_class="success") corrected_success
                        FROM api_health_correction_events
                        GROUP BY api_request_log_id
                    ) hc ON hc.api_request_log_id=l.id '
                : '';
            $acceptedSuccess = $hasCorrections
                ? '(l.outcome_class="success" OR COALESCE(hc.corrected_success,0)=1)'
                : '(l.outcome_class="success")';
            $correctedSuccess = $hasCorrections
                ? 'SUM(l.outcome_class<>"success" AND COALESCE(hc.corrected_success,0)=1)'
                : '0';
            $stmt = Database::connection()->prepare(
                'SELECT l.meli_account_id,a.account_name,COUNT(*) attempts,
                        SUM(l.reached_remote=1) sent,
                        SUM(' . $acceptedSuccess . ') successful,
                        SUM(l.outcome_class="success") raw_successful,
                        ' . $correctedSuccess . ' corrected_successful,
                        SUM(l.outcome_class="remote_error") remote_errors,
                        SUM(l.outcome_class="local_failure") local_failures,
                        SUM(l.outcome_class="policy_delay") policy_delays,
                        SUM(l.outcome_class="expected_absence") expected_absence,
                        SUM(l.reached_remote=1 AND l.http_status=400) http_400,
                        SUM(l.reached_remote=1 AND l.http_status=401) http_401,
                        SUM(l.reached_remote=1 AND l.http_status=403) http_403,
                        SUM(l.reached_remote=1 AND l.http_status=429) http_429,
                        SUM(l.reached_remote=1 AND l.http_status>=500) http_5xx,
                        COUNT(DISTINCT CASE WHEN l.incident_key IS NOT NULL AND l.actionable=1 THEN l.incident_key END) incident_count,
                        COUNT(DISTINCT CASE WHEN l.incident_key IS NOT NULL AND l.actionable=1 AND l.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL :active_minutes_all MINUTE) THEN l.incident_key END) active_incident_count,
                        COUNT(DISTINCT CASE WHEN l.incident_key IS NOT NULL AND l.actionable=1 AND l.reached_remote=1 AND l.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL :active_minutes_remote MINUTE) THEN l.incident_key END) active_remote_incident_count,
                        COUNT(DISTINCT CASE WHEN l.incident_key IS NOT NULL AND l.actionable=1 AND l.reached_remote=0 AND l.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL :active_minutes_local MINUTE) THEN l.incident_key END) active_local_incident_count,
                        MAX(l.created_at) last_request_at,
                        MAX(CASE WHEN l.reached_remote=1 THEN l.created_at END) last_remote_attempt_at,
                        MAX(CASE WHEN l.reached_remote=1 AND ' . $acceptedSuccess . ' THEN l.created_at END) last_remote_success_at,
                        MAX(CASE WHEN l.outcome_class="policy_delay" THEN l.created_at END) last_policy_delay_at
                 FROM api_request_logs l
                 LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
                 ' . $correctionJoin . '
                 WHERE l.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL :hours HOUR) AND ' . $scopeSql . '
                 GROUP BY l.meli_account_id,a.account_name
                 ORDER BY remote_errors DESC,local_failures DESC,sent DESC'
            );
            $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
            // Native PDO MySQL does not support reusing the same named placeholder.
            // Keep one binding for every occurrence so Salud API cannot silently fall
            // back to "No se pudo comprobar" while recent activity still succeeds.
            $stmt->bindValue(':active_minutes_all', $activeWindowMinutes, PDO::PARAM_INT);
            $stmt->bindValue(':active_minutes_remote', $activeWindowMinutes, PDO::PARAM_INT);
            $stmt->bindValue(':active_minutes_local', $activeWindowMinutes, PDO::PARAM_INT);
            foreach ($scopeParams as $key => $value) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $this->dataAvailable = false;
            return [];
        }
    }

    /** @return array<string,int> */
    private function activeSignals(?int $accountId = null): array
    {
        if (PHP_SAPI !== 'cli') {
            $signals = (new ApiIncidentReadModelService())->signalCounts($accountId);
            if ($signals === null) {
                $this->dataAvailable = false;
                return [];
            }
            return $signals;
        }
        try {
            $activeWindowMinutes = $this->activeWindowMinutes();
            [$scopeSql, $scopeParams] = $this->telemetryScopeSql('api_request_logs', 'signal_scope', $accountId);
            $stmt = Database::connection()->prepare(
                'SELECT
                    SUM(outcome_class="blocked_signal") blocked_signals,
                    SUM(reached_remote=1 AND http_status=400) bad_requests,
                    SUM(reached_remote=1 AND http_status=401) unauthorized,
                    SUM(reached_remote=1 AND http_status=403) forbidden,
                    SUM(reached_remote=1 AND http_status=429) rate_limited
                 FROM api_request_logs
                 WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $activeWindowMinutes . ' MINUTE) AND ' . $scopeSql
            );
            $stmt->execute($scopeParams);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? array_map('intval', $row) : [];
        } catch (Throwable) {
            $this->dataAvailable = false;
            return [];
        }
    }

    private function activeWindowMinutes(): int
    {
        return max(5, min(120, (new AppSettingsService())->int('api.health.active_window_minutes', 15)));
    }

    /** @return list<array<string,mixed>> */
    private function incidentsByKey(string $key): array
    {
        try {
            [$scopeSql, $scopeParams] = $this->telemetryScopeSql('l', 'key_scope');
            $schema = new SchemaInspectorService();
            $hasAcknowledgements = $schema->hasTable('api_incident_acknowledgements');
            $hasScopedAcknowledgements = $hasAcknowledgements && $schema->hasColumn('api_incident_acknowledgements', 'scope_key');
            $scopeKeySql = 'CASE
                WHEN l.meli_account_id IS NOT NULL THEN CONCAT("account:",l.meli_account_id)
                WHEN l.scope_kind="company" AND l.company_id IS NOT NULL THEN CONCAT("company:",l.company_id)
                ELSE "application" END';
            $ackSelect = $hasScopedAcknowledgements
                ? 'MAX(ack.acknowledged_at) acknowledged_at,
                   MIN(CASE WHEN ack.acknowledged_through_at>=l.created_at THEN 1 ELSE 0 END) acknowledged_all,'
                : ($hasAcknowledgements
                    ? 'MAX(ack.acknowledged_at) acknowledged_at,
                       MAX(CASE WHEN ack.acknowledged_at IS NOT NULL THEN 1 ELSE 0 END) acknowledged_all,'
                    : 'NULL acknowledged_at,0 acknowledged_all,');
            $ackJoin = $hasScopedAcknowledgements
                ? ' LEFT JOIN api_incident_acknowledgements ack
                      ON ack.incident_key=l.incident_key AND ack.scope_key=' . $scopeKeySql . ' '
                : ($hasAcknowledgements
                    ? ' LEFT JOIN api_incident_acknowledgements ack ON ack.incident_key=l.incident_key '
                    : '');
            $stmt = Database::connection()->prepare(
                'SELECT l.incident_key,l.outcome_class,l.method,
                        SUBSTRING_INDEX(GROUP_CONCAT(l.endpoint_path ORDER BY l.created_at DESC,l.id DESC SEPARATOR "\n"),"\n",1) endpoint_path,
                        CAST(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.http_status,0) ORDER BY l.created_at DESC,l.id DESC SEPARATOR ","),",",1) AS UNSIGNED) http_status,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.error_type,"") ORDER BY l.created_at DESC,l.id DESC SEPARATOR "\n"),"\n",1) error_type,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.error_code,"") ORDER BY l.created_at DESC,l.id DESC SEPARATOR "\n"),"\n",1) error_code,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.safe_message,"") ORDER BY l.created_at DESC,l.id DESC SEPARATOR "\n"),"\n",1) safe_message,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.diagnostic_id,"") ORDER BY l.created_at DESC,l.id DESC SEPARATOR "\n"),"\n",1) diagnostic_id,
                        MIN(l.created_at) first_seen_at,
                        MAX(l.created_at) last_seen_at,COUNT(*) repetitions,
                        CAST(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(l.reached_remote,0) ORDER BY l.created_at DESC,l.id DESC SEPARATOR ","),",",1) AS UNSIGNED) reached_remote,MAX(l.actionable) actionable,
                        MAX(l.risk_signal) risk_signal,' . $ackSelect . '
                        COUNT(DISTINCT CONCAT(COALESCE(l.scope_kind,"application"),":",COALESCE(l.meli_account_id,l.company_id,0))) account_count,
                        GROUP_CONCAT(DISTINCT CASE
                            WHEN l.meli_account_id IS NOT NULL THEN COALESCE(a.account_name,"Cuenta")
                            WHEN l.scope_kind="company" AND l.company_id IS NOT NULL THEN CONCAT("Empresa: ",COALESCE(c.name,"sin nombre"))
                            ELSE "Aplicación"
                        END ORDER BY l.scope_kind,l.company_id,l.meli_account_id SEPARATOR ", ") account_names
                 FROM api_request_logs l
                 LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
                 LEFT JOIN companies c ON c.id=l.company_id' . $ackJoin . '
                 WHERE l.incident_key=:incident_key AND ' . $scopeSql . '
                 GROUP BY l.incident_key,l.outcome_class,l.method
                 LIMIT 1'
            );
            $stmt->execute(array_merge(['incident_key' => $key], $scopeParams));
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $this->dataAvailable = false;
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function incidentAccounts(string $key): array
    {
        try {
            [$scopeSql, $scopeParams] = $this->telemetryScopeSql('l', 'account_scope');
            $scopeKeySql = 'CASE
                WHEN l.meli_account_id IS NOT NULL THEN CONCAT("account:",l.meli_account_id)
                WHEN l.scope_kind="company" AND l.company_id IS NOT NULL THEN CONCAT("company:",l.company_id)
                ELSE "application" END';
            $schema = new SchemaInspectorService();
            $hasScopedAcknowledgements = $schema->hasTable('api_incident_acknowledgements')
                && $schema->hasColumn('api_incident_acknowledgements', 'scope_key');
            $ackJoin = $hasScopedAcknowledgements
                ? ' LEFT JOIN api_incident_acknowledgements ack
                      ON ack.incident_key=l.incident_key AND ack.scope_key=' . $scopeKeySql . ' '
                : '';
            $ackSelect = $hasScopedAcknowledgements
                ? 'MIN(CASE WHEN ack.acknowledged_through_at>=l.created_at THEN 1 ELSE 0 END) acknowledged_all,'
                : '0 acknowledged_all,';
            $stmt = Database::connection()->prepare(
                'SELECT l.meli_account_id,l.company_id,l.scope_kind,' . $scopeKeySql . ' scope_key,
                        CASE
                            WHEN l.meli_account_id IS NOT NULL THEN COALESCE(a.account_name,"Cuenta")
                            WHEN l.scope_kind="company" AND l.company_id IS NOT NULL THEN CONCAT("Empresa: ",COALESCE(c.name,"sin nombre"))
                            ELSE "Aplicación"
                        END account_name,
                        ' . $ackSelect . '
                        COUNT(*) repetitions,MIN(l.created_at) first_seen_at,MAX(l.created_at) last_seen_at
                 FROM api_request_logs l
                 LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
                 LEFT JOIN companies c ON c.id=l.company_id' . $ackJoin . '
                 WHERE l.incident_key=:incident_key AND ' . $scopeSql . '
                 GROUP BY l.meli_account_id,l.company_id,l.scope_kind,a.account_name,c.name
                 ORDER BY repetitions DESC'
            );
            $stmt->execute(array_merge(['incident_key' => $key], $scopeParams));
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $this->dataAvailable = false;
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function incidentSamples(string $key): array
    {
        try {
            [$scopeSql, $scopeParams] = $this->telemetryScopeSql('l', 'sample_scope');
            $stmt = Database::connection()->prepare(
                'SELECT l.created_at,a.account_name,l.method,l.endpoint_path,l.http_status,l.error_type,l.error_code,l.safe_message,l.diagnostic_id,l.request_id
                 FROM api_request_logs l
                 LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
                 WHERE l.incident_key=:incident_key AND ' . $scopeSql . '
                 ORDER BY l.created_at DESC
                 LIMIT 20'
            );
            $stmt->execute(array_merge(['incident_key' => $key], $scopeParams));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row = Logger::redact($row);
                $row['safe_message'] = $this->sanitize((string) ($row['safe_message'] ?? ''));
            }
            return $rows;
        } catch (Throwable) {
            $this->dataAvailable = false;
            return [];
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function presentIncident(array $row): array
    {
        $outcome = (string) ($row['outcome_class'] ?? 'local_failure');
        $lastSeen = (string) ($row['last_seen_at'] ?? '');
        try {
            $lastSeenTimestamp = $lastSeen !== ''
                ? (new DateTimeImmutable($lastSeen, new DateTimeZone('UTC')))->getTimestamp()
                : 0;
        } catch (Throwable) {
            $lastSeenTimestamp = 0;
        }
        $active = $lastSeenTimestamp >= time() - ($this->activeWindowMinutes() * 60);
        $acknowledged = (int) ($row['acknowledged_all'] ?? (!empty($row['acknowledged_at']) ? 1 : 0)) === 1;
        $state = $acknowledged
            ? 'reviewed'
            : ($active ? 'active' : ($lastSeenTimestamp < time() - 604800 ? 'historical' : 'recovered'));
        $httpStatus = isset($row['http_status']) ? (int) $row['http_status'] : 0;
        $errorType = (string) ($row['error_type'] ?? '');
        $message = (string) ($row['safe_message'] ?? '');
        $lower = mb_strtolower($message);
        $reachedRemote = (int) ($row['reached_remote'] ?? 0) === 1;

        $transportClass = $this->transportClass($reachedRemote, $httpStatus, $outcome);
        $isRateLimit = $transportClass === 'REMOTE_HTTP_429';
        $isPermissionOrAuth = $reachedRemote && in_array($httpStatus, [401, 403], true);
        $isServerError = $reachedRemote && $httpStatus >= 500;
        $hasRiskSignal = (int) ($row['risk_signal'] ?? 0) === 1;
        $signalRequiresProtection = $isRateLimit || $isPermissionOrAuth || $outcome === 'blocked_signal' || $hasRiskSignal;
        $signalLabel = match (true) {
            $isRateLimit => 'Rate limit de Mercado Libre',
            $outcome === 'blocked_signal' => 'Señal de bloqueo o autorización',
            $httpStatus === 401 => 'OAuth/autorización inválida',
            $httpStatus === 403 => 'Permiso o alcance no disponible',
            $isServerError => 'Falla temporal de Mercado Libre',
            $outcome === 'policy_delay' => 'Pausa preventiva local · sin HTTP remoto',
            $outcome === 'local_failure' => 'Fallo interno del ERP',
            $httpStatus === 404 => 'Recurso no encontrado',
            default => $reachedRemote ? 'Respuesta remota con error' : 'Evento interno',
        };

        $title = match (true) {
            str_contains($lower, 'already an active transaction') => 'Conflicto interno al renovar autorización',
            $outcome === 'policy_delay' => 'Consulta aplazada por protección preventiva',
            $outcome === 'blocked_signal' => 'Señal de bloqueo o autorización',
            $httpStatus === 429 => 'Rate limit de Mercado Libre',
            $httpStatus === 403 => 'Permiso no disponible para esta operación',
            $httpStatus === 401 => 'Autorización de cuenta no válida',
            $httpStatus >= 500 => 'Falla temporal de Mercado Libre',
            $outcome === 'local_failure' => 'Problema interno antes de consultar Mercado Libre',
            default => 'Respuesta con problema de Mercado Libre',
        };
        $severity = match (true) {
            $outcome === 'blocked_signal' || $httpStatus === 401 => 'critical',
            $httpStatus === 429 || $httpStatus === 403 => 'high',
            $outcome === 'local_failure' => 'medium',
            default => 'low',
        };
        $recommendation = match (true) {
            str_contains($lower, 'already an active transaction') => 'No pause las cuentas. La solicitud no llegó a Mercado Libre; continúe observando si vuelve a aparecer.',
            $outcome === 'policy_delay' => 'Espere la hora segura indicada; el trabajo continuará mediante cron.',
            $httpStatus === 429 => 'Respete Retry-After o la próxima hora segura. Baje o mantenga limitado el ritmo efectivo de la cuenta/endpoint y no fuerce reintentos.',
            $httpStatus === 403 => 'Revise permisos de la cuenta y la capacidad de la operación.',
            $httpStatus === 401 => 'Revise la conexión OAuth de la cuenta afectada.',
            $httpStatus >= 500 => 'Permita que el cron reintente con espera progresiva.',
            $outcome === 'local_failure' => 'Revise el diagnóstico interno del ERP; no es necesario pausar Mercado Libre.',
            default => 'Revise la operación y el diagnóstico técnico antes de reintentar.',
        };
        $impact = match (true) {
            $isRateLimit => 'Mercado Libre respondió 429. El ERP debe respetar la espera y limitar temporalmente esa cuenta/endpoint.',
            $reachedRemote => 'Mercado Libre respondió a la consulta; la operación afectada puede requerir revisión.',
            default => 'La consulta se detuvo dentro del ERP y no consumió una llamada de Mercado Libre.',
        };

        return array_merge($row, [
            'state' => $state,
            'title' => $title,
            'severity' => $severity,
            'reached_remote' => $reachedRemote ? 1 : 0,
            'active_now' => $active,
            'risk_when_active' => $hasRiskSignal,
            'blocking_risk' => $active && $hasRiskSignal,
            'signal_requires_protection' => $signalRequiresProtection,
            'rate_limit_signal' => $isRateLimit,
            'transport_class' => $transportClass,
            'transport_label' => match ($transportClass) {
                'REMOTE_HTTP_429' => 'Mercado Libre respondió HTTP 429',
                'LOCAL_RATE_LIMITED_PRETRANSPORT' => 'Pausa preventiva local · sin HTTP remoto',
                default => $reachedRemote ? 'Respuesta remota' : 'Sin transporte remoto',
            },
            'signal_label' => $signalLabel,
            'risk_explanation' => $signalRequiresProtection
                ? ($active
                    ? 'La señal está activa y requiere protección operativa.'
                    : 'La señal ya no está activa, pero queda como evidencia de protección para no subir ritmo sin estabilidad.')
                : 'No es una señal de rate limit, autorización o bloqueo.',
            'recommendation' => $recommendation,
            'impact' => $impact,
            'operation_label' => UiLabelPresenter::apiOperation((string) ($row['method'] ?? ''), (string) ($row['endpoint_path'] ?? '')),
            'safe_message' => $this->sanitize($message),
            'error_type' => $errorType,
        ]);
    }

    private function transportClass(bool $reachedRemote, int $httpStatus, string $outcome): string
    {
        if ($reachedRemote && $httpStatus === 429) {
            return 'REMOTE_HTTP_429';
        }
        if (!$reachedRemote && $outcome === 'policy_delay') {
            return 'LOCAL_RATE_LIMITED_PRETRANSPORT';
        }
        return 'OTHER';
    }

    private function supportsOutcomeClassification(): bool
    {
        $schema = new SchemaInspectorService();
        foreach (['outcome_class', 'reached_remote', 'actionable', 'risk_signal', 'incident_key'] as $column) {
            if (!$schema->hasColumn('api_request_logs', $column)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function legacySummary(int $hours, ?int $accountId = null): array
    {
        $guard = new ApiGuardService();
        $legacyIds = $accountId !== null ? [$accountId] : $this->authorizedAccountIds();
        $circuits = $guard->openCircuits(100, $legacyIds);
        if (!$guard->readAvailable()) {
            $this->dataAvailable = false;
        }
        $stats = [];
        try {
            [$scopeSql, $scopeParams] = $this->accountScopeSql('l.meli_account_id', 'legacy_scope_', $accountId);
            $stmt = Database::connection()->prepare(
                'SELECT l.meli_account_id,a.account_name,COUNT(*) attempts,COUNT(*) total,
                        SUM(l.http_status>=400 OR l.was_blocked=1) errors,
                        SUM(l.http_status=400) http_400,SUM(l.http_status=401) http_401,
                        SUM(l.http_status=403) http_403,SUM(l.http_status=429) http_429,
                        SUM(l.http_status>=500) http_5xx,MAX(l.created_at) last_request_at
                 FROM api_request_logs l
                 LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
                 WHERE l.created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL :hours HOUR) AND ' . $scopeSql . '
                 GROUP BY l.meli_account_id,a.account_name'
            );
            $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
            foreach ($scopeParams as $key => $value) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            }
            $stmt->execute();
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $this->dataAvailable = false;
        }
        $errors = array_sum(array_map(static fn(array $row): int => (int) ($row['errors'] ?? 0), $stats));
        $requests = array_sum(array_map(static fn(array $row): int => (int) ($row['attempts'] ?? 0), $stats));
        return [
            'risk' => !$this->dataAvailable ? 'unknown' : ($errors > 0 || $circuits !== [] ? 'medium' : 'low'),
            'data_available' => $this->dataAvailable,
            'requests' => $requests,
            'errors' => $errors,
            'attempts' => $requests,
            'sent' => $requests,
            'successful' => max(0, $requests - $errors),
            'remote_errors' => $errors,
            'local_failures' => 0,
            'policy_delays' => 0,
            'expected_absence' => 0,
            'active_incident_count' => 0,
            'recovered_incident_count' => 0,
            'open_circuits' => $circuits,
            'stats' => $stats,
            'incidents' => [],
            'problem_endpoints' => [],
            'budget' => $this->scopedBudgetSummary($accountId),
            'classified' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function unavailableMaterializedSummary(): array
    {
        $this->dataAvailable = false;
        return [
            'risk' => 'unknown',
            'data_available' => false,
            'requests' => 0,
            'errors' => 0,
            'attempts' => 0,
            'sent' => 0,
            'successful' => 0,
            'raw_successful' => 0,
            'corrected_successful' => 0,
            'remote_errors' => 0,
            'local_failures' => 0,
            'policy_delays' => 0,
            'expected_absence' => 0,
            'active_incident_count' => 0,
            'recovered_incident_count' => 0,
            'open_circuits' => [],
            'stats' => [],
            'incidents' => [],
            'problem_endpoints' => [],
            'budget' => ['available' => false],
            'classified' => false,
            'snapshot_state' => 'unavailable',
            'safe_message' => 'El modelo materializado de Salud API no está disponible. No se consultaron logs crudos.',
        ];
    }

    private function sanitize(string $value): string
    {
        return (new ApiHealthSafeMessageService())->present($value)['safe_message'];
    }

    private function csvSafe(string $value): string
    {
        $value = str_replace("\0", '', $value);
        return preg_match('/^[=+\-@]/u', ltrim($value)) === 1 ? "'" . $value : $value;
    }

    /** @return list<int> */
    private function authorizedAccountIds(): array
    {
        return (new BusinessScopeContext())->accountIds();
    }

    /** @return array{0:string,1:array<string,int>} */
    private function accountScopeSql(string $column, string $prefix, ?int $accountId = null): array
    {
        $ids = $accountId !== null && $accountId > 0 ? [$accountId] : $this->authorizedAccountIds();
        if ($ids === []) {
            return ['1=0', []];
        }
        $params = [];
        $tokens = [];
        foreach ($ids as $index => $id) {
            $key = $prefix . $index;
            $tokens[] = ':' . $key;
            $params[$key] = $id;
        }
        return [$column . ' IN (' . implode(',', $tokens) . ')', $params];
    }

    public function canAcknowledgeIncident(string $key): bool
    {
        return $this->incident($key) !== null;
    }

    /** @return array<string,mixed> */
    private function scopedBudgetSummary(?int $accountId = null): array
    {
        $ids = $accountId !== null && $accountId > 0 ? [$accountId] : $this->authorizedAccountIds();
        return (new ApiBudgetService())->summary(80, $ids);
    }

    /** @return array{0:string,1:array<string,int>} */
    private function telemetryScopeSql(string $alias, string $prefix, ?int $accountId = null): array
    {
        if ((new SchemaInspectorService())->hasColumn('api_request_logs', 'scope_kind')) {
            $scope = (new ApiHealthAccessScope())->predicate($alias, $prefix, $accountId);
            return [$scope['sql'], $scope['params']];
        }
        return $this->accountScopeSql($alias . '.meli_account_id', $prefix . '_legacy_', $accountId);
    }
}
