<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\CronV3Handlers\ClaimExactHandler;
use App\Services\CronV3Handlers\ClaimsSearchPageHandler;
use App\Services\CronV3Handlers\CatalogDescriptionExactHandler;
use App\Services\CronV3Handlers\FinancialGapScanHandler;
use App\Services\CronV3Handlers\FinancialLocalProjectionHandler;
use App\Services\CronV3Handlers\FinancialRecalcHandler;
use App\Services\CronV3Handlers\ItemExactHandler;
use App\Services\CronV3Handlers\ItemsSearchPageHandler;
use App\Services\CronV3Handlers\ModuleLogisticsExactHandler;
use App\Services\CronV3Handlers\MonthlyReportMaintenanceHandler;
use App\Services\CronV3Handlers\NotificationBackfillHandler;
use App\Services\CronV3Handlers\NotificationNormalizeHandler;
use App\Services\CronV3Handlers\NotificationSpoolHandler;
use App\Services\CronV3Handlers\OAuthRefreshHandler;
use App\Services\CronV3Handlers\OperationalMaintenanceHandler;
use App\Services\CronV3Handlers\OrderExactHandler;
use App\Services\CronV3Handlers\OrderResourceExactHandler;
use App\Services\CronV3Handlers\OrdersSearchPageHandler;
use App\Services\CronV3Handlers\QuestionExactHandler;
use App\Services\CronV3Handlers\QuestionsSearchPageHandler;
use App\Services\CronV3Handlers\RecurringScheduleHandler;
use App\Services\CronV3Handlers\SalePackReconciliationExactHandler;
use App\Services\CronV3Handlers\SaleBillingCaptureHandler;
use App\Services\CronV3Handlers\SalesAuditPageHandler;
use App\Services\CronV3Handlers\SalesRepairExactHandler;

final class CronV3DefaultHandlerBootstrap
{
    public static function register(CronV3HandlerRegistry $handlers): void
    {
        $handlers->register('oauth_refresh', 'remote', new OAuthRefreshHandler());
        $handlers->register('claims_search_page', 'remote', new ClaimsSearchPageHandler());
        $handlers->register('claim_exact', 'remote', new ClaimExactHandler());
        $handlers->register('orders_search_page', 'remote', new OrdersSearchPageHandler());
        $handlers->register('order_exact', 'remote', new OrderExactHandler());
        $handlers->register('pack_exact', 'remote', new OrderResourceExactHandler('pack'));
        $handlers->register('shipment_exact', 'remote', new OrderResourceExactHandler('shipment'));
        $handlers->register('questions_search_page', 'remote', new QuestionsSearchPageHandler());
        $handlers->register('question_exact', 'remote', new QuestionExactHandler());
        $handlers->register('items_search_page', 'remote', new ItemsSearchPageHandler());
        $handlers->register('item_exact', 'remote', new ItemExactHandler());
        $handlers->register('catalog_description_exact', 'remote', new CatalogDescriptionExactHandler());
        $handlers->register('module_logistics_exact', 'remote', new ModuleLogisticsExactHandler());
        $handlers->register('notification_spool', 'local', new NotificationSpoolHandler());
        $handlers->register('notification_normalize', 'local', new NotificationNormalizeHandler());
        $handlers->register('notification_backfill', 'local', new NotificationBackfillHandler());
        $handlers->register('recurring_schedule', 'local', new RecurringScheduleHandler());
        $handlers->register('financial_local_projection', 'local', new FinancialLocalProjectionHandler());
        $handlers->register('financial_recalc', 'local', new FinancialRecalcHandler());
        $handlers->register('financial_gap_scan', 'local', new FinancialGapScanHandler());
        $handlers->register('sale_billing_capture', 'remote', new SaleBillingCaptureHandler());
        $handlers->register('sale_pack_reconciliation_exact', 'remote', new SalePackReconciliationExactHandler());
        $handlers->register('sales_audit_page', 'remote', new SalesAuditPageHandler());
        $handlers->register('sales_repair_exact', 'remote', new SalesRepairExactHandler());
        $handlers->register('operational_maintenance', 'local', new OperationalMaintenanceHandler());
        $handlers->register('monthly_report_maintenance', 'local', new MonthlyReportMaintenanceHandler());
    }

    /** @return array<string,array{enabled:bool,reason:string,lane?:string}> */
    public static function availability(): array
    {
        return [
            'orders_search_page' => ['enabled' => true, 'reason' => 'confirmed_single_page', 'lane' => 'remote'],
            'questions_search_page' => ['enabled' => true, 'reason' => 'confirmed_single_page_without_cursor', 'lane' => 'remote'],
            'question_exact' => ['enabled' => true, 'reason' => 'confirmed_single_exact', 'lane' => 'remote'],
            'items_search_page' => ['enabled' => true, 'reason' => 'confirmed_single_page', 'lane' => 'remote'],
            'item_exact' => ['enabled' => true, 'reason' => 'confirmed_single_exact', 'lane' => 'remote'],
            'catalog_description_exact' => ['enabled' => true, 'reason' => 'confirmed_single_exact', 'lane' => 'remote'],
            'module_logistics_exact' => ['enabled' => true, 'reason' => 'gated_confirmed_single_exact', 'lane' => 'remote'],
            'notification_spool' => ['enabled' => true, 'reason' => 'local_spool_to_validated_events_without_remote_transport', 'lane' => 'local'],
            'notification_normalize' => ['enabled' => true, 'reason' => 'local_events_to_exact_v3_work_without_remote_transport', 'lane' => 'local'],
            'notification_backfill' => ['enabled' => true, 'reason' => 'local_backfill_checkpointed_without_campaign_continuation', 'lane' => 'local'],
            'recurring_schedule' => ['enabled' => true, 'reason' => 'local_recurring_range_producer_with_existing_account_scope', 'lane' => 'local'],
            'sale_pack_reconciliation_exact' => ['enabled' => true, 'reason' => 'confirmed_exact_pack_reconciliation_job', 'lane' => 'remote'],
            'sales_audit_page' => ['enabled' => true, 'reason' => 'confirmed_single_audit_page', 'lane' => 'remote'],
            'sales_repair_exact' => ['enabled' => true, 'reason' => 'confirmed_exact_missing_order_repair_one_item', 'lane' => 'remote'],
            'operational_maintenance' => ['enabled' => true, 'reason' => 'local_lock_and_freeze_guarded', 'lane' => 'local'],
            'monthly_report_maintenance' => ['enabled' => true, 'reason' => 'local_lock_and_freeze_guarded', 'lane' => 'local'],
            'sales_fiscal_exact' => [
                'enabled' => false,
                'reason' => 'legacy_service_claims_unscoped_due_job_and_has_no_public_exact_persistence_contract',
                'lane' => 'remote',
            ],
        ];
    }
}
