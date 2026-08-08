<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class WorkQueueProjectionService
{
    public function available(): bool
    {
        return (new SchemaInspectorService())->hasTable('system_work_queue_projection');
    }

    public function refresh(): int
    {
        if (!$this->available()) {
            return 0;
        }
        $count = 0;
        foreach ((new WorkQueueRegistry())->adapters() as $adapter) {
            $count += $this->replaceQueue($adapter);
        }
        Database::connectionFresh()->exec(
            'DELETE FROM system_work_queue_projection
             WHERE projected_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)
               AND display_status IN ("completed","paused")'
        );
        return $count;
    }

    public function refreshQueue(string $queueKey): bool
    {
        foreach ((new WorkQueueRegistry())->adapters() as $adapter) {
            if ($adapter->key() === $queueKey) {
                $this->replaceQueue($adapter);
                return $adapter->projectSucceeded();
            }
        }
        return false;
    }

    /**
     * Actualiza una sola cola por invocación.
     *
     * La proyección completa puede contener miles de recursos. Repartirla
     * evita consumir la ventana de Hostinger después de cerrar el trabajo de
     * negocio y conserva checkpoints aunque el proceso sea interrumpido.
     *
     * @return array{queue_key:?string,refreshed:bool,processed:int}
     */
    public function refreshNextQueue(): array
    {
        if (!$this->available()) {
            return ['queue_key' => null, 'refreshed' => false, 'processed' => 0];
        }
        $adapters = (new WorkQueueRegistry())->adapters();
        if ($adapters === []) {
            return ['queue_key' => null, 'refreshed' => false, 'processed' => 0];
        }
        $settings = new AppSettingsService();
        $cursor = max(0, $settings->int('automation.projection_refresh_cursor', 0));
        $index = $cursor % count($adapters);
        $adapter = $adapters[$index];
        // Avanzar primero evita quedar atrapados para siempre en un adaptador
        // incompatible o en una terminación forzada de Hostinger.
        $settings->set(
            'automation.projection_refresh_cursor',
            (string) (($index + 1) % count($adapters)),
            'automation'
        );
        $processed = $this->replaceQueue($adapter);
        return [
            'queue_key' => $adapter->key(),
            'refreshed' => $adapter->projectSucceeded(),
            'processed' => $processed,
        ];
    }

    /**
     * Inventario completo y acotado para congelar una sesión manual.
     *
     * @param array<string,mixed> $filters
     * @return array{rows:list<array<string,mixed>>,total:int,truncated:bool}
     */
    public function all(array $filters = [], int $maximum = 5000, bool $refreshIfStale = false): array
    {
        $maximum = max(100, min(10000, $maximum));
        $rows = [];
        $page = 1;
        $total = 0;
        do {
            $result = $this->page(
                array_merge($filters, ['page' => $page, 'per_page' => 100]),
                $refreshIfStale
            );
            $total = (int) $result['total'];
            foreach ($result['rows'] as $row) {
                if (count($rows) >= $maximum) {
                    break 2;
                }
                $rows[] = $row;
            }
            $page++;
        } while (count($rows) < $total);

        return ['rows' => $rows, 'total' => $total, 'truncated' => count($rows) < $total];
    }

    /** @param array<string,mixed> $filters @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,returned:int,has_more:bool,truncated:bool} */
    public function page(array $filters = [], bool $refreshIfStale = false): array
    {
        if (!$this->available()) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'returned' => 0, 'has_more' => false, 'truncated' => false];
        }
        if ($refreshIfStale) {
            $this->refreshIfStale();
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = in_array((int) ($filters['per_page'] ?? 50), [25, 50, 100], true) ? (int) $filters['per_page'] : 50;
        [$where, $params] = $this->where($filters);
        $pdo = Database::connectionFresh();
        $count = $pdo->prepare('SELECT COUNT(*) FROM system_work_queue_projection p ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT p.* FROM system_work_queue_projection p ' . $where . '
             ORDER BY
               CASE p.display_status WHEN "running" THEN 0 WHEN "error" THEN 1 WHEN "waiting_budget" THEN 2
                    WHEN "pending" THEN 3 WHEN "retry" THEN 4 WHEN "scheduled" THEN 5 ELSE 6 END,
               p.priority_tier ASC,p.created_at_source ASC,p.id ASC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $estimator = new WorkEstimateService();
        $presenter = new WorkStatusPresenter();
        $attentionPresenter = new WorkAttentionPresenter();
        $eligibilityService = new WorkEligibilityService();
        foreach ($rows as &$row) {
            $row['eligibility'] = $eligibilityService->inspect($row);
            $row['estimate'] = $estimator->estimate($row);
            $row['presented_status'] = $presenter->present((string) $row['display_status']);
            $row['attention'] = $attentionPresenter->present($row);
        }
        unset($row);
        $returned = count($rows);
        $hasMore = ($page * $perPage) < $total;
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'returned' => $returned,
            'has_more' => $hasMore,
            // La respuesta HTTP está paginada, pero la proyección de origen ya no se recorta silenciosamente.
            'truncated' => $hasMore,
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(string $queueKey, string $sourceId, bool $refreshIfStale = false): ?array
    {
        if (!$this->available() || $queueKey === '' || $sourceId === '') {
            return null;
        }
        $definitions = (new WorkQueueRegistry())->definitionsByKey();
        if (!isset($definitions[$queueKey])) {
            return null;
        }
        if ($refreshIfStale) {
            $this->refreshIfStale();
        }
        [$scopeSql, $scopeParams] = $this->scopePredicate('p');
        $stmt = Database::connectionFresh()->prepare(
            'SELECT p.* FROM system_work_queue_projection p
             WHERE p.queue_key=? AND p.source_id=? AND ' . $scopeSql . ' LIMIT 1'
        );
        $stmt->execute(array_merge([$queueKey, $sourceId], $scopeParams));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['estimate'] = (new WorkEstimateService())->estimate($row);
        $row['eligibility'] = (new WorkEligibilityService())->inspect($row);
        $row['presented_status'] = (new WorkStatusPresenter())->present((string) $row['display_status']);
        $row['attention'] = (new WorkAttentionPresenter())->present($row);
        return $row;
    }

    /** @return array<string,mixed> */
    public function summary(bool $refreshIfStale = false): array
    {
        if (!$this->available()) {
            return ['available' => false, 'pending' => 0, 'errors' => 0, 'oldest' => null, 'running' => 0, 'waiting_budget' => 0];
        }
        if ($refreshIfStale) {
            $this->refreshIfStale();
        }
        [$scopeSql, $scopeParams] = $this->scopePredicate('p');
        $stmt = Database::connectionFresh()->prepare(
            'SELECT
                SUM(display_status IN ("pending","retry","scheduled","waiting_budget")) pending,
                SUM(display_status="running") running,
                SUM(display_status="error") errors,
                SUM(display_status="waiting_budget") waiting_budget,
                MIN(CASE WHEN display_status IN ("pending","retry","waiting_budget") THEN created_at_source END) oldest
             FROM system_work_queue_projection p WHERE ' . $scopeSql
        );
        $stmt->execute($scopeParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['available' => true] + $row;
    }

    /** @param array<string,mixed> $item */
    private function upsert(PDO $pdo, array $item): void
    {
        $columns = [
            'queue_key','source_table','source_id','company_id','meli_account_id','account_name','category','human_label',
            'content_summary','source_status','display_status','eligibility_state','eligibility_checked_at',
            'eligibility_reason','priority_tier','priority_score','is_api_task',
            'item_count','progress_current','progress_total','estimated_api_calls','estimated_seconds',
            'created_at_source','next_eligible_at','started_at_source','heartbeat_at','lease_expires_at',
            'finished_at_source','last_result',
            'wait_reason','safe_error_message','diagnostic_id','source_updated_at',
            'normalized_error_code','retry_policy','remediation_key','reached_remote','next_retry_at',
        ];
        $sql = 'INSERT INTO system_work_queue_projection (' . implode(',', $columns) . ',projected_at)
                VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ',UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE
                  company_id=VALUES(company_id),meli_account_id=VALUES(meli_account_id),
                  account_name=VALUES(account_name),category=VALUES(category),
                  human_label=VALUES(human_label),content_summary=VALUES(content_summary),source_status=VALUES(source_status),
                  display_status=VALUES(display_status),eligibility_state=VALUES(eligibility_state),
                  eligibility_checked_at=VALUES(eligibility_checked_at),eligibility_reason=VALUES(eligibility_reason),
                  priority_tier=VALUES(priority_tier),priority_score=VALUES(priority_score),
                  is_api_task=VALUES(is_api_task),item_count=VALUES(item_count),progress_current=VALUES(progress_current),
                  progress_total=VALUES(progress_total),estimated_api_calls=VALUES(estimated_api_calls),
                  estimated_seconds=VALUES(estimated_seconds),created_at_source=VALUES(created_at_source),
                  next_eligible_at=VALUES(next_eligible_at),started_at_source=VALUES(started_at_source),
                  heartbeat_at=VALUES(heartbeat_at),lease_expires_at=VALUES(lease_expires_at),
                  finished_at_source=VALUES(finished_at_source),last_result=VALUES(last_result),
                  wait_reason=VALUES(wait_reason),safe_error_message=VALUES(safe_error_message),
                  diagnostic_id=VALUES(diagnostic_id),source_updated_at=VALUES(source_updated_at),
                  normalized_error_code=VALUES(normalized_error_code),retry_policy=VALUES(retry_policy),
                  remediation_key=VALUES(remediation_key),reached_remote=VALUES(reached_remote),
                  next_retry_at=VALUES(next_retry_at),projected_at=UTC_TIMESTAMP()';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_map(static fn (string $column): mixed => $item[$column] ?? null, $columns));
    }

    private function replaceQueue(WorkQueueAdapter $adapter): int
    {
        $items = $adapter->project();
        if (!$adapter->projectSucceeded()) {
            // Conservar la última proyección conocida. Vaciarla convertiría
            // un fallo de lectura en una falsa "cola vacía".
            $this->recordQueueHealth(
                $adapter->key(),
                'unavailable',
                'No se pudo comprobar esta cola. Se conserva la última lectura válida.'
            );
            return 0;
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM system_work_queue_projection WHERE queue_key=?');
            $delete->execute([$adapter->key()]);
            foreach ($items as $item) {
                $item['priority_score'] = $this->score($item);
                $eligibility = (new WorkEligibilityService())->inspect($item);
                $item['eligibility_state'] = $eligibility['state'];
                $item['eligibility_reason'] = $eligibility['reason'];
                $item['eligibility_checked_at'] = gmdate('Y-m-d H:i:s');
                $this->upsert($pdo, $item);
            }
            $pdo->commit();
            $this->recordQueueHealth($adapter->key(), 'healthy', 'Cola comprobada correctamente.');
            return count($items);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function recordQueueHealth(string $queueKey, string $status, string $message): void
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_queue_health')) {
                return;
            }
            Database::connectionFresh()->prepare(
                'INSERT INTO system_queue_health
                 (queue_key,status,last_checked_at,last_success_at,last_failure_at,safe_message)
                 VALUES (?,?,UTC_TIMESTAMP(),
                         IF(?="healthy",UTC_TIMESTAMP(),NULL),
                         IF(?<>"healthy",UTC_TIMESTAMP(),NULL),?)
                 ON DUPLICATE KEY UPDATE status=VALUES(status),last_checked_at=VALUES(last_checked_at),
                   last_success_at=IF(VALUES(status)="healthy",UTC_TIMESTAMP(),last_success_at),
                   last_failure_at=IF(VALUES(status)<>"healthy",UTC_TIMESTAMP(),last_failure_at),
                   safe_message=VALUES(safe_message)'
            )->execute([$queueKey, $status, $status, $status, $message]);
        } catch (Throwable) {
            // La salud de la proyección nunca debe detener una cola operativa.
        }
    }

    /** @param array<string,mixed> $item */
    private function score(array $item): int
    {
        $tier = max(1, (int) ($item['priority_tier'] ?? 8));
        $created = (new SystemDatabaseUtcClock())->timestamp(
            !empty($item['created_at_source']) ? (string) $item['created_at_source'] : null
        );
        $ageMinutes = $created !== null ? max(0, (int) floor((time() - $created) / 60)) : 0;
        return (9 - min(8, $tier)) * 1000000 + min(999999, $ageMinutes);
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:list<mixed>} */
    private function where(array $filters): array
    {
        [$scopeSql, $params] = $this->scopePredicate('p');
        $conditions = [$scopeSql];
        if (!empty($filters['account_id'])) {
            (new BusinessScopeContext())->account((int) $filters['account_id']);
            $conditions[] = 'p.meli_account_id=?';
            $params[] = (int) $filters['account_id'];
        }
        if (!empty($filters['queue_key'])) {
            $conditions[] = 'p.queue_key=?';
            $params[] = (string) $filters['queue_key'];
        }
        if (!empty($filters['queue_keys']) && is_array($filters['queue_keys'])) {
            $known = (new WorkQueueRegistry())->definitionsByKey();
            $queueKeys = array_values(array_unique(array_filter(
                array_map('strval', $filters['queue_keys']),
                static fn (string $key): bool => isset($known[$key])
            )));
            if ($queueKeys !== []) {
                $conditions[] = 'p.queue_key IN (' . implode(',', array_fill(0, count($queueKeys), '?')) . ')';
                array_push($params, ...$queueKeys);
            }
        }
        if (!empty($filters['status'])) {
            $conditions[] = 'p.display_status=?';
            $params[] = (string) $filters['status'];
        }
        $resolution = (string) ($filters['resolution'] ?? '');
        if ($resolution !== '') {
            $hasHistory = (new SchemaInspectorService())->hasTable('system_work_historical_reconciliations');
            $historyMatch = 'h.company_id=p.company_id AND h.meli_account_id=p.meli_account_id
                AND h.queue_key=p.queue_key AND h.source_id=p.source_id
                AND h.observed_source_status=p.source_status
                AND (h.observed_source_updated_at IS NULL OR p.source_updated_at IS NULL
                     OR h.observed_source_updated_at=p.source_updated_at)';
            $historyExists = $hasHistory
                ? 'EXISTS (SELECT 1 FROM system_work_historical_reconciliations h WHERE ' . $historyMatch
                : '';
            if ($resolution === 'legacy_needs_diagnosis') {
                $conditions[] = 'p.display_status="error" AND p.reached_remote IS NULL'
                    . ($hasHistory ? ' AND NOT ' . $historyExists . ')' : '');
            } elseif ($resolution === 'retryable_local') {
                $conditions[] = 'p.display_status="error" AND (p.reached_remote=0'
                    . ($hasHistory ? ' OR ' . $historyExists . ' AND h.reached_remote=0)' : '') . ')';
            } elseif ($resolution === 'expected_absence') {
                $conditions[] = 'p.display_status="error" AND (COALESCE(p.normalized_error_code,"")="http_404"'
                    . ($hasHistory ? ' OR ' . $historyExists . ' AND h.normalized_error_code="http_404")' : '') . ')';
            } elseif ($resolution === 'remote_result_uncertain') {
                $conditions[] = 'p.display_status="error" AND (p.reached_remote=1 OR COALESCE(p.normalized_error_code,"")="remote_result_uncertain"'
                    . ($hasHistory ? ' OR ' . $historyExists . ' AND h.normalized_error_code="remote_result_uncertain")' : '') . ')';
            }
        }
        $group = (string) ($filters['group'] ?? '');
        if ($group === 'running') {
            $conditions[] = 'p.display_status="running"';
        } elseif ($group === 'attention') {
            $conditions[] = 'p.display_status="error"';
            $conditions[] = 'COALESCE(p.normalized_error_code,"") NOT IN ('
                . '"cron_deadline_deferred","deadline_reached","not_started_deadline",'
                . '"waiting_budget","waiting_rhythm","waiting_api","api_budget_exhausted",'
                . '"api_manual_pause","account_paused","account_stopped","lock_busy","lease_busy")';
            $conditions[] = 'COALESCE(p.remediation_key,"") NOT LIKE "waiting\\_%"';
        } elseif ($group === 'waiting') {
            $conditions[] = '(p.display_status IN ("pending","retry","waiting_budget","paused")'
                . ' OR (p.display_status="error" AND ('
                . 'COALESCE(p.normalized_error_code,"") IN ('
                . '"cron_deadline_deferred","deadline_reached","not_started_deadline",'
                . '"waiting_budget","waiting_rhythm","waiting_api","api_budget_exhausted",'
                . '"api_manual_pause","account_paused","account_stopped","lock_busy","lease_busy")'
                . ' OR COALESCE(p.remediation_key,"") LIKE "waiting\\_%")))';
        } elseif ($group === 'upcoming') {
            $conditions[] = 'p.display_status="scheduled"';
        } elseif ($group === 'completed') {
            $conditions[] = 'p.display_status="completed"';
        }
        if (($filters['mode'] ?? '') === 'api') {
            $conditions[] = 'p.is_api_task=1';
        } elseif (($filters['mode'] ?? '') === 'local') {
            $conditions[] = 'p.is_api_task=0';
        }
        if (!empty($filters['active_only'])) {
            $conditions[] = 'p.display_status<>"completed"';
        }
        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    /** @return array{0:string,1:list<int>} */
    private function scopePredicate(string $alias): array
    {
        $userId = (int) (Auth::id() ?? 0);
        if ($userId <= 0 && PHP_SAPI === 'cli') {
            // El orquestador CLI opera bajo el alcance del sistema y cada
            // adaptador vuelve a validar empresa + cuenta en su tabla fuente.
            return ['1=1', []];
        }
        $companies = (new BusinessScopeContext())->companyIds($userId);
        if ($companies === []) {
            return ['1=0', []];
        }
        $accounts = (new BusinessScopeContext())->accountIds($userId);
        $accountSql = $accounts === []
            ? $alias . '.meli_account_id IS NULL'
            : '(' . $alias . '.meli_account_id IS NULL OR ' . $alias . '.meli_account_id IN ('
                . implode(',', array_fill(0, count($accounts), '?')) . '))';
        return [
            $alias . '.company_id IN (' . implode(',', array_fill(0, count($companies), '?')) . ') AND ' . $accountSql,
            array_merge($companies, $accounts),
        ];
    }

    private function refreshIfStale(): void
    {
        try {
            $last = Database::connectionFresh()->query('SELECT MAX(projected_at) FROM system_work_queue_projection')->fetchColumn();
            $seconds = max(5, (new AppSettingsService())->int('automation.projection_refresh_seconds', 30));
            $lastProjectedAt = (new SystemDatabaseUtcClock())->timestamp(is_string($last) ? $last : null);
            if ($lastProjectedAt === null || $lastProjectedAt < time() - $seconds) {
                $this->refresh();
            }
        } catch (Throwable) {
            // La pantalla conservará la última proyección válida.
        }
    }
}
