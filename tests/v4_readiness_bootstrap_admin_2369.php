<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/V4ReadinessBootstrapService.php');
$flags = (string) file_get_contents($root . '/app/QueueCore/QueueCoreFeatureFlagService.php');
$receipts = (string) file_get_contents($root . '/app/QueueCore/QueueCoreReadinessReceiptService.php');
$canary = (string) file_get_contents($root . '/app/QueueCore/QueueCoreCanaryService.php');
$config = (string) file_get_contents($root . '/app/Services/CronV3SetupAssistantService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$view = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$js = (string) file_get_contents($root . '/public/assets/app.js');
$routes = (string) file_get_contents($root . '/public/index.php');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $v4ViewStart = strpos($view, 'data-v4-readiness-action');
    $v4ViewEnd = strpos($view, 'data-cron-v3-commands', $v4ViewStart ?: 0);
    $v4View = $v4ViewStart !== false && $v4ViewEnd !== false
        ? substr($view, $v4ViewStart, $v4ViewEnd - $v4ViewStart)
        : '';
    $retirementViewStart = strpos($view, 'data-cron-v3-retirement-action');
    $retirementViewEnd = strpos($view, 'data-v4-readiness-action', $retirementViewStart ?: 0);
    $retirementView = $retirementViewStart !== false && $retirementViewEnd !== false
        ? substr($view, $retirementViewStart, $retirementViewEnd - $retirementViewStart)
        : '';
    $assert(substr_count($routes, '/settings/cron/v3-setup/prepare-safe-config') === 1, 'new_public_post_route_added');
    $assert(str_contains($controller, "'v4_readiness_bootstrap'"), 'admin_operation_dispatch_missing');
    $assert(str_contains($controller, 'AdministrativeReauthenticationService'), 'password_reauthentication_missing');
    $assert(str_contains($controller, '$this->requireAdminPermanent();'), 'permanent_admin_missing');
    $assert(str_contains($controller, '$this->assertSameOrigin();'), 'same_origin_missing');
    $assert(str_contains($controller, "Csrf::validate(\$_POST['_token'] ?? null);"), 'csrf_missing');
    $assert(str_contains($view, 'Preparar y certificar V4'), 'admin_label_missing');
    $assert(str_contains($view, 'name="admin_password"'), 'password_field_missing');
    $assert($v4View !== '', 'v4_view_not_found');
    $assert(!str_contains($v4View, 'name="confirmation_phrase"'), 'v4_confirmation_phrase_remains');
    $assert(!str_contains($v4View, 'name="scheduler_absent_confirmed"'), 'v4_scheduler_checkbox_remains');
    $assert(str_contains($v4View, 'name="admin_password"'), 'v4_password_missing');
    $assert(str_contains($v4View, 'autoridad técnica registrada'), 'scheduler_authority_explanation_missing');
    $assert($retirementView !== '', 'retirement_view_not_found');
    $assert(str_contains($retirementView, 'name="confirmation_phrase"'), 'retirement_confirmation_phrase_removed');
    $assert(str_contains($retirementView, 'RETIRAR_AUTORIDAD_V3_PARA_PREPARAR_V4'), 'retirement_phrase_changed');
    $assert(!str_contains($js, "Boolean(scheduler?.checked)"), 'v4_scheduler_ui_gate_remains');
    $assert(!str_contains($js, 'v4Form.dataset.confirmationPhrase'), 'v4_phrase_dataset_remains');
    $assert(str_contains($js, "form.dataset.preflightOk === '1'\n        && Boolean(password?.value)"), 'v4_password_only_button_gate_missing');
    $assert(str_contains($js, "credentials: 'same-origin'"), 'same_origin_fetch_missing');
    $assert(str_contains($controller, 'V4ReadinessBootstrapService())->advance((int) Auth::id())'), 'password_only_advance_dispatch_missing');

    $assert(str_contains($service, "public const REQUIRED_VERSION = '2.36.9'"), 'version_gate_missing');
    $assert(str_contains($service, "public const LAST_MIGRATION = '293_queue_core_runtime_profile_defaults_b2_1.sql'"), 'schema_gate_missing');
    $assert(str_contains($service, 'COUNT(*) AS total'), 'schema_count_gate_missing');
    $assert(str_contains($service, 'AS max_version'), 'schema_max_gate_missing');
    $assert(str_contains($service, '$schemaCount !== 293 || $schemaMax !== 293 || $migration293Count !== 1'), 'schema_exact_authority_missing');
    $assert(str_contains($service, "SELECT GET_LOCK(?,0)"), 'advisory_lock_missing');
    $assert(str_contains($service, "'preparing'"), 'readiness_transition_missing');
    $assert(str_contains($service, "(int) (\$transition['generation'] ?? -1) !== 1"), 'generation_0_to_1_gate_missing');
    $assert(str_contains($service, 'currentContextHash($generation)'), 'context_revalidation_missing');
    $assert(str_contains($service, 'QueueCorePreflightService'), 'preflight_service_missing');
    $assert(str_contains($service, 'QueueCoreCanaryService'), 'real_canary_missing');
    $assert(str_contains($service, 'QueueCoreConvergenceService'), 'convergence_missing');
    $assert(!str_contains($service, 'certifyBackup'), 'operational_backup_gate_remains');
    $assert(str_contains($service, 'certifyManifest'), 'manifest_evidence_missing');
    $assert(str_contains($service, 'certifyCapacity'), 'capacity_evidence_missing');
    $assert(str_contains($service, 'canActivateV4'), 'real_activation_contract_missing');
    $assert(preg_match(
        '/\$certificationGate\s*=\s*\(new QueueCoreReadinessReceiptService\(\$pdo\)\)\s*' .
        '->canActivateV4\(\(int\) \$engine\[\x27generation\x27\]\);/',
        $service,
    ) === 1, 'certified_snapshot_activation_recheck_missing');
    $assert(str_contains($service, 'compareAndSwapReadinessFlags'), 'feature_cas_missing');
    $assert(str_contains($service, 'private static function flagsMatch'), 'semantic_flag_comparison_missing');
    $assert(substr_count($service, 'self::flagsMatch(') === 6, 'semantic_flag_comparison_call_count_invalid');
    $assert(!str_contains($service, '$flags === self::FLAGS_READY'), 'order_sensitive_ready_comparison_present');
    $assert(!str_contains($service, '$flags === self::FLAGS_DISABLED'), 'order_sensitive_disabled_comparison_present');
    $assert(str_contains($service, "if (\$state === 'recovery_required')"), 'partial_recovery_dispatch_missing');
    $assert(str_contains($service, "'state' => 'recovered_fail_closed'"), 'partial_recovery_result_missing');
    $assert(str_contains($service, "'partial_arm_recovery_2369'"), 'partial_recovery_reason_missing');
    $assert(str_contains($service, 'private static function isRecoverablePartialArm'), 'partial_recovery_classifier_missing');
    $assert(str_contains($service, "'feature_generations' => \$featureGenerations"), 'structured_feature_generation_authority_missing');
    $assert(str_contains($service, "'queue_core_preflight_ok' => !empty(\$preflight['ok'])"), 'structured_preflight_authority_missing');
    $assert(!str_contains($service, '$allowed = ['), 'fragile_issue_allowlist_remains');
    $assert(str_contains($service, 'partial_arm_requires_fail_closed_recovery'), 'partial_recovery_snapshot_missing');
    $armStart = strpos($service, 'private function armStableAuthorities');
    $armEnd = strpos($service, 'private function enterReadiness', $armStart ?: 0);
    $armBody = $armStart !== false && $armEnd !== false ? substr($service, $armStart, $armEnd - $armStart) : '';
    $assert($armBody !== '' && !str_contains($armBody, 'catch (Throwable'), 'partial_local_rollback_remains');
    $assert(!str_contains($armBody, 'SCHEDULER_AUTHORITY_KEY'), 'scheduler_absence_fabricated_during_arm');
    $assert(str_contains($service, "'scheduler_absence_authority_missing'"), 'scheduler_absence_precondition_missing');
    $assert(str_contains($service, 'private static function schedulerAbsenceRecorded'), 'scheduler_structured_authority_missing');
    $assert(str_contains($service, "'permanent_admin_explicit_confirmation'"), 'explicit_scheduler_authority_missing');
    $assert(str_contains($service, "'rollback_preserved_absence'"), 'rollback_scheduler_authority_missing');
    $assert(str_contains($service, 'startApiWithoutCanary'), 'api_enable_missing');
    $assert(str_contains($service, 'restoreV4FailClosedConfig'), 'config_rollback_missing');
    $assert(str_contains($service, 'stopApi'), 'api_rollback_missing');
    $rollbackStart = strpos($service, 'private function rollbackAuthorities');
    $rollbackBody = $rollbackStart === false ? '' : substr($service, $rollbackStart);
    $assert(
        strpos($rollbackBody, '$attempt(\'api\'') < strpos($rollbackBody, '$attempt(\'engine\''),
        'api_fail_closed_not_prioritized'
    );
    $assert(
        strpos($rollbackBody, '$attempt(\'config\'') < strpos($rollbackBody, '$attempt(\'engine\''),
        'config_fail_closed_not_prioritized'
    );
    $assert(str_contains($rollbackBody, 'v4_bootstrap_rollback_incomplete:'), 'rollback_error_aggregation_missing');
    $assert(str_contains($service, "compareAndSwapReadiness(\n                'idle'"), 'engine_rollback_cas_missing');
    $assert(str_contains($service, "'scheduler_created' => false"), 'scheduler_absence_receipt_missing');
    $assert(str_contains($service, "'engine_activated' => false"), 'engine_disabled_receipt_missing');

    foreach (['fresh_producer', 'webhook_producer', 'pack_shipment_followups'] as $enabled) {
        $assert(str_contains($service, "'$enabled' => true"), 'required_feature_missing:' . $enabled);
    }
    foreach (['remote_financial', 'historical_importer'] as $disabled) {
        $assert(str_contains($service, "'$disabled' => false"), 'disabled_feature_missing:' . $disabled);
    }
    $assert(str_contains($config, "'CRON_V4_ENABLED' => 'true'"), 'cron_v4_readiness_config_missing');
    $assert(str_contains($config, "'CRON_V3_ENABLED' => 'false'"), 'v3_false_missing');
    $assert(str_contains($config, "'CRON_V3_SHADOW_ENABLED' => 'false'"), 'shadow_false_missing');
    $assert(str_contains($receipts, 'hostinger_scheduler_absence_unverified'), 'scheduler_activation_gate_missing');
    $assert(str_contains($receipts, "'hostinger_scheduler' => \$schedulerContext"), 'scheduler_context_binding_missing');
    $assert(str_contains($canary, "'checkpoint_generation'"), 'checkpoint_generation_receipt_missing');
    $assert(str_contains($canary, "'watermark_sha256'"), 'checkpoint_watermark_evidence_missing');
    $assert(str_contains($canary, "\$passed=\$checkpoint!==[]"), 'checkpoint_pass_gate_missing');

    $assert(!preg_match('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:meli_orders|meli_payments|meli_shipments|sales|orders)\b/i', $service), 'business_dml_present');
    $assert(!preg_match('/\b(?:curl_|MeliApiClient|MeliApiTransport)\b/', $service), 'direct_remote_mutation_surface_present');
    $assert(!str_contains($service, 'cron_v4.php'), 'scheduler_command_embedded');
    $assert(!str_contains($service, 'storage/raw'), 'raw_storage_surface_present');
    $assert(!preg_match('/\bDELETE\s+FROM\b/i', $service), 'delete_dml_present');
    $assert(substr_count($flags, 'UPDATE queue_core_feature_flags') === 1, 'feature_update_surface_count_invalid');
    $assert(str_contains($flags, 'FOR UPDATE'), 'feature_row_lock_missing');
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL v4_readiness_bootstrap_admin_2369 ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

echo 'PASS v4_readiness_bootstrap_admin_2369 checks=' . $checks . PHP_EOL;
