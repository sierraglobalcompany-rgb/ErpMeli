<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use PDO;

final class LogQueryService
{
    private const API_SORTS = [
        'date' => 'l.created_at',
        'type' => 'l.outcome_class',
        'account' => 'a.account_name',
        'http' => 'l.http_status',
        'operation' => 'l.endpoint_path',
        'message' => 'l.safe_message',
    ];

    public function query(array $filters): array
    {
        return match ($filters['type'] ?: 'api') {
            'system' => $this->systemLogs($filters),
            'sync' => $this->syncLogs($filters),
            'cron' => $this->cronLogs($filters),
            'questions' => $this->questionLogs($filters),
            default => $this->apiLogs($filters),
        };
    }

    public function accounts(): array
    {
        $accountIds = (new BusinessScopeContext())->accountIds();
        if ($accountIds === []) {
            return [];
        }
        $schema = new SchemaInspectorService();
        $where = ['id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')'];
        if ($schema->hasColumn('meli_accounts', 'deleted_at')) {
            $where[] = 'deleted_at IS NULL';
        }
        $sql = 'SELECT id,account_name FROM meli_accounts WHERE '
            . implode(' AND ', $where)
            . ' ORDER BY account_name';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($accountIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,int|bool> */
    public function summary(array $filters): array
    {
        if (!(new SchemaInspectorService())->hasColumn('api_request_logs', 'outcome_class')) {
            return ['available' => false];
        }
        $where = [];
        $params = [];
        $this->classifiedApiWhere($where, $params, $filters, false);
        try {
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) attempts,
                        SUM(reached_remote=1) sent,
                        SUM(outcome_class="success") successful,
                        SUM(outcome_class="expected_absence") expected_absence,
                        SUM(outcome_class="remote_error") remote_errors,
                        SUM(outcome_class="local_failure") local_failures,
                        SUM(outcome_class="policy_delay") policy_delays,
                        COUNT(DISTINCT CASE WHEN actionable=1 AND incident_key IS NOT NULL THEN incident_key END) incidents
                 FROM api_request_logs l
                 WHERE ' . implode(' AND ', $where)
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'available' => true,
                'attempts' => (int) ($row['attempts'] ?? 0),
                'sent' => (int) ($row['sent'] ?? 0),
                'successful' => (int) ($row['successful'] ?? 0),
                'expected_absence' => (int) ($row['expected_absence'] ?? 0),
                'remote_errors' => (int) ($row['remote_errors'] ?? 0),
                'local_failures' => (int) ($row['local_failures'] ?? 0),
                'policy_delays' => (int) ($row['policy_delays'] ?? 0),
                'incidents' => (int) ($row['incidents'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }

    /** @return list<array<string,mixed>> */
    public function activity(array $filters, int $limit = 12): array
    {
        if (!(new SchemaInspectorService())->hasColumn('api_request_logs', 'outcome_class')) {
            return [];
        }
        $where = ['l.outcome_class IN ("success","expected_absence")'];
        $params = [];
        $this->classifiedApiWhere($where, $params, $filters, false);
        $stmt = Database::connection()->prepare(
            'SELECT l.method,l.endpoint_path,a.account_name,l.outcome_class,COUNT(*) occurrences,
                    MAX(l.created_at) last_seen_at
             FROM api_request_logs l
             LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY l.method,l.endpoint_path,a.account_name,l.outcome_class
             ORDER BY last_seen_at DESC LIMIT 250'
        );
        $stmt->execute($params);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $normalized = ApiRequestOutcomeClassifier::normalizePath((string) $row['endpoint_path']);
            $key = implode('|', [
                strtoupper((string) $row['method']),
                $normalized,
                (string) ($row['account_name'] ?? 'Aplicación'),
                (string) $row['outcome_class'],
            ]);
            if (!isset($groups[$key])) {
                $groups[$key] = $row + [
                    'normalized_path' => $normalized,
                    'operation_label' => UiLabelPresenter::apiOperation((string) $row['method'], $normalized),
                ];
            } else {
                $groups[$key]['occurrences'] += (int) $row['occurrences'];
                if ((string) $row['last_seen_at'] > (string) $groups[$key]['last_seen_at']) {
                    $groups[$key]['last_seen_at'] = $row['last_seen_at'];
                }
            }
        }
        usort($groups, static fn (array $a, array $b): int => strcmp((string) $b['last_seen_at'], (string) $a['last_seen_at']));
        return array_slice($groups, 0, max(1, min(50, $limit)));
    }

    /** @return array{rows:array,total:int,page:int,per_page:int,pages:int,classified:bool} */
    public function page(array $filters): array
    {
        if (($filters['type'] ?? 'api') !== 'api') {
            $rows = $this->query($filters);
            return ['rows' => $rows, 'total' => count($rows), 'page' => 1, 'per_page' => count($rows), 'pages' => 1, 'classified' => false];
        }
        if (!(new SchemaInspectorService())->hasColumn('api_request_logs', 'outcome_class')) {
            $rows = $this->apiLogs($filters);
            return ['rows' => $rows, 'total' => count($rows), 'page' => 1, 'per_page' => count($rows), 'pages' => 1, 'classified' => false];
        }

        $perPage = in_array((int) ($filters['per_page'] ?? 50), [25, 50, 100], true) ? (int) $filters['per_page'] : 50;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $where = [];
        $params = [];
        $this->classifiedApiWhere($where, $params, $filters, true);
        $whereSql = implode(' AND ', $where);
        $count = Database::connection()->prepare('SELECT COUNT(*) FROM api_request_logs l WHERE ' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $sort = self::API_SORTS[(string) ($filters['sort'] ?? 'date')] ?? self::API_SORTS['date'];
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $stmt = Database::connection()->prepare(
            'SELECT l.id,l.created_at,"api" log_type,l.safe_message message,l.http_status,l.endpoint_path,l.method,
                    a.account_name,l.request_id,l.outcome_class,l.reached_remote,l.actionable,l.risk_signal,
                    l.incident_key,l.error_type,l.error_code,l.was_blocked
             FROM api_request_logs l
             LEFT JOIN meli_accounts a ON a.id=l.meli_account_id
             WHERE ' . $whereSql . '
             ORDER BY ' . $sort . ' ' . $direction . ',l.id ' . $direction . '
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'classified' => true,
        ];
    }

    private function apiLogs(array $filters): array
    {
        $where = [];
        $params = [];
        $this->namedAccountScope($where, $params, 'e.meli_account_id', $filters);
        if (($filters['http_status'] ?? '') !== '') {
            $where[] = 'e.http_status=:http';
            $params['http'] = (int) $filters['http_status'];
        }
        if (($filters['endpoint'] ?? '') !== '') {
            $where[] = 'e.endpoint_path LIKE :endpoint';
            $params['endpoint'] = '%' . $filters['endpoint'] . '%';
        }
        $this->dateWhere($where, $params, 'e.created_at', $filters);
        $stmt = Database::connection()->prepare(
            'SELECT e.id,e.created_at,"api" log_type,e.safe_message message,e.http_status,e.endpoint_path,e.method,
                    a.account_name,e.request_id
             FROM api_error_logs e LEFT JOIN meli_accounts a ON a.id=e.meli_account_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY e.created_at DESC LIMIT 300'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<string> $where @param list<mixed> $params */
    private function classifiedApiWhere(array &$where, array &$params, array $filters, bool $applyView): void
    {
        $accountIds = $this->scopedAccountIds($filters);
        if ($accountIds === []) {
            $where[] = '1=0';
        } else {
            $where[] = 'l.meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            array_push($params, ...$accountIds);
        }
        if (($filters['http_status'] ?? '') !== '' && ctype_digit((string) $filters['http_status'])) {
            $where[] = 'l.http_status=?';
            $params[] = (int) $filters['http_status'];
        }
        if (($filters['endpoint'] ?? '') !== '') {
            $where[] = 'l.endpoint_path LIKE ?';
            $params[] = '%' . mb_substr((string) $filters['endpoint'], 0, 160) . '%';
        }
        $allowedOutcomes = ['success', 'remote_error', 'local_failure', 'policy_delay', 'expected_absence', 'blocked_signal'];
        if (in_array((string) ($filters['outcome'] ?? ''), $allowedOutcomes, true)) {
            $where[] = 'l.outcome_class=?';
            $params[] = (string) $filters['outcome'];
        }
        if (($filters['origin'] ?? '') === 'local') {
            $where[] = 'l.reached_remote=0';
        } elseif (($filters['origin'] ?? '') === 'remote') {
            $where[] = 'l.reached_remote=1';
        }
        $risk = (string) ($filters['risk'] ?? '');
        if ($risk === 'critical') {
            $where[] = '(l.outcome_class="blocked_signal" OR (l.http_status=401 AND LOWER(COALESCE(l.safe_message,"")) LIKE "%unauthorized_scopes%"))';
        } elseif ($risk === 'high') {
            $where[] = 'l.http_status=429';
        } elseif ($risk === 'none') {
            $where[] = 'l.outcome_class IN ("success","expected_absence")';
        } elseif ($risk === 'review') {
            $where[] = 'l.outcome_class IN ("remote_error","local_failure","policy_delay")';
        }
        if ($applyView && ($filters['view'] ?? '') === 'attention') {
            $where[] = 'l.actionable=1';
            $where[] = 'l.outcome_class NOT IN ("success","expected_absence")';
        } elseif ($applyView && ($filters['view'] ?? '') === 'activity') {
            $where[] = 'l.outcome_class IN ("success","expected_absence")';
        }

        [$fromUtc, $toUtc] = $this->apiRange($filters);
        $where[] = 'l.created_at>=?';
        $params[] = $fromUtc;
        $where[] = 'l.created_at<?';
        $params[] = $toUtc;
    }

    /** @return array{0:string,1:string} */
    private function apiRange(array $filters): array
    {
        $clock = new Clock();
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $localFrom = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $clock->localTimezone());
            $localTo = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $clock->localTimezone());
            if ($localFrom !== false && $localTo !== false) {
                return [
                    $clock->localToUtc($localFrom)->format('Y-m-d H:i:s'),
                    $clock->localToUtc($localTo->modify('+1 day'))->format('Y-m-d H:i:s'),
                ];
            }
        }
        $hours = in_array((int) ($filters['period'] ?? 24), [24, 168, 720], true) ? (int) $filters['period'] : 24;
        $toUtc = $clock->nowUtc();
        return [$toUtc->modify("-{$hours} hours")->format('Y-m-d H:i:s'), $toUtc->format('Y-m-d H:i:s')];
    }

    private function systemLogs(array $filters): array
    {
        // No existe clave de empresa/cuenta en esta tabla: falla cerrado en web.
        return [];
    }

    private function syncLogs(array $filters): array
    {
        $where = []; $params = [];
        $this->namedAccountScope($where, $params, 'l.meli_account_id', $filters);
        if (($filters['level'] ?? '') !== '') { $where[] = 'l.status=:level'; $params['level'] = $filters['level']; }
        $this->dateWhere($where, $params, 'l.started_at', $filters);
        $stmt = Database::connection()->prepare('SELECT l.id,l.started_at created_at,"sync" log_type,l.error_message message,l.status level,a.account_name,l.sync_type endpoint_path FROM meli_sync_logs l LEFT JOIN meli_accounts a ON a.id=l.meli_account_id WHERE ' . implode(' AND ', $where) . ' ORDER BY l.started_at DESC LIMIT 300');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function cronLogs(array $filters): array
    {
        // cron_health_checks no identifica tenant; no debe exponerse como dato multiempresa.
        return [];
    }

    private function questionLogs(array $filters): array
    {
        $where = []; $params = [];
        $this->namedAccountScope($where, $params, 'q.meli_account_id', $filters);
        $this->dateWhere($where, $params, 'q.asked_at', $filters);
        $stmt = Database::connection()->prepare('SELECT q.id,q.asked_at created_at,"questions" log_type,q.text message,q.status level,a.account_name,q.external_item_id endpoint_path FROM meli_questions q JOIN meli_accounts a ON a.id=q.meli_account_id WHERE ' . implode(' AND ', $where) . ' ORDER BY q.asked_at DESC LIMIT 300');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function dateWhere(array &$where, array &$params, string $column, array $filters): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['from'] ?? ''))) {
            $where[] = $column . '>=:from'; $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['to'] ?? ''))) {
            $where[] = $column . '<:to';
            $params['to'] = (new DateTimeImmutable($filters['to'] . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s');
        }
    }

    /** @return list<int> */
    private function scopedAccountIds(array $filters): array
    {
        $accountIds = (new BusinessScopeContext())->accountIds();
        $requested = (int) ($filters['account_id'] ?? 0);
        if ($requested <= 0) {
            return $accountIds;
        }
        return in_array($requested, $accountIds, true) ? [$requested] : [];
    }

    /** @param list<string> $where @param array<string,mixed> $params */
    private function namedAccountScope(array &$where, array &$params, string $column, array $filters): void
    {
        $accountIds = $this->scopedAccountIds($filters);
        if ($accountIds === []) {
            $where[] = '1=0';
            return;
        }
        $names = [];
        foreach ($accountIds as $index => $accountId) {
            $name = 'scope_account_' . $index;
            $names[] = ':' . $name;
            $params[$name] = $accountId;
        }
        $where[] = $column . ' IN (' . implode(',', $names) . ')';
    }
}
