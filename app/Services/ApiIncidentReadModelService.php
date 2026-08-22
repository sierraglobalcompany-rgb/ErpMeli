<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class ApiIncidentReadModelService
{
    /** @return array{current:bool,last_log_id:int,latest_log_id:int} */
    public function freshness(): array
    {
        try {
            $pdo = Database::connectionFresh();
            $state = (int) $pdo->query(
                'SELECT last_log_id FROM api_incident_materializer_state WHERE singleton_id=1'
            )->fetchColumn();
            $latest = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM api_request_logs')->fetchColumn();
            return ['current' => $state >= $latest, 'last_log_id' => $state, 'latest_log_id' => $latest];
        } catch (Throwable) {
            return ['current' => false, 'last_log_id' => 0, 'latest_log_id' => 0];
        }
    }

    /** @param array<string,mixed> $filters @return array{rows:list<array<string,mixed>>,total:int}|null */
    public function page(array $filters, int $limit, int $offset): ?array
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('api_incident_groups') || !$schema->hasTable('api_incident_materializer_state')) {
            return null;
        }
        try {
            $pdo = Database::connectionFresh();
            $accountId = max(0, (int) ($filters['account_id'] ?? 0));
            $scope = (new ApiHealthAccessScope())->predicate('g', 'incident_group', $accountId ?: null);
            $where = [
                'g.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL :hours HOUR)',
                $scope['sql'],
            ];
            $params = $scope['params'] + ['hours' => max(1, min(720, (int) ($filters['hours'] ?? 24)))];
            $origin = (string) ($filters['origin'] ?? '');
            if ($origin === 'protection') {
                $where[] = 'g.outcome_class="policy_delay"';
            } elseif ($origin !== 'all_with_protection') {
                $where[] = 'g.outcome_class<>"policy_delay"';
            }
            if ($origin === 'internal') {
                $where[] = 'g.reached_remote=0';
            } elseif ($origin === 'remote') {
                $where[] = 'g.reached_remote=1';
            }
            $operation = trim((string) ($filters['operation'] ?? ''));
            if ($operation !== '') {
                $where[] = 'g.endpoint_path LIKE :operation';
                $params['operation'] = '%' . $operation . '%';
            }
            $httpStatus = max(0, (int) ($filters['http_status'] ?? 0));
            if ($httpStatus > 0) {
                $where[] = 'g.http_status=:http_status_filter';
                $params['http_status_filter'] = $httpStatus;
                if ($httpStatus === 429) {
                    $where[] = 'g.reached_remote=1';
                }
            }
            $severity = (string) ($filters['severity'] ?? '');
            if ($severity === 'critical') {
                $where[] = '(g.outcome_class="blocked_signal" OR g.http_status=401)';
            } elseif ($severity === 'high') {
                $where[] = '((g.http_status=403 AND g.reached_remote=1) OR (g.http_status=429 AND g.reached_remote=1))';
            } elseif ($severity === 'medium') {
                $where[] = 'g.outcome_class="local_failure"';
            } elseif ($severity === 'low') {
                $where[] = 'g.outcome_class="remote_error" AND COALESCE(g.http_status,0) NOT IN (401,403,429)';
            }
            $ack = '(ack.acknowledged_through_at IS NOT NULL AND ack.acknowledged_through_at>=g.last_seen_at)';
            $activeMinutes = max(5, min(1440, (new AppSettingsService())->int('api_health.active_window_minutes', 120)));
            $status = (string) ($filters['status'] ?? '');
            if ($status === 'reviewed') {
                $where[] = $ack;
            } elseif ($status === 'active') {
                $where[] = 'NOT ' . $ack . ' AND g.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $activeMinutes . ' MINUTE)';
            } elseif ($status === 'historical') {
                $where[] = 'NOT ' . $ack . ' AND g.last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)';
            } elseif ($status === 'recovered') {
                $where[] = 'NOT ' . $ack . ' AND g.last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $activeMinutes . ' MINUTE) AND g.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)';
            }
            $from = ' FROM api_incident_groups g
                      LEFT JOIN api_incident_acknowledgements ack
                        ON ack.incident_key=g.incident_key AND ack.scope_key=g.scope_key
                      LEFT JOIN meli_accounts a
                        ON a.company_id=g.company_id AND a.id=g.meli_account_id
                      LEFT JOIN companies c ON c.id=g.company_id
                      WHERE ' . implode(' AND ', $where);
            $count = $pdo->prepare('SELECT COUNT(*)' . $from);
            foreach ($params as $key => $value) {
                $count->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $count->execute();
            $total = (int) $count->fetchColumn();
            $stmt = $pdo->prepare(
                'SELECT g.*,ack.acknowledged_at,
                        IF(' . $ack . ',1,0) acknowledged_all,
                        1 account_count,
                        CASE WHEN g.meli_account_id IS NOT NULL THEN COALESCE(a.account_name,"Cuenta")
                             WHEN g.scope_kind="company" THEN CONCAT("Empresa: ",COALESCE(c.name,"sin nombre"))
                             ELSE "Aplicación" END account_names' . $from . '
                 ORDER BY g.last_seen_at DESC,g.id DESC LIMIT :offset,:limit'
            );
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
            $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string,mixed>>|null */
    public function byKey(string $key): ?array
    {
        try {
            $scope = (new ApiHealthAccessScope())->predicate('g', 'incident_key_group');
            $statement = Database::connectionFresh()->prepare(
                'SELECT g.*,ack.acknowledged_at,
                        IF(ack.acknowledged_through_at IS NOT NULL AND ack.acknowledged_through_at>=g.last_seen_at,1,0) acknowledged_all,
                        1 account_count,
                        CASE WHEN g.meli_account_id IS NOT NULL THEN COALESCE(a.account_name,"Cuenta")
                             WHEN g.scope_kind="company" THEN CONCAT("Empresa: ",COALESCE(c.name,"sin nombre"))
                             ELSE "Aplicación" END account_names
                 FROM api_incident_groups g
                 LEFT JOIN api_incident_acknowledgements ack
                   ON ack.incident_key=g.incident_key AND ack.scope_key=g.scope_key
                 LEFT JOIN meli_accounts a
                   ON a.company_id=g.company_id AND a.id=g.meli_account_id
                 LEFT JOIN companies c ON c.id=g.company_id
                 WHERE g.incident_key=:incident_key AND ' . $scope['sql'] . '
                 ORDER BY g.last_seen_at DESC,g.id DESC'
            );
            $statement->execute(['incident_key' => $key] + $scope['params']);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{active:int,recovered:int,reviewed:int,historical:int}|null */
    public function stateCounts(int $hours, ?int $accountId): ?array
    {
        try {
            $scope = (new ApiHealthAccessScope())->predicate('g', 'incident_count_group', $accountId);
            $activeMinutes = max(5, min(1440, (new AppSettingsService())->int('api_health.active_window_minutes', 120)));
            $ack = '(ack.acknowledged_through_at IS NOT NULL AND ack.acknowledged_through_at>=g.last_seen_at)';
            $statement = Database::connectionFresh()->prepare(
                'SELECT
                   COALESCE(SUM(NOT ' . $ack . ' AND g.outcome_class<>"policy_delay"
                     AND g.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $activeMinutes . ' MINUTE)),0) active,
                   COALESCE(SUM(NOT ' . $ack . ' AND g.outcome_class<>"policy_delay"
                     AND g.last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $activeMinutes . ' MINUTE)
                     AND g.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)),0) recovered,
                   COALESCE(SUM(' . $ack . ' AND g.outcome_class<>"policy_delay"),0) reviewed,
                   COALESCE(SUM(NOT ' . $ack . ' AND g.outcome_class<>"policy_delay"
                     AND g.last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)),0) historical
                 FROM api_incident_groups g
                 LEFT JOIN api_incident_acknowledgements ack
                   ON ack.incident_key=g.incident_key AND ack.scope_key=g.scope_key
                 WHERE g.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL :hours HOUR)
                   AND ' . $scope['sql']
            );
            $statement->execute(['hours' => max(1, min(720, $hours))] + $scope['params']);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? [
                'active' => (int) $row['active'],
                'recovered' => (int) $row['recovered'],
                'reviewed' => (int) $row['reviewed'],
                'historical' => (int) $row['historical'],
            ] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{blocked_signals:int,bad_requests:int,unauthorized:int,forbidden:int,rate_limited:int}|null */
    public function signalCounts(?int $accountId): ?array
    {
        try {
            $scope = (new ApiHealthAccessScope())->predicate('g', 'incident_signal_group', $accountId);
            $minutes = max(5, min(120, (new AppSettingsService())->int('api.health.active_window_minutes', 15)));
            $statement = Database::connectionFresh()->prepare(
                'SELECT
                   COALESCE(SUM(g.outcome_class="blocked_signal"),0) blocked_signals,
                   COALESCE(SUM(g.reached_remote=1 AND g.http_status=400),0) bad_requests,
                   COALESCE(SUM(g.reached_remote=1 AND g.http_status=401),0) unauthorized,
                   COALESCE(SUM(g.reached_remote=1 AND g.http_status=403),0) forbidden,
                   COALESCE(SUM(g.reached_remote=1 AND g.http_status=429),0) rate_limited
                 FROM api_incident_groups g
                 WHERE g.last_seen_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ' . $minutes . ' MINUTE)
                   AND ' . $scope['sql']
            );
            $statement->execute($scope['params']);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? array_map('intval', $row) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
