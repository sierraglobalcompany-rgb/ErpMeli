<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class ApiIncidentReadModelService
{
    /** @param array<string,mixed> $filters @return array{rows:list<array<string,mixed>>,total:int}|null */
    public function page(array $filters, int $limit, int $offset): ?array
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('api_incident_groups') || !$schema->hasTable('api_incident_materializer_state')) {
            return null;
        }
        try {
            $pdo = Database::connectionFresh();
            $state = (int) $pdo->query('SELECT last_log_id FROM api_incident_materializer_state WHERE singleton_id=1')->fetchColumn();
            $latest = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM api_request_logs')->fetchColumn();
            if ($state < $latest) {
                return null;
            }
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
            }
            $severity = (string) ($filters['severity'] ?? '');
            if ($severity === 'critical') {
                $where[] = '(g.outcome_class="blocked_signal" OR g.http_status=401)';
            } elseif ($severity === 'high') {
                $where[] = 'g.http_status IN (403,429)';
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
                      LEFT JOIN meli_accounts a ON a.id=g.meli_account_id
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
}
