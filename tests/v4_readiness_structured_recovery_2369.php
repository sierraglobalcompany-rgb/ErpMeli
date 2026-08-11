<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/QueueCore/QueueCoreFeatureFlagService.php';
require $root . '/app/Services/V4ReadinessBootstrapService.php';

use App\Services\V4ReadinessBootstrapService;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $method = new ReflectionMethod(V4ReadinessBootstrapService::class, 'isRecoverablePartialArm');
    $recoverable = static fn (array $engine, array $flags, array $pre): bool =>
        (bool) $method->invoke(null, $engine, $flags, $pre);
    $schedulerMethod = new ReflectionMethod(V4ReadinessBootstrapService::class, 'schedulerAbsenceRecorded');
    $schedulerRecorded = static fn (array $authority): bool =>
        (bool) $schedulerMethod->invoke(null, $authority);
    $assert($schedulerRecorded([
        'status' => 'absent',
        'authority' => 'permanent_admin_explicit_confirmation',
    ]), 'explicit_scheduler_authority_rejected');
    $assert($schedulerRecorded([
        'status' => 'absent',
        'authority' => 'rollback_preserved_absence',
    ]), 'rollback_scheduler_authority_rejected');
    foreach (['', 'unknown', 'fixture', 'forged'] as $authority) {
        $assert(!$schedulerRecorded(['status' => 'absent', 'authority' => $authority]), 'foreign_scheduler_authority_accepted:' . $authority);
    }
    $assert(!$schedulerRecorded([
        'status' => 'present',
        'authority' => 'permanent_admin_explicit_confirmation',
    ]), 'present_scheduler_accepted');

    $engine = [
        'active_engine' => 'disabled',
        'readiness_mode' => 'idle',
        'readiness_context_hash' => '',
        'generation' => 0,
    ];
    $flags = [
        'historical_importer' => false,
        'pack_shipment_followups' => false,
        'remote_financial' => false,
        'webhook_producer' => false,
        'fresh_producer' => false,
    ];
    $generations = [
        'fresh_producer' => 0,
        'historical_importer' => 0,
        'pack_shipment_followups' => 0,
        'remote_financial' => 0,
        'webhook_producer' => 0,
    ];
    $pre = [
        'file_version' => '2.36.9',
        'app_version' => '2.36.9',
        'schema' => 293,
        'schema_count' => 293,
        'migration_293_count' => 1,
        'v3_active_ownership' => 0,
        'v3_retired' => true,
        'active_leases' => 0,
        'uncertain_executions' => 0,
        'active_runs' => 0,
        'historical_importer' => false,
        'feature_generations' => $generations,
        'queue_core_preflight_ok' => true,
        'oauth_current_accounts' => 3,
        'scheduler_absent_recorded' => true,
        'cron_v4_enabled' => true,
        'cron_v3_enabled' => false,
        'cron_v3_shadow_enabled' => false,
        'ml_write_enabled' => false,
        'api' => 'stopped',
        'automation' => 'stopped',
        'issues' => ['wording_is_not_authority'],
    ];

    $assert($recoverable($engine, $flags, $pre), 'exact_structured_partial_arm_not_recoverable');
    $variant = $pre;
    $variant['issues'] = ['completely_different_text', 'another_text'];
    $assert($recoverable($engine, $flags, $variant), 'issue_text_influenced_structured_authority');

    foreach ([
        'active_engine' => 'v4',
        'readiness_mode' => 'preparing',
        'readiness_context_hash' => str_repeat('a', 64),
        'generation' => 1,
    ] as $key => $value) {
        $variant = $engine;
        $variant[$key] = $value;
        $assert(!$recoverable($variant, $flags, $pre), 'engine_drift_accepted:' . $key);
    }

    foreach (array_keys($flags) as $feature) {
        $variant = $flags;
        $variant[$feature] = true;
        $assert(!$recoverable($engine, $variant, $pre), 'enabled_feature_accepted:' . $feature);
    }
    $variant = $flags;
    $variant['foreign_feature'] = false;
    $assert(!$recoverable($engine, $variant, $pre), 'foreign_feature_accepted');

    foreach (array_keys($generations) as $feature) {
        $variant = $pre;
        $variant['feature_generations'][$feature] = 1;
        $assert(!$recoverable($engine, $flags, $variant), 'feature_generation_drift_accepted:' . $feature);
    }
    $variant = $pre;
    unset($variant['feature_generations']['fresh_producer']);
    $assert(!$recoverable($engine, $flags, $variant), 'missing_feature_generation_accepted');
    $variant = $pre;
    $variant['feature_generations']['foreign_feature'] = 0;
    $assert(!$recoverable($engine, $flags, $variant), 'foreign_feature_generation_accepted');

    foreach ([
        'file_version' => '2.36.8',
        'app_version' => '2.36.8',
        'schema' => 294,
        'schema_count' => 292,
        'migration_293_count' => 0,
        'v3_active_ownership' => 1,
        'active_leases' => 1,
        'uncertain_executions' => 1,
        'active_runs' => 1,
        'oauth_current_accounts' => 2,
        'api' => 'enabled',
        'automation' => 'enabled',
    ] as $key => $value) {
        $variant = $pre;
        $variant[$key] = $value;
        $assert(!$recoverable($engine, $flags, $variant), 'structured_drift_accepted:' . $key);
    }
    foreach (['v3_retired', 'queue_core_preflight_ok', 'scheduler_absent_recorded', 'cron_v4_enabled'] as $key) {
        $variant = $pre;
        $variant[$key] = false;
        $assert(!$recoverable($engine, $flags, $variant), 'missing_authority_accepted:' . $key);
    }
    foreach (['historical_importer', 'cron_v3_enabled', 'cron_v3_shadow_enabled', 'ml_write_enabled'] as $key) {
        $variant = $pre;
        $variant[$key] = true;
        $assert(!$recoverable($engine, $flags, $variant), 'unsafe_authority_accepted:' . $key);
    }

    $authority = new ReflectionMethod(V4ReadinessBootstrapService::class, 'assertRequestAuthority');
    $instance = (new ReflectionClass(V4ReadinessBootstrapService::class))->newInstanceWithoutConstructor();
    $authority->invoke($instance, 1);
    try {
        $authority->invoke($instance, 0);
        $assert(false, 'missing_admin_accepted');
    } catch (ReflectionException $error) {
        throw $error;
    } catch (Throwable $error) {
        $assert(str_contains($error->getMessage(), 'v4_bootstrap_admin_required'), 'wrong_admin_error');
    }

    $source = (string) file_get_contents($root . '/app/Services/V4ReadinessBootstrapService.php');
    $assert(!str_contains($source, '$allowed = ['), 'issue_allowlist_remains');
    $assert(!str_contains($source, "in_array('feature_generation_invalid:"), 'issue_text_classifier_remains');
    $assert(str_contains($source, "'state' => 'recovered_fail_closed'"), 'recovery_terminal_missing');
    $assert(str_contains($source, "'requires_next_request' => true"), 'next_request_boundary_missing');
    $assert(str_contains($source, "'partial_arm_recovery_2369'"), 'recovery_reason_missing');

    echo 'V4 structured recovery 2.36.9: PASS checks=' . $checks . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'V4 structured recovery 2.36.9: FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
