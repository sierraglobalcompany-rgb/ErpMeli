<?php
declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

use App\Services\CapacityPolicyService;
use App\Services\ManualCampaignPreviewService;
use App\Services\ManualPhysicalCallBudget;
use App\Services\ManualProcessingService;
use App\Services\ManualSingleStepService;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL:$label\n");
        exit(1);
    }
    echo "PASS:$label\n";
};

$assert(defined(ManualCampaignPreviewService::class . '::PRESENTATION_LIMIT')
    && ManualCampaignPreviewService::PRESENTATION_LIMIT === 60, 'fixed_presentation_limit_60');

$method = new ReflectionMethod(ManualSingleStepService::class, 'executePreview');
$assert($method->getNumberOfParameters() === 3, 'start_accepts_token_user_and_http_budget_only');

$assert(ManualCampaignPreviewService::assertScope('available_queue') === 'available_queue', 'available_scope_is_current');
$unsupportedRejected = false;
try {
    ManualCampaignPreviewService::assertScope('modules');
} catch (RuntimeException) {
    $unsupportedRejected = true;
}
$assert($unsupportedRejected, 'unsupported_modules_scope_hidden_and_rejected');
$availableRequirements = ManualCampaignPreviewService::schemaRequirements('available_queue');
$assert(isset($availableRequirements['manual_campaign_previews'], $availableRequirements['queue_v4_clean_jobs'])
    && !isset($availableRequirements['manual_campaigns']), 'available_readiness_uses_current_tables_only');
$exactRequirements = ManualCampaignPreviewService::schemaRequirements('finance');
$assert(isset($exactRequirements['manual_campaign_previews'], $exactRequirements['queue_core_jobs'], $exactRequirements['sale_financial_reconciliation_jobs'])
    && !isset($exactRequirements['manual_campaigns']), 'exact_readiness_uses_core_and_source_tables');

$invalidScopeRejected = false;
try {
    (new ManualProcessingService())->assertScope('made_up_scope');
} catch (RuntimeException) {
    $invalidScopeRejected = true;
}
$assert($invalidScopeRejected, 'unknown_scope_fails_closed');

$policy = ['current' => 55, 'ceiling' => 55, 'revision' => str_repeat('a', 64)];
$legacyRejected = false;
try {
    ManualPhysicalCallBudget::resolve([
        'preview_format' => 3,
        'physical_api_call_budget' => 55,
        'capacity_revision' => $policy['revision'],
    ], 55, $policy);
} catch (RuntimeException) {
    $legacyRejected = true;
}
$assert($legacyRejected, 'legacy_preview_requires_recalculation');
$assert(ManualPhysicalCallBudget::resolve([
    'preview_format' => 4,
    'physical_api_call_budget' => 55,
    'capacity_revision' => $policy['revision'],
], 3, $policy) === 3, 'requested_http_budget_is_only_start_limit');

echo "STATUS=PASS CALLS_MANUAL_CONTRACT\n";
