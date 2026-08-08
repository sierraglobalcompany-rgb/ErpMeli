<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class QueryPlanAuditService
{
    /**
     * Solo contiene consultas conocidas, sin parámetros comerciales ni texto libre.
     *
     * @return array<string,string>
     */
    public function knownQueries(): array
    {
        return [
            'orders_recent' => "SELECT id FROM meli_orders WHERE meli_account_id=0 AND date_created>='2000-01-01' AND date_created<'2000-01-02' ORDER BY date_created DESC LIMIT 20",
            'payments_range' => "SELECT id FROM meli_payments WHERE meli_account_id=0 AND date_approved>='2000-01-01' AND date_approved<'2000-01-02' AND status='approved'",
            'shipments_status' => "SELECT id FROM meli_shipments WHERE meli_account_id=0 AND status='ready_to_ship' ORDER BY synced_at DESC LIMIT 20",
            'notifications_due' => "SELECT id FROM meli_notification_work_items WHERE status IN ('pending','retry') AND next_run_at<=UTC_TIMESTAMP() AND (lock_expires_at IS NULL OR lock_expires_at<=UTC_TIMESTAMP()) ORDER BY priority,next_run_at,id LIMIT 20",
            'item_sync_due' => "SELECT id FROM meli_item_sync_jobs WHERE phase IN ('discovering','details','partial') AND next_run_at<=UTC_TIMESTAMP() ORDER BY next_run_at LIMIT 1",
        ];
    }

    /** @return array<string,mixed> */
    public function explain(string $queryId): array
    {
        $queries = $this->knownQueries();
        if (!isset($queries[$queryId])) {
            throw new \InvalidArgumentException('Consulta de diagnóstico no registrada.');
        }
        $started = microtime(true);
        $rows = Database::connection()->query('EXPLAIN ' . $queries[$queryId])->fetchAll(PDO::FETCH_ASSOC);
        $summaryRows = array_map(static fn(array $row): array => [
            'table' => (string) ($row['table'] ?? ''),
            'access_type' => (string) ($row['type'] ?? ''),
            'possible_keys' => (string) ($row['possible_keys'] ?? ''),
            'key_used' => (string) ($row['key'] ?? ''),
            'estimated_rows' => (int) ($row['rows'] ?? 0),
            'extra' => (string) ($row['Extra'] ?? ''),
        ], $rows);
        $reviewReasons = $this->reviewReasons($summaryRows);
        return [
            'query_id' => $queryId,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'plan' => $summaryRows,
            'review_reasons' => $reviewReasons,
            'needs_review' => $reviewReasons !== [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function reviewReasons(array $rows): array
    {
        $reasons = [];
        foreach ($rows as $row) {
            $estimatedRows = (int) ($row['estimated_rows'] ?? 0);
            $accessType = (string) ($row['access_type'] ?? '');
            $extra = strtolower((string) ($row['extra'] ?? ''));
            if ($accessType === 'ALL' && $estimatedRows > 1000) {
                $reasons[] = 'full_scan_over_1000_rows';
            }
            if (str_contains($extra, 'using filesort') && $estimatedRows > 1000) {
                $reasons[] = 'filesort_over_1000_rows';
            }
            if (str_contains($extra, 'using temporary') && $estimatedRows > 1000) {
                $reasons[] = 'temporary_table_over_1000_rows';
            }
            if (($row['key_used'] ?? '') === '' && $estimatedRows > 1000) {
                $reasons[] = 'no_index_over_1000_rows';
            }
        }
        return array_values(array_unique($reasons));
    }

    /** @return array<string,array<string,mixed>> */
    public function auditAll(): array
    {
        $results = [];
        foreach (array_keys($this->knownQueries()) as $queryId) {
            try {
                $results[$queryId] = $this->explain($queryId);
            } catch (Throwable $error) {
                $results[$queryId] = [
                    'query_id' => $queryId,
                    'error' => 'No fue posible revisar el plan.',
                    'diagnostic' => SafeErrorPresenter::report(
                        $error,
                        'No fue posible revisar un plan de consulta.',
                        ['query_id' => $queryId]
                    )['reference'],
                ];
            }
        }
        return $results;
    }
}
