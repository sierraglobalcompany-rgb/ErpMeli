<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class CronV3OwnershipService
{
    /** @var array<string,list<string>> */
    private const V2_TO_V3 = [
        'notification_spool' => ['notification_spool'],
        'notification_backfill' => ['notification_backfill'],
        'notification_fallback' => ['notification_normalize', 'order_exact', 'shipment_exact', 'question_exact', 'claim_exact', 'item_exact'],
        'orders_sync' => ['orders_search_page'],
        'order_enrichment' => ['pack_exact', 'shipment_exact'],
        'questions' => ['questions_search_page', 'question_exact'],
        'financial_recalc' => ['financial_recalc'],
        'sale_financial_reconciliation' => ['sale_billing_capture'],
        'sales_repair' => ['sales_repair_exact', 'order_exact'],
        'sale_pack_reconciliation' => ['sale_pack_reconciliation_exact'],
        'sales_audit' => ['sales_audit_page'],
        'sales_fiscal' => ['sales_fiscal_exact'],
        'catalog_descriptions' => ['catalog_description_exact'],
        'items_sync' => ['items_search_page', 'item_exact'],
        'module_jobs' => ['module_logistics_exact'],
        'recurring_sync' => ['recurring_schedule'],
        'order_date_repair' => ['order_date_repair'],
        'operational_maintenance' => ['operational_maintenance'],
        'monthly_report_maintenance' => ['monthly_report_maintenance'],
    ];

    /** @param list<array<string,mixed>> $definitions @return list<array<string,mixed>> */
    public function filterV2Definitions(array $definitions): array
    {
        if ((new CronV3OperationalModeService())->enabled()) {
            return [];
        }

        $owned = $this->v3OwnedTypes();
        $capabilities = new CronV3CutoverCapabilityRegistry();
        return array_values(array_filter(
            $definitions,
            static function (array $definition) use ($owned, $capabilities): bool {
                $v2Key = (string) ($definition['key'] ?? '');
                if ($v2Key === 'manual_campaign') {
                    return false;
                }
                if (!$capabilities->canTransferV2($v2Key)) {
                    return true;
                }
                $required = self::V2_TO_V3[$v2Key] ?? [$v2Key];
                foreach ($required as $v3Type) {
                    if (!isset($owned[$v3Type])) {
                        return true;
                    }
                }
                return false;
            }
        ));
    }

    /** @return array<string,true> */
    private function v3OwnedTypes(): array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('cron_v3_queue_ownership')) {
                return [];
            }
            $rows = Database::connectionFresh()->query(
                'SELECT queue_key FROM cron_v3_queue_ownership
                 WHERE owner_engine="v3" AND enabled=1'
            )->fetchAll(PDO::FETCH_COLUMN);
            return array_fill_keys(array_map('strval', $rows), true);
        } catch (Throwable) {
            // Fail safe for rollback: V2 remains available when V3 authority is
            // not readable. V3 itself is fail-closed without ownership.
            return [];
        }
    }
}
