<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/V4ReadinessBootstrapService.php');
$flags = (string) file_get_contents($root . '/app/QueueCore/QueueCoreFeatureFlagService.php');
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
    $assert(substr_count($routes, '/settings/cron/v3-setup/prepare-safe-config') === 1, 'new_public_route_added');
    $assert(str_contains($controller, "'v4_readiness_bootstrap'"), 'admin_dispatch_missing');
    $assert(str_contains($controller, 'AdministrativeReauthenticationService'), 'reauthentication_missing');
    $assert(str_contains($controller, '$this->requireAdminPermanent();'), 'permanent_admin_missing');
    $assert(str_contains($controller, '$this->assertSameOrigin();'), 'same_origin_missing');
    $assert(str_contains($controller, "Csrf::validate(\$_POST['_token'] ?? null);"), 'csrf_missing');
    $assert(str_contains($view, 'Preparar y certificar V4'), 'admin_label_missing');
    $assert(str_contains($view, 'name="admin_password"'), 'password_field_missing');
    $assert(str_contains($view, 'name="scheduler_absent_confirmed"'), 'scheduler_confirmation_missing');
    $assert(str_contains($js, "Boolean(scheduler?.checked)"), 'scheduler_ui_gate_missing');
    $assert(str_contains($js, "credentials: 'same-origin'"), 'same_origin_fetch_missing');

    $assert(str_contains($service, "public const REQUIRED_VERSION = '2.36.8'"), 'version_gate_missing');
    $assert(str_contains($service, "public const LAST_MIGRATION = '293_queue_core_runtime_profile_defaults_b2_1.sql'"), 'schema_gate_missing');
    $assert(str_contains($service, '$schemaCount !== 293 || $schemaMax !== 293 || $migration293Count !== 1'), 'exact_schema_missing');
    $assert(str_contains($service, "SELECT GET_LOCK(?,0)"), 'advisory_lock_missing');
    $assert(str_contains($service, "if (\$state === 'recovery_required')"), 'partial_recovery_dispatch_missing');
    $assert(str_contains($service, "'state' => 'recovered_fail_closed'"), 'partial_recovery_result_missing');
    $assert(str_contains($service, "'partial_arm_recovery_2368'"), 'partial_recovery_receipt_missing');
    $assert(str_contains($service, 'private static function isRecoverablePartialArm'), 'partial_recovery_classifier_missing');
    $assert(str_contains($service, 'partial_arm_requires_fail_closed_recovery'), 'partial_recovery_reason_missing');
    $assert(str_contains($service, "'recovery_required', 'ready_to_arm'"), 'partial_recovery_not_actionable');
    $assert(substr_count($service, 'self::flagsMatch(') === 6, 'semantic_flag_comparison_count_invalid');

    $armStart = strpos($service, 'private function armStableAuthorities');
    $armEnd = strpos($service, 'private function enterReadiness', $armStart ?: 0);
    $armBody = $armStart !== false && $armEnd !== false ? substr($service, $armStart, $armEnd - $armStart) : '';
    $assert($armBody !== '', 'arm_method_missing');
    $assert(!str_contains($armBody, 'catch (Throwable'), 'partial_arm_rollback_remains');
    $assert(str_contains($service, 'private function rollbackIfArmed'), 'central_rollback_missing');
    $assert(str_contains($service, 'restoreV4FailClosedConfig'), 'config_restore_missing');
    $assert(str_contains($service, 'stopApi'), 'api_stop_missing');
    $rollbackStart = strpos($service, 'private function rollbackAuthorities');
    $rollbackBody = $rollbackStart === false ? '' : substr($service, $rollbackStart);
    $assert(strpos($rollbackBody, '$attempt(\'api\'') < strpos($rollbackBody, '$attempt(\'engine\''), 'api_not_prioritized');
    $assert(strpos($rollbackBody, '$attempt(\'config\'') < strpos($rollbackBody, '$attempt(\'engine\''), 'config_not_prioritized');
    $assert(str_contains($rollbackBody, 'v4_bootstrap_rollback_incomplete:'), 'rollback_aggregation_missing');

    foreach (['fresh_producer', 'webhook_producer', 'pack_shipment_followups'] as $feature) {
        $assert(str_contains($service, "'$feature' => true"), 'ready_feature_missing:' . $feature);
        $assert(str_contains($service, "feature_generation_invalid:$feature"), 'recovery_generation_gate_missing:' . $feature);
    }
    foreach (['remote_financial', 'historical_importer'] as $feature) {
        $assert(str_contains($service, "'$feature' => false"), 'disabled_feature_missing:' . $feature);
    }
    $assert(str_contains($config, "'CRON_V4_ENABLED' => 'true'"), 'v4_ready_config_missing');
    $assert(str_contains($config, "'CRON_V4_ENABLED' => 'false'"), 'v4_fail_closed_config_missing');
    $assert(str_contains($config, "'CRON_V3_ENABLED' => 'false'"), 'v3_false_missing');
    $assert(str_contains($config, "'CRON_V3_SHADOW_ENABLED' => 'false'"), 'shadow_false_missing');

    $assert(!preg_match('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:meli_orders|meli_payments|meli_shipments|sales|orders)\b/i', $service), 'business_dml_present');
    $assert(!preg_match('/\b(?:curl_|MeliApiClient|MeliApiTransport)\b/', $service), 'direct_http_present');
    $assert(!str_contains($service, 'cron_v4.php'), 'scheduler_command_embedded');
    $assert(!str_contains($service, 'storage/raw'), 'raw_storage_surface_present');
    $assert(!preg_match('/\bDELETE\s+FROM\b/i', $service), 'delete_dml_present');
    $assert(substr_count($flags, 'UPDATE queue_core_feature_flags') === 1, 'feature_update_surface_changed');
    $assert(str_contains($flags, 'FOR UPDATE'), 'feature_row_lock_missing');
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL v4_readiness_bootstrap_admin_2368 ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

echo 'PASS v4_readiness_bootstrap_admin_2368 checks=' . $checks . PHP_EOL;
