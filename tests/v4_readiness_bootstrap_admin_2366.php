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
    $assert(substr_count($routes, '/settings/cron/v3-setup/prepare-safe-config') === 1, 'new_public_post_route_added');
    $assert(str_contains($controller, "'v4_readiness_bootstrap'"), 'admin_operation_dispatch_missing');
    $assert(str_contains($controller, 'AdministrativeReauthenticationService'), 'password_reauthentication_missing');
    $assert(str_contains($controller, '$this->requireAdminPermanent();'), 'permanent_admin_missing');
    $assert(str_contains($controller, '$this->assertSameOrigin();'), 'same_origin_missing');
    $assert(str_contains($controller, "Csrf::validate(\$_POST['_token'] ?? null);"), 'csrf_missing');
    $assert(str_contains($view, 'Preparar y certificar V4'), 'admin_label_missing');
    $assert(str_contains($view, 'name="admin_password"'), 'password_field_missing');
    $assert(str_contains($view, 'name="scheduler_absent_confirmed"'), 'scheduler_confirmation_missing');
    $assert(str_contains($view, 'PREPARAR_Y_CERTIFICAR_V4_SIN_SCHEDULER'), 'confirmation_phrase_missing');
    $assert(str_contains($js, "Boolean(scheduler?.checked)"), 'scheduler_ui_gate_missing');
    $assert(str_contains($js, "credentials: 'same-origin'"), 'same_origin_fetch_missing');

    $assert(str_contains($service, "public const REQUIRED_VERSION = '2.36.6'"), 'version_gate_missing');
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
    fwrite(STDERR, 'FAIL v4_readiness_bootstrap_admin_2366 ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

echo 'PASS v4_readiness_bootstrap_admin_2366 checks=' . $checks . PHP_EOL;
