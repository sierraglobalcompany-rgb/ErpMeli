<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
        exit(1);
    }
};

$controller = $read('app/Controllers/SettingsController.php');
$preview = $read('app/Services/ManualCampaignPreviewService.php');
$drain = $read('app/Services/ManualDrainSessionService.php');
$single = $read('app/Services/ManualSingleStepService.php');
$view = $read('app/Views/settings/manual_processing.php');

$manualDrainStart = strpos($controller, "private function manualDrainJsonMutation");
$manualDrainBody = $manualDrainStart === false ? '' : substr($controller, $manualDrainStart, 4200);

$assert(str_contains($preview, "'execution_mode'"), 'preview_must_persist_execution_mode');
$assert(str_contains($preview, "'max_items'"), 'preview_must_persist_max_items');
$assert(str_contains($preview, "'remote_calls_per_burst'"), 'preview_must_persist_burst');
$assert(str_contains($preview, "'429_action'"), 'preview_must_persist_429_action');
$assert(str_contains($preview, "'bounded_5xx_preferences'"), 'preview_must_persist_5xx_action');

$assert(str_contains($manualDrainBody, "if (\$action === 'start') {"), 'controller_must_bind_selection_only_when_starting');
$assert(str_contains($manualDrainBody, "\$profile['preview_token']"), 'controller_start_must_send_preview_token');
$assert(str_contains($manualDrainBody, "\$profile['selected_scope']"), 'controller_start_must_send_selected_scope');
$assert(str_contains($manualDrainBody, "\$profile['selected_account_id']"), 'controller_start_must_send_selected_account');
$assert(!str_contains($manualDrainBody, "\$profile = [\n                'preview_token'"), 'controller_profile_update_must_not_default_blank_preview');

$assert(str_contains($drain, 'PREVIEW_TO_EXECUTION_BINDING_REQUIRED'), 'drain_must_refuse_to_start_without_preview');
$assert(str_contains($drain, 'boundPreview('), 'drain_must_validate_preview_before_start');
$assert(str_contains($drain, 'SELECTION_BINDING_MISMATCH'), 'drain_must_detect_selection_binding_mismatch');
$assert(str_contains($drain, 'ManualSingleStepService())->executeSelected('), 'drain_must_execute_selected_exact_step');
$assert(!str_contains($drain, "->run('manual', 1, 25)"), 'drain_must_not_call_global_v4_worker');
$assert(str_contains($drain, 'array_merge($this->profileFromRow($row), $incoming)'), 'profile_update_must_preserve_bound_selection');
$assert(str_contains($drain, 'array_merge($profile, [') && str_contains($drain, 'degradedProfile'), 'auto_degrade_must_preserve_bound_selection');
$assert(str_contains($drain, 'No se sustituyó por trabajo global'), 'stale_preview_must_not_substitute_global_work');

$assert(str_contains($single, '?string $expectedScope = null'), 'single_step_must_accept_expected_scope');
$assert(str_contains($single, '?int $expectedAccountId = null'), 'single_step_must_accept_expected_account');
$assert(str_contains($single, '$preview[\'rows\'][$positionNo]'), 'single_step_must_execute_selected_position');
$assert(str_contains($single, 'SELECTION_BINDING_MISMATCH'), 'single_step_must_revalidate_scope_account');
$assert(str_contains($single, 'NO_ELIGIBLE_SELECTED_WORK'), 'single_step_must_fail_closed_when_selection_stale');

$assert(str_contains($view, 'name="execution_mode"'), 'ui_must_expose_execution_mode');
$assert(str_contains($view, 'ONE_STEP'), 'ui_must_offer_one_step');
$assert(str_contains($view, 'CONTINUE_AUTOMATICALLY'), 'ui_must_offer_explicit_auto');
$assert(str_contains($view, 'name="max_items"'), 'ui_must_expose_item_limit');
$assert(str_contains($view, 'name="429_action"'), 'ui_must_expose_429_action');
$assert(str_contains($view, 'name="bounded_5xx_preferences"'), 'ui_must_expose_5xx_action');
$assert(str_contains($view, 'loopEnabled = isAutoMode(payload);'), 'ui_start_must_not_force_hidden_auto_loop');
$assert(!str_contains($view, 'loopEnabled = true;'), 'ui_must_not_enable_hidden_auto_loop');

echo 'MANUAL_SELECTION_EXECUTION_BINDING_2402=PASS assertions=' . $assertions . ' real_meli_http=0 production_touched=no' . PHP_EOL;
