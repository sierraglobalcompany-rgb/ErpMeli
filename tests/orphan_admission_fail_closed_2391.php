<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\HttpException;
use App\Core\Modules\ModuleEventDispatcher;
use App\Core\Modules\ModuleJobRunner;
use App\Services\CronV3ProducerService;
use App\Services\ManualCampaignAdapterRegistry;
use App\Services\ManualProcessingService;
use App\Services\NotificationBackfillService;
use App\Services\QuestionSyncService;
use App\Services\RecurringSyncService;
use App\Services\SalesFiscalPreparationService;
use App\Services\SettingsSectionService;
use App\Services\WorkEnvelope;

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$throwsBeforeInfrastructure = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
        $assert(false, $message . '_did_not_throw');
    } catch (HttpException|RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'retirad'), $message . '_unsafe_message');
    }
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

// notification_backfill: direct creation/start are closed before Auth/DB;
// existing exact run processing and history remain available.
$backfill = new NotificationBackfillService();
$throwsBeforeInfrastructure(static fn () => $backfill->createAnalysis(7, 3), 'notification_backfill_create');
$throwsBeforeInfrastructure(static fn () => $backfill->start(11, 3, 7), 'notification_backfill_start');
$assert(method_exists($backfill, 'processRun'), 'notification_backfill_existing_run_path_missing');
$maintenanceSource = $source('app/Services/CronV3MaintenanceProducer.php');
$assert(!str_contains($maintenanceSource, "'type' => 'notification_backfill'"), 'notification_backfill_legacy_producer_present');
$notificationController = $source('app/Controllers/NotificationController.php');
$backfillControllerSlice = substr($notificationController, (int) strpos($notificationController, 'public function analyzeBackfill'), 1800);
$assert(!str_contains($backfillControllerSlice, 'createAnalysis('), 'notification_backfill_web_create_present');
$assert(!str_contains($backfillControllerSlice, '->start('), 'notification_backfill_web_start_present');
$notificationView = $source('app/Views/notifications/index.php');
$assert(!str_contains($notificationView, '/notifications/backfill/analyze'), 'notification_backfill_analyze_ui_present');
$assert(!str_contains($notificationView, '/notifications/backfill/start'), 'notification_backfill_start_ui_present');

// recurring_sync: enabling is rejected before DB, while a disable-only save
// remains possible; the legacy producer itself cannot enqueue ranges.
$recurring = new RecurringSyncService();
$throwsBeforeInfrastructure(
    static fn () => $recurring->save([7 => ['enabled' => '1']], [7]),
    'recurring_sync_enable'
);
$recurringResult = $recurring->processDue();
$assert(($recurringResult['reason'] ?? '') === 'legacy_automation_retired', 'recurring_sync_not_retired');
$assert((int) ($recurringResult['enqueued'] ?? -1) === 0, 'recurring_sync_enqueued');
$assert(!str_contains($maintenanceSource, "'type' => 'recurring_schedule'"), 'recurring_schedule_legacy_producer_present');
$recurringView = $source('app/Views/sync/recurring.php');
$assert(str_contains($recurringView, 'Programación recurrente retirada'), 'recurring_sync_ui_not_explicit');
$assert(!str_contains($recurringView, 'name="sync_daily_enabled"'), 'recurring_sync_global_enable_ui_present');
$assert(!str_contains($recurringView, '][enabled]'), 'recurring_sync_account_enable_ui_present');
$assert(!str_contains($recurringView, '<select class="input compact-input" name="rules['), 'recurring_sync_history_still_editable');

