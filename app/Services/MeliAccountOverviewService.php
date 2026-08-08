<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class MeliAccountOverviewService
{
    /** @return array<int,array<string,int>> */
    public function summaries(): array
    {
        $cacheKey = 'erp_meli_account_overview_v2';
        if (function_exists('apcu_fetch') && (bool) ini_get('apc.enabled')) {
            $hit = false;
            $cached = apcu_fetch($cacheKey, $hit);
            if ($hit && is_array($cached)) {
                return $cached;
            }
        }

        $summary = [];
        $this->merge($summary, $this->grouped(
            "SELECT meli_account_id,COUNT(*) metric
             FROM meli_orders
             WHERE date_created>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01')
             GROUP BY meli_account_id"
        ), 'orders_period');
        $this->merge($summary, $this->grouped(
            "SELECT i.meli_account_id,COUNT(*) metric
             FROM meli_items i
             LEFT JOIN product_meli_links l
               ON l.meli_account_id=i.meli_account_id
              AND l.meli_item_id=i.id
              AND l.status='active'
             WHERE l.id IS NULL
             GROUP BY i.meli_account_id"
        ), 'unlinked_products');

        $schema = new SchemaInspectorService();
        if ($schema->hasTable('meli_notification_work_items')) {
            $this->merge($summary, $this->grouped(
                "SELECT meli_account_id,COUNT(*) metric
                 FROM meli_notification_work_items
                 WHERE status IN ('pending','retry','running','paused','error')
                 GROUP BY meli_account_id"
            ), 'pending_webhooks');
        } else {
            $this->merge($summary, $this->grouped(
                "SELECT meli_account_id,COUNT(*) metric
                 FROM meli_webhook_events
                 WHERE status='pending'
                 GROUP BY meli_account_id"
            ), 'pending_webhooks');
        }
        $this->merge($summary, $this->grouped(
            'SELECT meli_account_id,COUNT(*) metric
             FROM api_error_logs
             WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)
             GROUP BY meli_account_id'
        ), 'recent_errors');

        if (function_exists('apcu_store') && (bool) ini_get('apc.enabled')) {
            apcu_store($cacheKey, $summary, 30);
        }
        return $summary;
    }

    /** @return array<int,array{meli_account_id:int,metric:int}> */
    private function grouped(string $sql): array
    {
        try {
            return Database::connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<int,array<string,int>> $summary
     * @param array<int,array{meli_account_id:int|string,metric:int|string}> $rows
     */
    private function merge(array &$summary, array $rows, string $metric): void
    {
        foreach ($rows as $row) {
            $accountId = (int) $row['meli_account_id'];
            $summary[$accountId] ??= [];
            $summary[$accountId][$metric] = (int) $row['metric'];
        }
    }
}
