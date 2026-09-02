<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanHealthSnapshotService;
use PDO;
use Throwable;

/**
 * Read-only, bounded evidence for the Cron risk card.
 *
 * This deliberately reads the transport telemetry rather than incident groups:
 * the card must remain truthful while the incident materializer catches up.
 */
final class CronApiRiskSummaryService
{
    private const HOURS = 720;
    private const TOP_LIMIT = 10;
    private const WINDOWS = ['60m' => 1, '24h' => 24, '30d' => 720];

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $measuredAt = gmdate('Y-m-d H:i:s');
        try {
            $schema = new SchemaInspectorService();
            $missing = $schema->missingRequirements([
                'api_request_logs' => [
                    'id', 'company_id', 'meli_account_id', 'scope_kind', 'method', 'endpoint_path',
                    'http_status', 'error_type', 'outcome_class', 'reached_remote', 'created_at',
                ],
            ]);
            if ($missing !== []) {
                return $this->unavailable($measuredAt, 'La telemetría necesaria no está disponible.');
            }

            $scope = new ApiHealthAccessScope();
            $predicate = $scope->predicate('l', 'cron_risk');
            $pdo = Database::connectionFresh();
            $where = 'l.created_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL :hours HOUR) AND ' . $predicate['sql'];
            $params = ['hours' => self::HOURS] + $predicate['params'];

            $totals = $this->totals($pdo, $where, $params);
            $windows = $this->windowTotals($pdo, $predicate['sql'], $predicate['params']);
            $top = $this->topSignals($pdo, $where, $params);
            $recent = $this->recentSignals($pdo, $where, $params);
            $queue = $this->queueEvidence($scope);
            $billing = $this->billingBreaker($schema, $pdo);
            $materializer = (new ApiIncidentReadModelService())->freshness();

            return [
                'ok' => true,
                'read_only' => true,
                'source' => 'api_request_logs_direct',
                'source_label' => 'Telemetría directa de transporte · últimos 30 días',
                'measured_at' => $measuredAt,
                'hours' => self::HOURS,
                'authoritative' => true,
                'totals' => $totals,
                'windows' => $windows,
                'top' => $top,
                'recent' => $recent,
                'billing' => $billing,
                'worker' => $queue,
                'materializer' => [
                    'current' => (bool) ($materializer['current'] ?? false),
                    'last_log_id' => (int) ($materializer['last_log_id'] ?? 0),
                    'latest_log_id' => (int) ($materializer['latest_log_id'] ?? 0),
                    'lag' => max(0, (int) ($materializer['latest_log_id'] ?? 0) - (int) ($materializer['last_log_id'] ?? 0)),
                ],
                'links' => [
                    'remote_429' => '/settings/api-health/incidents?hours=720&http_status=429&origin=remote',
                    'local_pretransport' => '/settings/api-health/incidents?hours=720&origin=protection',
                    'technical' => '/settings/api-health/technical',
                    'cron' => '/settings/cron',
                ],
            ];
        } catch (Throwable) {
            return $this->unavailable($measuredAt, 'No se pudo certificar Riesgos API. No se modificó Cron ni la cola.');
        }
    }

    /** @param array<string,int> $scopeParams @return array<string,array<string,int|string|null>> */
    private function windowTotals(PDO $pdo, string $scopeSql, array $scopeParams): array
    {
        $result = [];
        foreach (self::WINDOWS as $label => $hours) {
            $where = 'l.created_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL :hours HOUR) AND ' . $scopeSql;
            $params = ['hours' => $hours] + $scopeParams;
            $result[$label] = $this->totals($pdo, $where, $params);
        }
        return $result;
    }

    /** @param array<string,int> $params @return array<string,int> */
    private function totals(PDO $pdo, string $where, array $params): array
    {
        $statement = $pdo->prepare(
            'SELECT
                COALESCE(SUM(l.reached_remote=1 AND l.http_status=429),0) remote_http_429,
                COALESCE(SUM(l.reached_remote=0 AND l.outcome_class="policy_delay"),0) local_rate_limited_pretransport,
                COALESCE(SUM(l.reached_remote=1 AND l.http_status IN (401,403)),0) oauth_critical,
                COALESCE(SUM(l.reached_remote=1 AND l.http_status>=500),0) http_5xx,
                COALESCE(SUM(l.outcome_class="remote_result_uncertain"),0) remote_uncertain,
                MAX(l.created_at) latest_telemetry_at
             FROM api_request_logs l WHERE ' . $where
        );
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'remote_http_429' => (int) ($row['remote_http_429'] ?? 0),
            'local_rate_limited_pretransport' => (int) ($row['local_rate_limited_pretransport'] ?? 0),
            'oauth_critical' => (int) ($row['oauth_critical'] ?? 0),
            'http_5xx' => (int) ($row['http_5xx'] ?? 0),
            'remote_uncertain' => (int) ($row['remote_uncertain'] ?? 0),
            'latest_telemetry_at' => $row['latest_telemetry_at'] ?: null,
        ];
    }

    /** @param array<string,int> $params @return list<array<string,mixed>> */
    private function topSignals(PDO $pdo, string $where, array $params): array
    {
        $statement = $pdo->prepare(
            'SELECT l.company_id,l.meli_account_id,l.scope_kind,l.endpoint_path,
                    COUNT(*) repetitions,MAX(l.created_at) last_seen_at
             FROM api_request_logs l
             WHERE ' . $where . ' AND ' . $this->signalSql('l') . '
             GROUP BY l.company_id,l.meli_account_id,l.scope_kind,l.endpoint_path
             ORDER BY repetitions DESC,last_seen_at DESC
             LIMIT ' . (self::TOP_LIMIT * 20)
        );
        $statement->execute($params);
        $bucketed = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accountAlias = $this->scopeAlias($row);
            $endpoint = ApiRequestOutcomeClassifier::normalizePath((string) ($row['endpoint_path'] ?? ''));
            $key = $accountAlias . '|' . $endpoint;
            if (!isset($bucketed[$key])) {
                $bucketed[$key] = [
                    'account_alias' => $accountAlias,
                    'endpoint' => $endpoint,
                    'repetitions' => 0,
                    'last_seen_at' => null,
                ];
            }
            $bucketed[$key]['repetitions'] += (int) ($row['repetitions'] ?? 0);
            if ($bucketed[$key]['last_seen_at'] === null
                || strcmp((string) ($row['last_seen_at'] ?? ''), (string) $bucketed[$key]['last_seen_at']) > 0) {
                $bucketed[$key]['last_seen_at'] = $row['last_seen_at'] ?? null;
            }
        }
        usort($bucketed, static fn(array $a, array $b): int =>
            ((int) $b['repetitions'] <=> (int) $a['repetitions'])
            ?: strcmp((string) ($b['last_seen_at'] ?? ''), (string) ($a['last_seen_at'] ?? ''))
        );
        return array_slice(array_values($bucketed), 0, self::TOP_LIMIT);
    }

    /** @param array<string,int> $params @return list<array<string,mixed>> */
    private function recentSignals(PDO $pdo, string $where, array $params): array
    {
        $statement = $pdo->prepare(
            'SELECT l.company_id,l.meli_account_id,l.scope_kind,l.method,l.endpoint_path,l.http_status,
                    l.error_type,l.outcome_class,l.reached_remote,l.created_at
             FROM api_request_logs l
             WHERE ' . $where . ' AND ' . $this->signalSql('l') . '
             ORDER BY l.created_at DESC,l.id DESC
             LIMIT ' . self::TOP_LIMIT
        );
        $statement->execute($params);
        return array_map(function (array $row): array {
            return [
                'observed_at' => $row['created_at'] ?? null,
                'signal' => $this->signalName($row),
                'account_alias' => $this->scopeAlias($row),
                'method' => strtoupper((string) ($row['method'] ?? 'GET')),
                'endpoint' => ApiRequestOutcomeClassifier::normalizePath((string) ($row['endpoint_path'] ?? '')),
                'http_status' => isset($row['http_status']) ? (int) $row['http_status'] : null,
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    private function queueEvidence(ApiHealthAccessScope $scope): array
    {
        try {
            $authorized = $scope->snapshot();
            $snapshot = (new QueueV4CleanHealthSnapshotService(Database::connectionFresh()))->snapshot(
                null,
                (array) ($authorized['company_ids'] ?? []),
                (array) ($authorized['account_ids'] ?? []),
                24,
            );
            $runtime = (array) ($snapshot['runtime'] ?? []);
            $totals = (array) ($snapshot['totals'] ?? []);
            return [
                'available' => true,
                'heartbeat_age_seconds' => $runtime['heartbeat_age_seconds'] ?? null,
                'dead' => (int) ($totals['dead'] ?? 0),
                'stale_leases' => (int) ($totals['stale_running'] ?? 0),
            ];
        } catch (Throwable) {
            return ['available' => false, 'heartbeat_age_seconds' => null, 'dead' => null, 'stale_leases' => null];
        }
    }

    /** @return array<string,mixed> */
    private function billingBreaker(SchemaInspectorService $schema, PDO $pdo): array
    {
        if (!$schema->hasTable('api_rhythm_penalties')
            || $schema->missingColumns('api_rhythm_penalties', ['scope_key', 'blocked_until', 'reduced_until', 'reason']) !== []) {
            return ['available' => false, 'scope' => 'Billing únicamente', 'state' => 'NO_CERTIFICADO', 'next_safe_at' => null];
        }
        $statement = $pdo->prepare(
            'SELECT blocked_until,reduced_until,reason
             FROM api_rhythm_penalties
             WHERE scope_key=? LIMIT 1'
        );
        $statement->execute(['endpoint:shared:' . hash('sha256', 'billing_orders')]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $next = $row['blocked_until'] ?? null;
        $active = is_string($next) && $next !== '' && strtotime($next . ' UTC') > time();
        return [
            'available' => true,
            'scope' => 'Billing únicamente',
            'state' => $active ? 'ACTIVE' : 'CLEAR',
            'next_safe_at' => $active ? $next : null,
            'reason' => $active ? (string) ($row['reason'] ?? 'billing_429_backoff') : null,
        ];
    }

    private function signalSql(string $alias): string
    {
        return '(' . $alias . '.reached_remote=1 AND (' . $alias . '.http_status IN (401,403,429) OR ' . $alias . '.http_status>=500))'
            . ' OR (' . $alias . '.reached_remote=0 AND ' . $alias . '.outcome_class="policy_delay")'
            . ' OR ' . $alias . '.outcome_class="remote_result_uncertain"';
    }

    /** @param array<string,mixed> $row */
    private function signalName(array $row): string
    {
        if ((int) ($row['reached_remote'] ?? 0) === 1 && (int) ($row['http_status'] ?? 0) === 429) {
            return 'REMOTE_HTTP_429';
        }
        if ((int) ($row['reached_remote'] ?? 0) === 0 && (string) ($row['outcome_class'] ?? '') === 'policy_delay') {
            return 'LOCAL_RATE_LIMITED_PRETRANSPORT';
        }
        if ((int) ($row['reached_remote'] ?? 0) === 1 && in_array((int) ($row['http_status'] ?? 0), [401, 403], true)) {
            return 'OAUTH_CRITICAL';
        }
        if ((int) ($row['reached_remote'] ?? 0) === 1 && (int) ($row['http_status'] ?? 0) >= 500) {
            return 'REMOTE_HTTP_5XX';
        }
        return 'REMOTE_UNCERTAIN';
    }

    /** @param array<string,mixed> $row */
    private function scopeAlias(array $row): string
    {
        $kind = (string) ($row['scope_kind'] ?? 'application');
        $identifier = (int) ($row['meli_account_id'] ?? 0);
        if ($identifier > 0) {
            return 'Cuenta ' . substr(hash('sha256', 'cron-risk-account:' . $identifier), 0, 10);
        }
        $identifier = (int) ($row['company_id'] ?? 0);
        if ($kind === 'company' && $identifier > 0) {
            return 'Empresa ' . substr(hash('sha256', 'cron-risk-company:' . $identifier), 0, 10);
        }
        return 'Aplicación';
    }

    /** @return array<string,mixed> */
    private function unavailable(string $measuredAt, string $message): array
    {
        return [
            'ok' => false,
            'read_only' => true,
            'source' => 'NOT_CERTIFIED',
            'source_label' => 'NO_CERTIFICADO',
            'measured_at' => $measuredAt,
            'hours' => self::HOURS,
            'authoritative' => false,
            'message' => $message,
            'totals' => [],
            'windows' => [],
            'top' => [],
            'recent' => [],
            'billing' => ['available' => false, 'scope' => 'Billing únicamente', 'state' => 'NO_CERTIFICADO', 'next_safe_at' => null],
            'worker' => ['available' => false, 'heartbeat_age_seconds' => null, 'dead' => null, 'stale_leases' => null],
            'materializer' => ['current' => false, 'last_log_id' => 0, 'latest_log_id' => 0, 'lag' => 0],
            'links' => [],
        ];
    }
}
