<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;

/** Fast read-only operational authority for Queue V4 Clean and API Health. */
final class QueueV4CleanHealthSnapshotService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function snapshot(?int $accountId, array $companyIds, array $accountIds, int $hours = 24): array
    {
        $hours = max(1, min(720, $hours));
        $accountId = $accountId !== null && $accountId > 0 ? $accountId : null;
        $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds), static fn(int $id): bool => $id > 0)));
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn(int $id): bool => $id > 0)));
        if ($accountId !== null) {
            $accountIds = in_array($accountId, $accountIds, true) ? [$accountId] : [];
        }
        [$tenantSql, $tenantParams] = $this->tenantPredicate('j', $companyIds, $accountIds);
        $control = $this->pdo->query(
            "SELECT engine_state,readiness_state,scheduler_enabled,last_scheduler_at,updated_at
             FROM queue_v4_clean_control WHERE control_key='primary' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $counts = $this->pdo->prepare(
            "SELECT COALESCE(SUM(j.state='ready'),0) ready,
                    COALESCE(SUM(j.state='running'),0) running,
                    COALESCE(SUM(j.state='waiting'),0) waiting,
                    COALESCE(SUM(j.state='review'),0) review,
                    COALESCE(SUM(j.state='dead'),0) dead,
                    COALESCE(SUM(j.state='completed'),0) completed
             FROM queue_v4_clean_jobs j WHERE {$tenantSql}"
        );
        $counts->execute($tenantParams);
        $totals = array_map('intval', $counts->fetch(PDO::FETCH_ASSOC) ?: []);
        $sales = $this->pdo->prepare(
            'SELECT COALESCE(SUM(j.status IN ("pending","waiting_budget")),0) pending,
                    COALESCE(SUM(j.status="running"),0) running,
                    COALESCE(SUM(j.status="error"),0) attention,
                    COALESCE(SUM(j.status="complete"),0) completed
             FROM sync_sales_audit_jobs j
             INNER JOIN sync_sales_audit_runs r
               ON r.id=j.sync_sales_audit_run_id AND r.company_id=j.company_id AND r.meli_account_id=j.meli_account_id
             INNER JOIN meli_accounts a ON a.company_id=j.company_id AND a.id=j.meli_account_id
             WHERE ' . $tenantSql
        );
        $sales->execute($tenantParams);
        $salesTotals = array_map('intval', $sales->fetch(PDO::FETCH_ASSOC) ?: []);
        $recent = $this->pdo->prepare(
            'SELECT
               (SELECT COALESCE(SUM(a.physical_http_calls),0) FROM queue_v4_clean_attempts a
                 WHERE a.started_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR)
                   AND ' . $this->tenantPredicate('a', $companyIds, $accountIds)[0] . ')
               +
               (SELECT COALESCE(SUM(o.remote_attempt_count),0) FROM oauth_refresh_operations o
                 WHERE o.updated_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR)
                   AND ' . $this->tenantPredicate('o', $companyIds, $accountIds)[0] . ') http_last_hour,
               (SELECT COUNT(*) FROM queue_v4_clean_jobs j
                 WHERE j.completed_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR)
                   AND ' . $tenantSql . ') completed_last_hour'
        );
        $recent->execute(array_merge($tenantParams, $tenantParams, $tenantParams));
        $recentTotals = $recent->fetch(PDO::FETCH_ASSOC) ?: [];
        $accountStats = $this->pdo->prepare(
            'SELECT a.id meli_account_id,a.account_name,
                    COALESCE(SUM(qa.physical_http_calls),0) sent,
                    COALESCE(SUM(qa.dispatch_state="RESPONSE_KNOWN" AND qa.http_status BETWEEN 200 AND 299),0) successful,
                    COALESCE(SUM(qa.dispatch_state="RESPONSE_KNOWN" AND qa.http_status>=400),0) remote_errors,
                    COALESCE(SUM(qa.dispatch_state="NOT_DISPATCHED" AND qa.outcome IN ("review","dead")),0) local_failures,
                    COALESCE(SUM(qa.dispatch_state="RESPONSE_KNOWN" AND qa.http_status=429),0) http_429,
                    MAX(qa.physical_started_at) last_remote_attempt_at,
                    MAX(CASE WHEN qa.dispatch_state="RESPONSE_KNOWN" AND qa.http_status BETWEEN 200 AND 299
                             THEN qa.response_known_at END) last_remote_success_at,
                    MAX(CASE WHEN qa.outcome="waiting" THEN qa.finished_at END) last_policy_delay_at
             FROM meli_accounts a
             LEFT JOIN queue_v4_clean_attempts qa
               ON qa.company_id=a.company_id AND qa.meli_account_id=a.id
              AND qa.started_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ' . $hours . ' HOUR)
             WHERE ' . $this->tenantPredicate('a', $companyIds, $accountIds, 'id')[0] . '
             GROUP BY a.company_id,a.id,a.account_name
             ORDER BY a.id'
        );
        $accountStats->execute($tenantParams);
        $accountStatsRows = $accountStats->fetchAll(PDO::FETCH_ASSOC);
        foreach ($accountStatsRows as &$accountStat) {
            foreach (['sent','successful','remote_errors','local_failures','http_429'] as $numeric) {
                $accountStat[$numeric] = (int) ($accountStat[$numeric] ?? 0);
            }
            $accountStat['raw_successful'] = $accountStat['successful'];
            $accountStat['corrected_successful'] = 0;
            $accountStat['active_incident_count'] = 0;
            $accountStat['active_remote_incident_count'] = 0;
            $accountStat['active_local_incident_count'] = 0;
        }
        unset($accountStat);
        $recentActivity = $this->pdo->prepare(
            'SELECT qa.response_known_at created_at,qa.transport_method method,
                    qa.endpoint_key endpoint_path,qa.meli_account_id,a.account_name
             FROM queue_v4_clean_attempts qa
             INNER JOIN meli_accounts a
               ON a.company_id=qa.company_id AND a.id=qa.meli_account_id
             WHERE qa.dispatch_state="RESPONSE_KNOWN" AND qa.http_status BETWEEN 200 AND 299
               AND ' . $this->tenantPredicate('qa', $companyIds, $accountIds)[0] . '
             ORDER BY qa.response_known_at DESC,qa.id DESC LIMIT 20'
        );
        $recentActivity->execute($tenantParams);
        $recentActivityRows = $recentActivity->fetchAll(PDO::FETCH_ASSOC);
        $oauth = (new QueueV4CleanOAuthOperationRepository($this->pdo))->observability();
        $oauth = array_values(array_filter(
            $oauth,
            static fn(array $row): bool => in_array((int) $row['company_id'], $companyIds, true)
                && in_array((int) $row['meli_account_id'], $accountIds, true)
        ));
        $oauthStates = [];
        foreach ($oauth as $row) {
            $state = (string) ($row['automatic_refresh_state'] ?? 'UNKNOWN');
            $oauthStates[$state] = ($oauthStates[$state] ?? 0) + 1;
        }
        $materializer = $this->pdo->query(
            'SELECT s.last_log_id,(SELECT COALESCE(MAX(id),0) FROM api_request_logs) latest_log_id
             FROM api_incident_materializer_state s WHERE s.singleton_id=1'
        )->fetch(PDO::FETCH_ASSOC) ?: ['last_log_id' => 0, 'latest_log_id' => 0];
        $last = trim((string) ($control['last_scheduler_at'] ?? ''));
        $lastTs = $last === '' ? false : strtotime($last . ' UTC');
        $age = $lastTs === false ? null : max(0, time() - $lastTs);
        $engine = (string) ($control['engine_state'] ?? 'UNKNOWN');
        $readiness = (string) ($control['readiness_state'] ?? 'UNKNOWN');
        $schedulerConfigured = (int) ($control['scheduler_enabled'] ?? 0) === 1;
        $state = match (true) {
            $engine !== 'ACTIVE' => 'stopped',
            $readiness !== 'CERTIFIED' => 'attention',
            !$schedulerConfigured || $age === null || $age > 180 => 'delayed',
            (int) ($oauthStates['REMOTE_UNCERTAIN'] ?? 0) > 0
                || (int) ($oauthStates['RECONNECT_REQUIRED'] ?? 0) > 0
                || (int) ($oauthStates['FAILED'] ?? 0) > 0 => 'attention',
            default => 'healthy',
        };
        $pending = (int) ($totals['ready'] ?? 0) + (int) ($totals['running'] ?? 0)
            + (int) ($totals['waiting'] ?? 0) + (int) ($totals['review'] ?? 0)
            + (int) ($salesTotals['pending'] ?? 0) + (int) ($salesTotals['running'] ?? 0);
        return [
            'ok' => true,
            'protocol' => 'complete',
            'snapshot_state' => 'complete',
            'read_only' => true,
            'measured_at' => gmdate('Y-m-d H:i:s'),
            'state' => $state,
            'state_label' => match ($state) {
                'healthy' => 'Queue V4 operativa',
                'delayed' => 'Queue V4 sin heartbeat reciente',
                'stopped' => 'Queue V4 detenida',
                default => 'Queue V4 requiere revisión',
            },
            'state_message' => $state === 'healthy'
                ? 'El único scheduler Queue V4 registra actividad reciente.'
                : 'Revise el Cron físico, OAuth y las revisiones antes de evaluar drenaje.',
            'runtime' => [
                'engine' => $engine,
                'readiness' => $readiness,
                'scheduler_configured' => $schedulerConfigured,
                'last_scheduler_at' => $last !== '' ? $last : null,
                'heartbeat_age_seconds' => $age,
            ],
            'totals' => $totals + [
                'pending' => $pending,
                'http_last_hour' => (int) ($recentTotals['http_last_hour'] ?? 0),
                'completed_last_hour' => (int) ($recentTotals['completed_last_hour'] ?? 0),
            ],
            'sales_audit' => $salesTotals,
            'oauth' => ['accounts' => count($oauth), 'states' => $oauthStates],
            'account_stats' => $accountStatsRows,
            'recent_activity' => $recentActivityRows,
            'materializer' => [
                'last_log_id' => (int) $materializer['last_log_id'],
                'latest_log_id' => (int) $materializer['latest_log_id'],
                'lag' => max(0, (int) $materializer['latest_log_id'] - (int) $materializer['last_log_id']),
            ],
        ];
    }

    /** @return array{0:string,1:list<int>} */
    private function tenantPredicate(
        string $alias,
        array $companyIds,
        array $accountIds,
        string $accountColumn = 'meli_account_id',
    ): array
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/i', $alias) !== 1
            || !in_array($accountColumn, ['id','meli_account_id'], true)
            || $companyIds === [] || $accountIds === []) {
            return ['1=0', []];
        }
        $companyTokens = implode(',', array_fill(0, count($companyIds), '?'));
        $accountTokens = implode(',', array_fill(0, count($accountIds), '?'));
        return [
            $alias . '.company_id IN (' . $companyTokens . ') AND '
                . $alias . '.' . $accountColumn . ' IN (' . $accountTokens . ')',
            array_merge($companyIds, $accountIds),
        ];
    }
}