// questions: generic scans are closed locally. Exact question-by-id remains
// available for the already certified notification fallback path.
$questions = new QuestionSyncService();
$assert(($questions->syncAllActive()['skipped'] ?? false) === true, 'questions_all_not_skipped');
$assert(($questions->syncNextActive()['skipped'] ?? false) === true, 'questions_next_not_skipped');
$assert($questions->syncAccount(7) === 0, 'questions_generic_account_not_blocked');
$assert(method_exists($questions, 'syncQuestionById'), 'questions_exact_path_missing');
$questionController = $source('app/Controllers/QuestionController.php');
$assert(!str_contains($questionController, '/settings/manual-processing'), 'questions_fake_manual_fallback_present');
$assert(!str_contains($source('app/Views/sales/questions/index.php'), '/questions/sync'), 'questions_ui_admission_present');
$settingsController = $source('app/Controllers/SettingsController.php');
$assert(str_contains($settingsController, "set('questions.sync_enabled', '0'"), 'questions_setting_can_enable');
$settingsSave = substr($settingsController, (int) strpos($settingsController, 'public function save'), 800);
$assert(
    strpos($settingsSave, "isset(\$_POST['questions_sync_enabled'])") < strpos($settingsSave, '$settings = new AppSettingsService()'),
    'questions_settings_guard_after_dml'
);
$settingsSections = new SettingsSectionService();
$throwsBeforeInfrastructure(
    static fn () => $settingsSections->save('communications', ['questions.sync_enabled' => '1']),
    'questions_section_sync_enable'
);
$throwsBeforeInfrastructure(
    static fn () => $settingsSections->save('communications', ['questions.endpoint_confirmed' => '1']),
    'questions_section_endpoint_enable'
);
$settingsSectionSource = $source('app/Services/SettingsSectionService.php');
$settingsSectionSave = substr($settingsSectionSource, (int) strpos($settingsSectionSource, 'public function save'), 700);
$assert(
    strpos($settingsSectionSave, 'assertRetiredQuestionAdmission($sectionKey, $submitted)')
        < strpos($settingsSectionSave, '$section = $this->requireSection($sectionKey)'),
    'questions_section_guard_after_definition_or_db'
);
$settingsSectionView = $source('app/Views/settings/section.php');
$assert(str_contains($settingsSectionView, '$retiredQuestionSetting ? \'disabled\''), 'questions_section_controls_not_disabled');

// sales_fiscal: creation is closed before schema checks, locks or inserts.
$fiscal = new SalesFiscalPreparationService();
$throwsBeforeInfrastructure(static fn () => $fiscal->create(7, 2026, 8, 3, 1), 'sales_fiscal_create');
$salesController = $source('app/Controllers/SalesControlController.php');
$assert(!str_contains($salesController, 'SalesFiscalPreparationService())->create'), 'sales_fiscal_controller_insert_present');
$assert(!str_contains($source('app/Views/sales_control/fiscal.php'), '/sales-control/prepare-fiscal'), 'sales_fiscal_ui_admission_present');

// module_jobs: all new admissions are closed before registry/DB. Existing
// jobs and the exact processor implementation remain untouched for later
// independent repair; B1 does not process or migrate them.
$modules = new ModuleJobRunner();
$throwsBeforeInfrastructure(
    static fn () => $modules->enqueue('meli-insights', 'snapshot_sync', 7, ['requested_by' => 1]),
    'module_jobs_enqueue'
);
$throwsBeforeInfrastructure(
    static fn () => $modules->enqueueScoped('meli-insights', 'snapshot_sync', 3, 7, ['requested_by' => 1]),
    'module_jobs_enqueue_scoped'
);
$assert($modules->reconcilePendingEvents(100) === 0, 'module_jobs_reconcile_created');
$assert($modules->resume(11) === false, 'module_jobs_resume_reactivated');
$assert($modules->retry(11) === false, 'module_jobs_retry_reactivated');
$assert($modules->resumeScoped(11, 3, 7) === false, 'module_jobs_resume_scoped_reactivated');
$assert($modules->retryScoped(11, 3, 7) === false, 'module_jobs_retry_scoped_reactivated');
(new ModuleEventDispatcher())->publish(9, 'orders_v2', 7, 'order', '123');
$assert(method_exists($modules, 'processExact'), 'module_jobs_existing_processor_missing');
$assert(!str_contains($source('app/Modules/Shared/Views/dashboard.php'), '/refresh'), 'module_jobs_shared_ui_admission_present');
$assert(!str_contains($source('app/Modules/MeliInsights/Views/index.php'), '/meli-insights/refresh'), 'module_jobs_insights_ui_admission_present');
$assert(!str_contains($source('app/Modules/MeliGrowth/Views/index.php'), '/meli-growth/refresh'), 'module_jobs_growth_ui_admission_present');
$sharedModuleController = $source('app/Modules/Shared/Controllers/ModuleDashboardController.php');
$insightsModuleController = $source('app/Modules/MeliInsights/Controllers/DashboardController.php');
$growthModuleController = $source('app/Modules/MeliGrowth/Controllers/DashboardController.php');
$sharedRefresh = substr($sharedModuleController, (int) strpos($sharedModuleController, 'public function refresh'), 700);
$insightsRefresh = substr($insightsModuleController, (int) strpos($insightsModuleController, 'public function refresh'), 900);
$growthRefresh = substr($growthModuleController, (int) strpos($growthModuleController, 'public function refresh'), 900);
$assert(strpos($sharedRefresh, 'HttpException(410') < strpos($sharedRefresh, 'BusinessScopeContext'), 'module_jobs_shared_guard_after_db');
$assert(strpos($insightsRefresh, 'HttpException(410') < strpos($insightsRefresh, 'BusinessScopeContext'), 'module_jobs_insights_guard_after_db');
$assert(strpos($growthRefresh, 'HttpException(410') < strpos($growthRefresh, 'BusinessScopeContext'), 'module_jobs_growth_guard_after_db');

