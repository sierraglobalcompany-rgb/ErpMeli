<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\AlertController;
use App\Controllers\BackupController;
use App\Controllers\CatalogController;
use App\Controllers\CatalogDescriptionJobController;
use App\Controllers\ClaimController;
use App\Controllers\CompanyController;
use App\Controllers\DashboardController;
use App\Controllers\DateReportController;
use App\Controllers\DatabaseMaintenanceController;
use App\Controllers\ExportController;
use App\Controllers\EmergencyControlController;
use App\Controllers\FinancialRecalcController;
use App\Controllers\InternalProductController;
use App\Controllers\InventoryController;
use App\Controllers\ImportedMeliDataResetController;
use App\Controllers\MeliAccountController;
use App\Controllers\MeliProductController;
use App\Controllers\MeliProductReviewController;
use App\Controllers\ModuleAdminController;
use App\Controllers\NotificationController;
use App\Controllers\MonthlyReportController;
use App\Controllers\OrderController;
use App\Controllers\LogController;
use App\Controllers\PackController;
use App\Controllers\PaymentController;
use App\Controllers\PerformanceController;
use App\Controllers\ProductLinkController;
use App\Controllers\ProductImportController;
use App\Controllers\ProfitabilityController;
use App\Controllers\PublicCatalogController;
use App\Controllers\QuestionController;
use App\Controllers\InstallController;
use App\Controllers\SettingsController;
use App\Controllers\SettingsSectionController;
use App\Controllers\ShipmentController;
use App\Controllers\ShellController;
use App\Controllers\SalesControlController;
use App\Controllers\SaleController;
use App\Controllers\SyncController;
use App\Controllers\UpdateController;
use App\Controllers\UserController;
use App\Controllers\UnlinkedProductController;
use App\Controllers\WebhookController;
use App\Core\Router;
use App\Core\AppPaths;
use App\Core\Auth;
use App\Core\Container;
use App\Core\Env;
use App\Core\Modules\ModuleKernel;
use App\Core\View;
use App\Repositories\RouteMetadataRepository;
use App\Services\AppVersionService;
use App\Services\BackupCenterService;
use App\Services\InstalledVersionMarkerService;
use App\Services\DatabaseMutationFreezeService;
use App\ValueObjects\RequestContext;

if (!defined('ERP_RELEASE_BOOTSTRAPPED')) {
    $installationRoot = dirname(__DIR__);
    if (is_file($installationRoot . '/shared/current-release.json') && is_file($installationRoot . '/launcher/web.php')) {
        require $installationRoot . '/launcher/web.php';
        return;
    }
}

$root = require dirname(__DIR__) . '/bootstrap.php';

if (!is_file(AppPaths::configFile())) {
    (new InstallController(AppPaths::installationRoot()))->handle();
    exit;
}

$requestedPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$publicCatalogRequest = preg_match('#/catalogo(?:/|$)#', $requestedPath) === 1;
if (
    Auth::check()
    && !$publicCatalogRequest
    && (new InstalledVersionMarkerService())->requiresUpdate(AppVersionService::fileVersion())
) {
    $updateUrl = rtrim((string) Env::get('APP_URL', ''), '/') . '/actualizar.php';
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json') || str_ends_with($requestedPath, '.json')) {
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode([
            'ok' => false,
            'error' => 'Un administrador debe completar la actualización.',
            'code' => 'update_required',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (Auth::role() === 'admin' && !Auth::isTemporary()) {
        header('Location: ' . $updateUrl, true, 303);
        exit;
    }
    http_response_code(503);
    View::render('errors/update_required', ['updateUrl' => $updateUrl], false);
    exit;
}

$container = Container::application();
$container->instance(RequestContext::class, RequestContext::fromGlobals());
$router = new Router($container, new RouteMetadataRepository());
$container->instance(Router::class, $router);
$router->get('/', [DashboardController::class, 'index']);
$router->get('/dashboard/summary.json', [DashboardController::class, 'summarySection']);
$router->get('/dashboard/operations.json', [DashboardController::class, 'operationsSection']);
$router->get('/dashboard/health.json', [DashboardController::class, 'healthSection']);
$router->get('/shell/context.json', [ShellController::class, 'context']);
$router->get('/shell/status.json', [ShellController::class, 'status']);
$router->get('/shell/snapshot.json', [ShellController::class, 'snapshot']);
$router->get('/sales-control', [SalesControlController::class, 'index']);
$router->get('/sales-control/overview.json', [SalesControlController::class, 'overviewSection']);
$router->get('/sales-control/month', [SalesControlController::class, 'month']);
$router->get('/sales-control/issues', [SalesControlController::class, 'issues']);
$router->get('/sales-control/fiscal', [SalesControlController::class, 'fiscal']);
$router->get('/sales-control/closes', [SalesControlController::class, 'closes']);
$router->post('/sales-control/check-year', [SalesControlController::class, 'checkYear']);
$router->post('/sales-control/check-month', [SalesControlController::class, 'checkMonth']);
$router->post('/sales-control/repair-missing', [SalesControlController::class, 'repairMissing']);
$router->post('/sales-control/recalculate-dates', [SalesControlController::class, 'recalculateDates']);
$router->post('/sales-control/prepare-fiscal', [SalesControlController::class, 'prepareFiscal']);
$router->post('/sales-control/close', [SalesControlController::class, 'close']);
$router->post('/sales-control/reopen', [SalesControlController::class, 'reopen']);
$router->get('/sales', [SaleController::class, 'index']);
$router->get('/sales/import-status.json', [SaleController::class, 'importStatusSection']);
$router->post('/sales/import-year', [SaleController::class, 'importYear']);
$router->post('/sales/import-available', [SaleController::class, 'importAvailable']);
$router->get('/sales/show', [SaleController::class, 'show']);
$router->get('/sales/orders', [SaleController::class, 'orders']);
$router->get('/sales/integrity', [SaleController::class, 'integrity']);
$router->get('/sales/integrity/show', [SaleController::class, 'integrityShow']);
$router->post('/sales/integrity/reconcile', [SaleController::class, 'reconcile']);
$router->post('/sales/financial/queue', [SaleController::class, 'queueFinancial']);
$router->get('/login', [AuthController::class, 'show']);
$router->get('/login.php', [AuthController::class, 'show']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->post('/logout.php', [AuthController::class, 'logout']);
$router->get('/settings/modules', [ModuleAdminController::class, 'index']);
$router->post('/settings/modules/migrate', [ModuleAdminController::class, 'migrate']);
$router->post('/settings/modules/enable', [ModuleAdminController::class, 'enable']);
$router->post('/settings/modules/disable', [ModuleAdminController::class, 'disable']);
$router->post('/settings/modules/diagnose', [ModuleAdminController::class, 'diagnose']);
$router->get('/settings/modules/jobs/status.json', [ModuleAdminController::class, 'queueStatus']);
$router->post('/settings/modules/events/reconcile', [ModuleAdminController::class, 'reconcileEvents']);
$router->get('/companies', [CompanyController::class, 'index']);
$router->post('/companies', [CompanyController::class, 'store']);
$router->get('/companies/show', [CompanyController::class, 'show']);
$router->post('/companies/update', [CompanyController::class, 'update']);
$router->post('/companies/deactivate', [CompanyController::class, 'deactivate']);
$router->post('/companies/reactivate', [CompanyController::class, 'reactivate']);
$router->post('/companies/delete', [CompanyController::class, 'delete']);
$router->get('/accounts', [MeliAccountController::class, 'index']);
$router->post('/accounts/connect', [MeliAccountController::class, 'connect']);
$router->post('/accounts/settings', [MeliAccountController::class, 'saveApplication']);
$router->get('/meli_callback.php', [MeliAccountController::class, 'callback']);
$router->get('/orders', [OrderController::class, 'index']);
$router->get('/orders/section.json', [OrderController::class, 'section']);
$router->get('/orders/show', [OrderController::class, 'show']);
$router->post('/orders/sync', [OrderController::class, 'sync']);
$router->post('/orders/refresh', [OrderController::class, 'refresh']);
$router->post('/orders/financial/queue', [OrderController::class, 'queueFinancial']);
$router->post('/orders/financial/recalculate', [OrderController::class, 'recalculateFinancial']);
$router->post('/orders/financial/manual-review', [OrderController::class, 'manualReviewFinancial']);
$router->get('/sync', [SyncController::class, 'index']);
$router->get('/sync/section.json', [SyncController::class, 'section']);
$router->get('/sync/account', [SyncController::class, 'account']);
$router->post('/sync/plan', [SyncController::class, 'plan']);
$router->post('/sync/chunk/enqueue', [SyncController::class, 'enqueueChunk']);
$router->post('/sync/chunk/retry', [SyncController::class, 'retryChunk']);
$router->post('/sync/chunk/cancel', [SyncController::class, 'cancelChunk']);
$router->post('/sync/chunk/delete', [SyncController::class, 'deleteChunk']);
$router->post('/sync/chunk/reactivate', [SyncController::class, 'reactivateChunk']);
$router->post('/sync/chunk/reschedule', [SyncController::class, 'rescheduleChunk']);
$router->post('/sync/overdue/reschedule', [SyncController::class, 'rescheduleOverdue']);
$router->post('/sync/batch/delete', [SyncController::class, 'deleteBatch']);
$router->post('/sync/process-now', [SyncController::class, 'processNow']);
$router->post('/sync/process-now.json', [SyncController::class, 'processNowJson']);
$router->post('/sync/assisted-step.json', [SyncController::class, 'assistedStepJson']);
$router->post('/sync/assisted/resume', [SyncController::class, 'assistedResume']);
$router->post('/sync/assisted/retry-failed', [SyncController::class, 'assistedRetryFailed']);
$router->get('/sync/status', [SyncController::class, 'status']);
$router->get('/sync/schedule', [SyncController::class, 'schedule']);
$router->get('/sync/schedule.json', [SyncController::class, 'scheduleJson']);
$router->get('/sync/guardrails', [SyncController::class, 'guardrails']);
$router->get('/sync/diagnostics', [SyncController::class, 'diagnostics']);
$router->post('/sync/diagnostics/run', [SyncController::class, 'runDiagnostics']);
$router->get('/sync/audit', [SyncController::class, 'audit']);
$router->get('/sync/audit/run', [SyncController::class, 'auditRunShow']);
$router->get('/sync/audit/repair/preview', [SyncController::class, 'auditRepairPreview']);
$router->get('/sync/audit/repair', [SyncController::class, 'auditRepairShow']);
$router->post('/sync/audit/run', [SyncController::class, 'runAudit']);
$router->post('/sync/audit/day', [SyncController::class, 'auditDay']);
$router->post('/sync/audit/enqueue-missing', [SyncController::class, 'auditEnqueueMissing']);
$router->post('/sync/audit/compare-ids', [SyncController::class, 'auditCompareIds']);
$router->post('/sync/audit/compare-month', [SyncController::class, 'auditCompareMonth']);
$router->post('/sync/audit/repair-month', [SyncController::class, 'auditRepairMonth']);
$router->post('/sync/audit/financial-month', [SyncController::class, 'auditFinancialMonth']);
$router->post('/sync/audit/financial-day', [SyncController::class, 'auditFinancialDay']);
$router->post('/sync/audit/reclassify-existing', [SyncController::class, 'auditReclassifyExisting']);
$router->post('/sync/audit/repair-real-missing', [SyncController::class, 'auditRepairRealMissing']);
$router->post('/sync/audit/repair/pause', [SyncController::class, 'auditRepairPause']);
$router->post('/sync/audit/repair/resume', [SyncController::class, 'auditRepairResume']);
$router->post('/sync/audit/recalculate-dates', [SyncController::class, 'auditRecalculateDates']);
$router->post('/sync/audit/diagnose-dates', [SyncController::class, 'auditDiagnoseDates']);
$router->get('/sync/recurring', [SyncController::class, 'recurring']);
$router->post('/sync/recurring/save', [SyncController::class, 'saveRecurring']);
$router->get('/financial-recalc', [FinancialRecalcController::class, 'index']);
$router->get('/financial-recalc/section.json', [FinancialRecalcController::class, 'section']);
$router->get('/financial-recalc/show', [FinancialRecalcController::class, 'show']);
$router->get('/financial-recalc/status.json', [FinancialRecalcController::class, 'statusJson']);
$router->post('/financial-recalc/process-now', [FinancialRecalcController::class, 'processNow']);
$router->post('/financial-recalc/assisted-step', [FinancialRecalcController::class, 'assistedStep']);
$router->post('/financial-recalc/retry-failed', [FinancialRecalcController::class, 'retryFailed']);
$router->post('/financial-recalc/cancel', [FinancialRecalcController::class, 'cancel']);
$router->get('/financial-recalc/errors', [FinancialRecalcController::class, 'errors']);
$router->get('/packs', [PackController::class, 'index']);
$router->get('/shipments', [ShipmentController::class, 'index']);
$router->get('/shipments/section.json', [ShipmentController::class, 'section']);
$router->get('/shipments/export', [ShipmentController::class, 'export']);
$router->get('/payments', [PaymentController::class, 'index']);
$router->get('/claims', [ClaimController::class, 'index']);
$router->post('/claims/sync', [ClaimController::class, 'sync']);
$router->get('/questions', [QuestionController::class, 'index']);
$router->post('/questions/sync', [QuestionController::class, 'sync']);
$router->get('/products/meli', [MeliProductController::class, 'index']);
$router->get('/products/meli/section.json', [MeliProductController::class, 'section']);
$router->get('/products/meli/show', [MeliProductController::class, 'show']);
$router->post('/products/meli/sync', [MeliProductController::class, 'sync']);
$router->get('/products/meli/reviews', [MeliProductReviewController::class, 'index']);
$router->get('/products/meli/reviews/show', [MeliProductReviewController::class, 'show']);
$router->post('/products/meli/reviews/approve', [MeliProductReviewController::class, 'approve']);
$router->post('/products/meli/reviews/reject', [MeliProductReviewController::class, 'reject']);
$router->post('/products/meli/reviews/apply', [MeliProductReviewController::class, 'apply']);
$router->get('/products/imports', [ProductImportController::class, 'index']);
$router->post('/products/imports/from-meli', [ProductImportController::class, 'fromMeli']);
$router->get('/products/internal', [InternalProductController::class, 'index']);
$router->post('/products/internal', [InternalProductController::class, 'store']);
$router->post('/products/internal/from-item', [InternalProductController::class, 'fromItem']);
$router->post('/products/internal/from-order', [InternalProductController::class, 'fromOrder']);
$router->get('/inventory', [InventoryController::class, 'index']);
$router->get('/inventory/kardex', [InventoryController::class, 'kardex']);
$router->post('/inventory/warehouses', [InventoryController::class, 'createWarehouse']);
$router->post('/inventory/warehouses/default', [InventoryController::class, 'defaultWarehouse']);
$router->post('/inventory/warehouses/status', [InventoryController::class, 'warehouseStatus']);
$router->post('/inventory/movements', [InventoryController::class, 'movement']);
$router->post('/inventory/reviews/retry', [InventoryController::class, 'retryReview']);
$router->post('/inventory/reviews/dismiss', [InventoryController::class, 'dismissReview']);
$router->get('/products/links', [ProductLinkController::class, 'index']);
$router->post('/products/links', [ProductLinkController::class, 'store']);
$router->post('/products/links/factor', [ProductLinkController::class, 'updateFactor']);
$router->post('/products/links/delete', [ProductLinkController::class, 'delete']);
$router->get('/products/unlinked', [UnlinkedProductController::class, 'index']);
$router->post('/products/unlinked/suggest', [UnlinkedProductController::class, 'suggest']);
$router->post('/products/unlinked/ignore', [UnlinkedProductController::class, 'ignore']);
$router->post('/products/unlinked/ignore-batch', [UnlinkedProductController::class, 'ignoreBatch']);
$router->get('/catalogs', [CatalogController::class, 'index']);
$router->get('/catalogs/create', [CatalogController::class, 'create']);
$router->post('/catalogs', [CatalogController::class, 'store']);
$router->get('/catalogs/private', [CatalogController::class, 'private']);
$router->get('/catalogs/categories', [CatalogController::class, 'categories']);
$router->get('/catalogs/settings', [CatalogController::class, 'settings']);
$router->post('/catalogs/categories/update', [CatalogController::class, 'updateCategory']);
$router->get('/catalogs/{id}/section.json', [CatalogController::class, 'section']);
$router->get('/catalogs/{id}', [CatalogController::class, 'show']);
$router->get('/catalogs/{id}/edit', [CatalogController::class, 'edit']);
$router->post('/catalogs/{id}/update', [CatalogController::class, 'update']);
$router->post('/catalogs/{id}/refresh', [CatalogController::class, 'refresh']);
$router->post('/catalogs/{id}/categories/resolve', [CatalogController::class, 'resolveCategories']);
$router->post('/catalogs/{id}/stock/resolve', [CatalogController::class, 'resolveStock']);
$router->post('/catalogs/{id}/descriptions/sync', [CatalogController::class, 'syncDescriptions']);
$router->get('/catalogs/{id}/description-jobs', [CatalogDescriptionJobController::class, 'index']);
$router->post('/catalogs/{id}/description-jobs', [CatalogDescriptionJobController::class, 'create']);
$router->get('/catalogs/description-jobs/{jobId}', [CatalogDescriptionJobController::class, 'show']);
$router->get('/catalogs/description-jobs/{jobId}/status.json', [CatalogDescriptionJobController::class, 'status']);
$router->post('/catalogs/description-jobs/{jobId}/step', [CatalogDescriptionJobController::class, 'step']);
$router->post('/catalogs/description-jobs/{jobId}/pause', [CatalogDescriptionJobController::class, 'pause']);
$router->post('/catalogs/description-jobs/{jobId}/resume', [CatalogDescriptionJobController::class, 'resume']);
$router->post('/catalogs/description-jobs/{jobId}/cancel', [CatalogDescriptionJobController::class, 'cancel']);
$router->post('/catalogs/description-jobs/{jobId}/reopen', [CatalogDescriptionJobController::class, 'reopen']);
$router->post('/catalogs/description-jobs/{jobId}/retry', [CatalogDescriptionJobController::class, 'retry']);
$router->post('/catalogs/{id}/token/regenerate', [CatalogController::class, 'regenerateToken']);
$router->post('/catalogs/{id}/token/test', [CatalogController::class, 'testToken']);
$router->post('/catalogs/{id}/password', [CatalogController::class, 'updatePassword']);
$router->post('/catalogs/items/toggle', [CatalogController::class, 'toggleItem']);
$router->get('/catalogs/{id}/print', [CatalogController::class, 'printView']);
$router->get('/catalogs/{id}/export', [CatalogController::class, 'export']);
$router->get('/billing', [MonthlyReportController::class, 'index']);
$router->post('/billing', [MonthlyReportController::class, 'create']);
$router->get('/billing/show', [MonthlyReportController::class, 'show']);
$router->post('/billing/update', [MonthlyReportController::class, 'update']);
$router->post('/billing/transition', [MonthlyReportController::class, 'transition']);
$router->get('/billing/export', [MonthlyReportController::class, 'export']);
$router->get('/billing/date', [DateReportController::class, 'index']);
$router->post('/billing/date', [DateReportController::class, 'create']);
$router->get('/billing/date/show', [DateReportController::class, 'show']);
$router->get('/billing/date/item-orders', [DateReportController::class, 'itemOrders']);
$router->get('/billing/date/item-orders/export', [DateReportController::class, 'itemOrdersExport']);
$router->post('/billing/date/update', [DateReportController::class, 'update']);
$router->post('/billing/date/transition', [DateReportController::class, 'transition']);
$router->post('/billing/date/recalculate', [DateReportController::class, 'recalculate']);
$router->post('/billing/date/financial/recalculate', [DateReportController::class, 'financialRecalculate']);
$router->get('/billing/date/export', [DateReportController::class, 'export']);
$router->get('/reports', [MonthlyReportController::class, 'index']);
$router->post('/reports', [MonthlyReportController::class, 'create']);
$router->get('/reports/show', [MonthlyReportController::class, 'show']);
$router->post('/reports/update', [MonthlyReportController::class, 'update']);
$router->post('/reports/transition', [MonthlyReportController::class, 'transition']);
$router->get('/reports/export', [MonthlyReportController::class, 'export']);
$router->get('/reports/date', [DateReportController::class, 'index']);
$router->post('/reports/date', [DateReportController::class, 'create']);
$router->get('/reports/date/show', [DateReportController::class, 'show']);
$router->get('/reports/date/export', [DateReportController::class, 'export']);
$router->get('/reports/profitability', [ProfitabilityController::class, 'index']);
$router->get('/reports/profitability/section.json', [ProfitabilityController::class, 'section']);
$router->get('/exports', [ExportController::class, 'index']);
$router->get('/alerts', [AlertController::class, 'index']);
$router->post('/alerts/refresh', [AlertController::class, 'refresh']);
$router->post('/alerts/resolve', [AlertController::class, 'resolve']);
$router->post('/alerts/resolve-batch', [AlertController::class, 'resolveBatch']);
$router->get('/notifications', [NotificationController::class, 'index']);
$router->get('/notifications/section.json', [NotificationController::class, 'section']);
$router->get('/notifications/activity', [NotificationController::class, 'activity']);
$router->get('/notifications/health', [NotificationController::class, 'health']);
$router->get('/notifications/status.json', [NotificationController::class, 'statusJson']);
$router->get('/notifications/automation', [NotificationController::class, 'automation']);
$router->get('/notifications/automation/status.json', [NotificationController::class, 'automationStatusJson']);
$router->post('/notifications/automation/test', [NotificationController::class, 'automationTest']);
$router->post('/notifications/automation/assisted-step', [NotificationController::class, 'automationAssistedStep']);
$router->get('/notifications/technical/events', [NotificationController::class, 'technicalEvents']);
$router->post('/notifications/work/process', [NotificationController::class, 'processWork']);
$router->post('/notifications/work/pause', [NotificationController::class, 'pauseWork']);
$router->post('/notifications/work/resume', [NotificationController::class, 'resumeWork']);
$router->post('/notifications/work/retry', [NotificationController::class, 'retryWork']);
$router->post('/notifications/backfill/analyze', [NotificationController::class, 'analyzeBackfill']);
$router->post('/notifications/backfill/start', [NotificationController::class, 'startBackfill']);
$router->post('/notifications/backfill/pause', [NotificationController::class, 'pauseBackfill']);
$router->post('/notifications/read', [NotificationController::class, 'markRead']);
$router->post('/notifications/dismiss', [NotificationController::class, 'dismiss']);
$router->get('/notifications/events', [NotificationController::class, 'events']);
$router->post('/notifications/events/retry', [NotificationController::class, 'retryEvent']);
$router->get('/notifications/missed', [NotificationController::class, 'missed']);
$router->post('/notifications/missed/run', [NotificationController::class, 'runMissed']);
$router->post('/performance/metrics', [PerformanceController::class, 'collect']);
$router->get('/webhooks', [WebhookController::class, 'index']);
$router->get('/logs', [LogController::class, 'index']);
$router->get('/logs/api/summary.json', [LogController::class, 'apiSummary']);
$router->get('/settings', [SettingsController::class, 'index']);
$router->get('/settings/emergency-control', [EmergencyControlController::class, 'index']);
$router->post('/settings/emergency-control/provision', [EmergencyControlController::class, 'provision']);
$router->post('/settings/emergency-control/revoke', [EmergencyControlController::class, 'revoke']);
$router->get('/settings/backups', [BackupController::class, 'index']);
$router->get('/settings/backups/status.json', [BackupController::class, 'status']);
$router->get('/settings/backups/interactive/status.json', [BackupController::class, 'status']);
$router->post('/settings/backups/create', [BackupController::class, 'create']);
$router->post('/settings/backups/interactive/start', [BackupController::class, 'interactiveStart']);
$router->post('/settings/backups/interactive/step', [BackupController::class, 'interactiveStep']);
$router->post('/settings/backups/interactive/cancel', [BackupController::class, 'interactiveCancel']);
$router->post('/settings/backups/verify', [BackupController::class, 'verify']);
$router->post('/settings/backups/download-grant', [BackupController::class, 'downloadGrant']);
$router->get('/settings/backups/download', [BackupController::class, 'download']);
$router->post('/settings/backups/delete', [BackupController::class, 'delete']);
$router->post('/settings/backups/cancel', [BackupController::class, 'cancel']);
$router->post('/settings/backups/cleanup', [BackupController::class, 'cancel']);
$router->post('/settings/backups/recover', [BackupController::class, 'recover']);
$router->get('/settings/backups/cancel', [BackupController::class, 'index']);
$router->get('/settings/backups/cleanup', [BackupController::class, 'index']);
$router->get('/settings/backups/delete', [BackupController::class, 'index']);
$router->get('/settings/backups/recover', [BackupController::class, 'index']);
$router->post('/settings/backups/recovery-key', [BackupController::class, 'recoveryKey']);
$router->post('/settings/backups/recovery-key/import', [BackupController::class, 'recoveryKeyImport']);
$router->get('/settings/backups/restore', [BackupController::class, 'restore']);
$router->post('/settings/backups/restore/prepare', [BackupController::class, 'restorePrepare']);
$router->post('/settings/backups/restore/start', [BackupController::class, 'restoreStart']);
$router->post('/settings/backups/restore/switch', [BackupController::class, 'restoreSwitch']);
$router->post('/settings/backups/restore/rollback', [BackupController::class, 'restoreRollback']);
$router->get('/settings/database-maintenance', [DatabaseMaintenanceController::class, 'index']);
$router->get('/settings/database-maintenance/protect', [DatabaseMaintenanceController::class, 'protectInfo']);
$router->get('/settings/database-maintenance/status.json', [DatabaseMaintenanceController::class, 'status']);
$router->post('/settings/database-maintenance/analyze', [DatabaseMaintenanceController::class, 'analyze']);
$router->post('/settings/database-maintenance/protect', [DatabaseMaintenanceController::class, 'protect']);
$router->post('/settings/database-maintenance/start', [DatabaseMaintenanceController::class, 'start']);
$router->post('/settings/database-maintenance/step', [DatabaseMaintenanceController::class, 'step']);
$router->post('/settings/database-maintenance/pause', [DatabaseMaintenanceController::class, 'pause']);
$router->post('/settings/database-maintenance/finish', [DatabaseMaintenanceController::class, 'finish']);
$router->post('/settings/database-maintenance/rebuild', [DatabaseMaintenanceController::class, 'rebuild']);
$router->get('/settings/imported-data-reset', [ImportedMeliDataResetController::class, 'index']);
$router->get('/settings/imported-data-reset/status.json', [ImportedMeliDataResetController::class, 'status']);
$router->post('/settings/imported-data-reset/analyze', [ImportedMeliDataResetController::class, 'analyze']);
$router->post('/settings/imported-data-reset/authorize', [ImportedMeliDataResetController::class, 'authorize']);
$router->post('/settings/imported-data-reset/pause', [ImportedMeliDataResetController::class, 'pause']);
$router->post('/settings/imported-data-reset/resume', [ImportedMeliDataResetController::class, 'resume']);
$router->post('/settings/imported-data-reset/abandon', [ImportedMeliDataResetController::class, 'abandon']);
$router->post('/settings', [SettingsController::class, 'save']);
$router->get('/settings/cron', [SettingsController::class, 'cron']);
$router->get('/settings/cron/queue-diagnostic/status.json', [SettingsController::class, 'queueV4DiagnosticStatus']);
$router->post('/settings/cron/queue-diagnostic/debug', [SettingsController::class, 'queueV4DiagnosticDebug']);
$router->post('/settings/cron/queue-diagnostic/generate', [SettingsController::class, 'queueV4DiagnosticGenerate']);
$router->get('/settings/cron/queue-diagnostic/download', [SettingsController::class, 'queueV4DiagnosticDownload']);
$router->get('/settings/cron/queue-v4.json', [SettingsController::class, 'queueV4CleanStatus']);
$router->get('/settings/cron/api-risks.json', [SettingsController::class, 'cronApiRisks']);
$router->post('/settings/cron/queue-v4/readiness', [SettingsController::class, 'queueV4CleanReadiness']);
$router->post('/settings/cron/queue-v4/activate', [SettingsController::class, 'queueV4CleanActivate']);
$router->post('/settings/cron/queue-v4/stop', [SettingsController::class, 'queueV4CleanStop']);
$router->get('/settings/cron/section.json', [SettingsController::class, 'cronSection']);
$router->get('/settings/cron/operational-snapshot.json', [SettingsController::class, 'cronOperationalSnapshot']);
$router->get('/settings/cron/overview.json', [SettingsController::class, 'cronOverview']);
$router->get('/settings/cron/tasks.json', [SettingsController::class, 'cronTasks']);
$router->get('/settings/cron/queues.json', [SettingsController::class, 'cronQueues']);
$router->get('/settings/cron/doctor.json', [SettingsController::class, 'cronDoctor']);
$router->get('/settings/cron/run', [SettingsController::class, 'cronRun']);
$router->get('/settings/cron/run.json', [SettingsController::class, 'cronRunJson']);
$router->get('/settings/cron/history.json', [SettingsController::class, 'cronHistoryJson']);
$router->get('/settings/cron/rhythm', [SettingsController::class, 'cronRhythm']);
$router->post('/settings/cron/rhythm', [SettingsController::class, 'saveCronRhythm']);
$router->post('/settings/cron/call-budget', [SettingsController::class, 'saveCronCallBudget']);
$router->get('/settings/cron/rhythm/preview.json', [SettingsController::class, 'cronRhythmPreview']);
$router->get('/settings/cron/next', [SettingsController::class, 'automationNext']);
$router->get('/settings/cron/work', [SettingsController::class, 'automationWork']);
$router->post('/settings/cron/work/remediate', [SettingsController::class, 'automationWorkRemediate']);
$router->get('/settings/cron/review', [SettingsController::class, 'automationAttention']);
$router->get('/settings/cron/queue', [SettingsController::class, 'automationQueue']);
$router->get('/settings/cron/history', [SettingsController::class, 'automationHistory']);
$router->get('/settings/cron/diagnostics', [SettingsController::class, 'automationDiagnostics']);
$router->get('/settings/cron/attention', [SettingsController::class, 'automationAttention']);
$router->post('/settings/cron/attention/remediate', [SettingsController::class, 'automationRemediate']);
$router->get('/settings/api-workload', [SettingsController::class, 'apiWorkload']);
$router->post('/settings/api-workload', [SettingsController::class, 'saveApiWorkload']);
$router->get('/settings/manual-processing', [SettingsController::class, 'manualProcessing']);
$router->get('/settings/manual-processing/new', [SettingsController::class, 'manualProcessing']);
$router->post('/settings/manual-processing/preview', [SettingsController::class, 'manualProcessingPreview']);
$router->get('/settings/manual-processing/excluded', [SettingsController::class, 'manualProcessingExcluded']);
$router->post('/settings/manual-processing/start', [SettingsController::class, 'manualProcessingStart']);
$router->get('/settings/cron/status.json', [SettingsController::class, 'cronStatus']);
$router->get('/settings/cron/integrity.json', [SettingsController::class, 'cronIntegrityStatus']);
$router->post('/settings/cron/test', [SettingsController::class, 'testCron']);
$router->post('/settings/cron/reschedule-overdue', [SettingsController::class, 'rescheduleOverdue']);
$router->post('/settings/cron/notifications/recover-known-errors', [SettingsController::class, 'recoverKnownNotificationErrors']);
$router->post('/settings/cron/notifications/recovery/canary', [SettingsController::class, 'createNotificationRecoveryCanary']);
$router->post('/settings/cron/notifications/recovery/start', [SettingsController::class, 'startNotificationCollationRecovery']);
$router->post('/settings/cron/notifications/recovery/pause', [SettingsController::class, 'pauseNotificationCollationRecovery']);
$router->get('/settings/cron/notifications/recovery/status.json', [SettingsController::class, 'notificationCollationRecoveryStatus']);
$router->get('/settings/api-logs', [SettingsController::class, 'logs']);
$router->get('/settings/api-health', [SettingsController::class, 'apiHealth']);
$router->get('/settings/api-health/section.html', [SettingsController::class, 'apiHealthSection']);
$router->get('/settings/api-health/overview.json', [SettingsController::class, 'apiHealthOverviewJson']);
$router->get('/settings/api-health/operational-snapshot.json', [SettingsController::class, 'apiHealthOperationalSnapshot']);
$router->get('/settings/api-health/protection.json', [SettingsController::class, 'apiHealthProtectionJson']);
$router->get('/settings/api-health/accounts', [SettingsController::class, 'apiHealthAccounts']);
$router->get('/settings/api-health/incidents', [SettingsController::class, 'apiHealthIncidents']);
$router->get('/settings/api-health/incidents.json', [SettingsController::class, 'apiHealthIncidentsJson']);
$router->get('/settings/api-health/incidents/show', [SettingsController::class, 'apiHealthIncidentShow']);
$router->get('/settings/api-health/incidents/show.json', [SettingsController::class, 'apiHealthIncidentShowJson']);
$router->get('/settings/api-health/incidents/status.json', [SettingsController::class, 'apiHealthIncidentsStatus']);
$router->post('/settings/api-health/email-settings', [SettingsController::class, 'saveCriticalApiAlertSettings']);
$router->post('/settings/api-health/email-test', [SettingsController::class, 'sendCriticalApiAlertTestEmail']);
$router->get('/settings/api-health/protection', [SettingsController::class, 'apiHealthProtection']);
$router->get('/settings/api-health/technical', [SettingsController::class, 'apiHealthTechnical']);
$router->post('/settings/api-health/incidents/acknowledge', [SettingsController::class, 'acknowledgeApiIncident']);
$router->post('/settings/api-health/pause', [SettingsController::class, 'pauseApi']);
$router->post('/settings/api-health/resume', [SettingsController::class, 'resumeApi']);
$router->get('/settings/api-health/pause/status.json', [SettingsController::class, 'apiPauseStatus']);
$router->get('/logs/api/incidents', [SettingsController::class, 'apiHealthIncidents']);
$router->get('/logs/api/incidents/show', [SettingsController::class, 'apiHealthIncidentShow']);
$router->get('/settings/api-docs', [SettingsController::class, 'apiDocs']);
$router->get('/settings/api-docs/endpoint', [SettingsController::class, 'apiDocsEndpoint']);
$router->post('/settings/api-docs/regenerate', [SettingsController::class, 'regenerateApiDocs']);
$router->post('/settings/api-health/circuit/close', [SettingsController::class, 'closeApiCircuit']);
$router->post('/settings/api-health/account/pause', [SettingsController::class, 'pauseApiAccount']);
$router->post('/settings/api-health/account/reactivate', [SettingsController::class, 'reactivateApiAccount']);
$router->get('/settings/api-health/export', [SettingsController::class, 'exportApiHealth']);
$router->get('/settings/diagnostics', [SettingsController::class, 'diagnostics']);
$router->get('/settings/diagnostics/section.json', [SettingsController::class, 'diagnosticsSection']);
$router->get('/settings/diagnostics/error', [SettingsController::class, 'supportDiagnostic']);
$router->get('/settings/diagnostics/migrations', [SettingsController::class, 'migrationDiagnostics']);
$router->get('/settings/diagnostics/migrations/export', [SettingsController::class, 'migrationDiagnosticsExport']);
$router->get('/settings/diagnostics/migrations/status.json', [SettingsController::class, 'migrationDiagnosticsStatus']);
$router->get('/settings/update', [UpdateController::class, 'index']);
$router->post('/settings/update/migrate', [UpdateController::class, 'migrate']);
$router->post('/settings/update/clear-cache', [UpdateController::class, 'clearCache']);
$router->post('/settings/update/key', [UpdateController::class, 'generateKey']);
$router->post('/settings/update/package', [UpdateController::class, 'uploadPackage']);
$router->post('/settings/update/run', [UpdateController::class, 'createRun']);
$router->post('/settings/update/run/process', [UpdateController::class, 'processRun']);
$router->post('/settings/update/run/pause', [UpdateController::class, 'pauseRun']);
$router->post('/settings/update/run/resume', [UpdateController::class, 'resumeRun']);
$router->post('/settings/update/run/rollback', [UpdateController::class, 'rollbackRun']);
$router->get('/settings/update/status.json', [UpdateController::class, 'status']);
$router->post('/settings/update/settings', [UpdateController::class, 'saveEngineSettings']);
$router->post('/settings/update/trusted-key', [UpdateController::class, 'addTrustedKey']);
$router->post('/settings/update/trusted-key/revoke', [UpdateController::class, 'revokeTrustedKey']);
$router->get('/settings/{section}', [SettingsSectionController::class, 'show']);
$router->post('/settings/{section}', [SettingsSectionController::class, 'save']);
$router->get('/update-run', [UpdateController::class, 'protectedRun']);
$router->post('/update-run', [UpdateController::class, 'protectedRun']);
$router->get('/users', [UserController::class, 'index']);
$router->post('/users', [UserController::class, 'store']);
$router->post('/users/temporary', [UserController::class, 'storeTemporary']);
$router->post('/users/temporary/revoke', [UserController::class, 'revokeTemporary']);
$router->post('/users/temporary/extend', [UserController::class, 'extendTemporary']);
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if (preg_match('#/(?:meli-ads|meli-growth|meli-insights|meli-logistics|meli-postsale)(?:/|$)#', $requestPath) === 1) {
    (new ModuleKernel(null, $container))->registerRoutes($router);
}
$router->get('/catalogo/{slug}', [PublicCatalogController::class, 'show']);
$router->post('/catalogo/{slug}/password', [PublicCatalogController::class, 'password']);
$router->get('/catalogo/{slug}/categoria/{categorySlug}', [PublicCatalogController::class, 'category']);
$router->get('/catalogo/{slug}/producto/{itemId}', [PublicCatalogController::class, 'product']);
$router->get('/catalogo/{slug}/print', [PublicCatalogController::class, 'printView']);

$snapshotMarker = AppPaths::storage('cache/database-snapshot-active.json');
// El snapshot es una protección persistente. Una petición web no puede
// inferir que quedó abandonado solo por su antigüedad: Hostinger puede
// interrumpir el worker y la siguiente ejecución debe reanudarlo desde el
// checkpoint aprobado. Únicamente el job propietario, después de verificar o
// cancelar de forma cercada, puede retirar este marcador.
$restoreMarker = AppPaths::storage('cache/restore-maintenance-request.json');
$restoreContinuation = str_ends_with($requestPath, '/settings/backups/restore/start');
$mutationFreeze = (new DatabaseMutationFreezeService())->status();
$sessionContinuation = str_ends_with($requestPath, '/login')
    || str_ends_with($requestPath, '/login.php')
    || str_ends_with($requestPath, '/logout')
    || str_ends_with($requestPath, '/logout.php');
$maintenanceSessionId = max(0, (int) ($_POST['session_id'] ?? 0));
$backupControlId = max(0, (int) ($_POST['backup_id'] ?? 0));
$maintenanceAnalyzeContinuation = str_ends_with($requestPath, '/settings/database-maintenance/analyze');
$maintenanceProtectionContinuation = $maintenanceSessionId > 0
    && str_ends_with($requestPath, '/settings/database-maintenance/protect');
$pathEndsWith = static fn (string $suffix): bool => str_ends_with($requestPath, $suffix);
$backupControlContinuation = (
    $pathEndsWith('/settings/backups/delete')
    || $pathEndsWith('/settings/backups/cancel')
    || $pathEndsWith('/settings/backups/interactive/step')
    || $pathEndsWith('/settings/backups/interactive/cancel')
    || $pathEndsWith('/settings/backups/recover')
    || $pathEndsWith('/settings/backups/cleanup')
)
    && $backupControlId > 0
    && (new BackupCenterService())->canEnterControlMutation($backupControlId);
$maintenanceContinuation = !empty($mutationFreeze['active'])
    && (string) ($mutationFreeze['purpose'] ?? '') === 'database_sanitation'
    && (int) (($mutationFreeze['context']['session_id'] ?? 0)) > 0
    && (int) (($mutationFreeze['context']['session_id'] ?? 0)) === $maintenanceSessionId
    && (
        $pathEndsWith('/settings/database-maintenance/protect')
        || $pathEndsWith('/settings/database-maintenance/start')
        || $pathEndsWith('/settings/database-maintenance/step')
        || $pathEndsWith('/settings/database-maintenance/pause')
        || $pathEndsWith('/settings/database-maintenance/finish')
        || $pathEndsWith('/settings/database-maintenance/rebuild')
    );
$resetControlContinuation = !empty($mutationFreeze['active'])
    && (string) ($mutationFreeze['purpose'] ?? '') === 'imported_data_reset'
    && (int) (($mutationFreeze['context']['request_id'] ?? 0)) > 0
    && (int) (($mutationFreeze['context']['request_id'] ?? 0))
        === max(0, (int) ($_POST['request_id'] ?? 0))
    && (
        str_ends_with($requestPath, '/settings/imported-data-reset/pause')
        || str_ends_with($requestPath, '/settings/imported-data-reset/resume')
        || str_ends_with($requestPath, '/settings/imported-data-reset/abandon')
    );
if (
    strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET'
    && (
        (
            is_file($snapshotMarker)
            && !$sessionContinuation
            && !$backupControlContinuation
            && !$maintenanceAnalyzeContinuation
            && !$maintenanceProtectionContinuation
            && !$maintenanceContinuation
        )
        || (is_file($restoreMarker) && !$restoreContinuation && !$sessionContinuation)
        || (
            !empty($mutationFreeze['active'])
            && !$sessionContinuation
            && !$maintenanceProtectionContinuation
            && !$maintenanceContinuation
            && !$resetControlContinuation
        )
    )
) {
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    http_response_code(423);
    header('Cache-Control: private, no-store');
    if (str_contains($accept, 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'code' => 'database_maintenance_active',
            'message' => 'Existe una tarea de mantenimiento local en curso. Continúe la copia, cancele la solicitud vacía, limpie restos o vuelva a Saneamiento para usar respaldo externo o continuar sin respaldo interno.',
            'action' => 'open_backup_recovery',
            'suggested_url' => rtrim((string) parse_url((string) Env::get('APP_URL', ''), PHP_URL_PATH), '/')
                . '/settings/backups',
            'actions' => [
                [
                    'label' => 'Abrir copias y recuperación',
                    'url' => rtrim((string) parse_url((string) Env::get('APP_URL', ''), PHP_URL_PATH), '/')
                        . '/settings/backups',
                ],
                [
                    'label' => 'Volver a saneamiento',
                    'url' => rtrim((string) parse_url((string) Env::get('APP_URL', ''), PHP_URL_PATH), '/')
                        . '/settings/database-maintenance'
                        . ($maintenanceSessionId > 0 ? '?id=' . $maintenanceSessionId . '#resultado' : ''),
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $basePath = rtrim((string) parse_url((string) Env::get('APP_URL', ''), PHP_URL_PATH), '/');
    View::render('errors/maintenance', [
        'title' => 'Protección de datos en curso',
        'message' => 'El ERP está temporalmente en modo lectura. Continúe la copia pendiente, cancele la solicitud vacía, limpie restos o vuelva a Saneamiento para usar respaldo externo o continuar sin respaldo interno.',
        'actions' => [
            [
                'label' => 'Abrir copias y recuperación',
                'url' => $basePath . '/settings/backups',
                'primary' => true,
            ],
            [
                'label' => 'Volver a saneamiento',
                'url' => $basePath . '/settings/database-maintenance'
                    . ($maintenanceSessionId > 0 ? '?id=' . $maintenanceSessionId . '#resultado' : ''),
            ],
            [
                'label' => 'Abrir actualizador seguro',
                'url' => $basePath . '/actualizar.php',
            ],
            [
                'label' => 'Abrir freno de mano',
                'url' => $basePath . '/stop.php',
            ],
        ],
    ], false);
    exit;
}

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