// claims_search_page: even adversarial injected ownership/enqueue callbacks
// are never reached, and the controller no longer advertises V3/manual work.
$ownershipCalls = 0;
$enqueueCalls = 0;
$claimsProducer = new CronV3ProducerService(
    static function (string $workType, string $lane) use (&$ownershipCalls): bool {
        $ownershipCalls++;
        return true;
    },
    static function (WorkEnvelope $work) use (&$enqueueCalls): array {
        $enqueueCalls++;
        return ['id' => 99, 'created' => true, 'status' => 'ready'];
    }
);
$assert($claimsProducer->claimsSearchPage(3, 7, 'claims-manual:test') === false, 'claims_search_page_not_blocked');
$assert($ownershipCalls === 0, 'claims_search_page_read_legacy_ownership');
$assert($enqueueCalls === 0, 'claims_search_page_enqueued');
$claimController = $source('app/Controllers/ClaimController.php');
$assert(!str_contains($claimController, 'CronV3ProducerService'), 'claims_controller_v3_producer_present');
$assert(!str_contains($claimController, '/settings/manual-processing'), 'claims_fake_manual_fallback_present');
$assert(!str_contains($source('app/Views/sales/claims/index.php'), '/claims/sync'), 'claims_ui_admission_present');

// The manual center must not create a session for any of the six unsupported
// capabilities. Certified exact paths unrelated to B1 stay registered.
$scopes = (new ManualProcessingService())->scopes();
$retired = ['notification_backfill', 'recurring_sync', 'questions', 'sales_fiscal', 'module_jobs', 'claims_search_page'];
foreach ($scopes as $scope => $queueKeys) {
    foreach ($retired as $queueKey) {
        $assert(!in_array($queueKey, $queueKeys, true), $scope . '_still_admits_' . $queueKey);
    }
}
$manualRegistry = new ManualCampaignAdapterRegistry();
$assert($manualRegistry->forQueue('notification_fallback')?->supportsExact() === true, 'notification_fallback_exact_regressed');
$assert($manualRegistry->forQueue('orders_sync')?->supportsExact() === true, 'orders_sync_exact_regressed');
$assert($manualRegistry->forQueue('module_jobs')?->supportsExact() === false, 'module_jobs_incorrectly_marked_exact');
$notificationWorker = $source('app/Services/NotificationWorkItemService.php');
$assert(str_contains($notificationWorker, 'syncQuestionById('), 'notification_question_exact_path_regressed');
$assert(str_contains($notificationWorker, 'syncClaimById('), 'notification_claim_exact_path_regressed');

echo 'ORPHAN_ADMISSION_FAIL_CLOSED_2391=PASS'
    . ' checks=' . $checks
    . ' capabilities=6'
    . ' orphan_insertions=0'
    . ' legacy_business_dml=0'
    . ' meli_http=0'
    . ' valid_manual_paths=preserved'
    . PHP_EOL;
