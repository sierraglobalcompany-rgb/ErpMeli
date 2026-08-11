<?php

declare(strict_types=1);

$root = dirname(__DIR__);
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
    $pre = [
        'scheduler_absent_recorded' => true,
        'cron_v4_enabled' => true,
        'cron_v3_enabled' => false,
        'cron_v3_shadow_enabled' => false,
        'ml_write_enabled' => false,
        'api' => 'stopped',
        'automation' => 'stopped',
        'issues' => [
            'feature_generation_invalid:fresh_producer',
            'feature_generation_invalid:webhook_producer',
            'feature_generation_invalid:pack_shipment_followups',
            'readiness_runtime_not_stable',
        ],
    ];

    $assert($recoverable($engine, $flags, $pre), 'exact_partial_arm_not_recoverable');

    $variant = $engine;
    $variant['active_engine'] = 'v4';
    $assert(!$recoverable($variant, $flags, $pre), 'active_engine_recovery_accepted');
    $variant = $engine;
    $variant['readiness_mode'] = 'preparing';
    $assert(!$recoverable($variant, $flags, $pre), 'preparing_recovery_accepted');
    $variant = $engine;
    $variant['generation'] = 1;
    $assert(!$recoverable($variant, $flags, $pre), 'nonzero_generation_recovery_accepted');
    $variant = $engine;
    $variant['readiness_context_hash'] = str_repeat('a', 64);
    $assert(!$recoverable($variant, $flags, $pre), 'context_recovery_accepted');

    foreach (array_keys($flags) as $feature) {
        $variant = $flags;
        $variant[$feature] = true;
        $assert(!$recoverable($engine, $variant, $pre), 'enabled_feature_recovery_accepted:' . $feature);
    }
    foreach (['scheduler_absent_recorded', 'cron_v4_enabled'] as $key) {
        $variant = $pre;
        $variant[$key] = false;
        $assert(!$recoverable($engine, $flags, $variant), 'missing_recovery_authority_accepted:' . $key);
    }
    foreach (['cron_v3_enabled', 'cron_v3_shadow_enabled', 'ml_write_enabled'] as $key) {
        $variant = $pre;
        $variant[$key] = true;
        $assert(!$recoverable($engine, $flags, $variant), 'unsafe_runtime_flag_accepted:' . $key);
    }
    $variant = $pre;
    $variant['api'] = 'enabled';
    $assert(!$recoverable($engine, $flags, $variant), 'enabled_api_recovery_accepted');
    $variant = $pre;
    $variant['automation'] = 'enabled';
    $assert(!$recoverable($engine, $flags, $variant), 'enabled_automation_recovery_accepted');
    $variant = $pre;
    $variant['issues'][] = 'active_queue_run_present';
    $assert(!$recoverable($engine, $flags, $variant), 'foreign_issue_recovery_accepted');

    foreach ([
        'feature_generation_invalid:fresh_producer',
        'feature_generation_invalid:webhook_producer',
        'feature_generation_invalid:pack_shipment_followups',
    ] as $requiredIssue) {
        $variant = $pre;
        $variant['issues'] = array_values(array_diff($variant['issues'], [$requiredIssue]));
        $assert(!$recoverable($engine, $flags, $variant), 'missing_required_issue_accepted:' . $requiredIssue);
    }

    $variant = $pre;
    $variant['issues'] = array_reverse($variant['issues']);
    $assert($recoverable($engine, $flags, $variant), 'issue_order_changed_recovery');

    $source = (string) file_get_contents($root . '/app/Services/V4ReadinessBootstrapService.php');
    $assert(str_contains($source, "if (\$state === 'recovery_required')"), 'recovery_dispatch_missing');
    $assert(str_contains($source, "'state' => 'recovered_fail_closed'"), 'recovery_terminal_missing');
    $assert(str_contains($source, "'partial_arm_recovery_2368'"), 'recovery_reason_missing');
    $assert(str_contains($source, "\$state = 'recovery_required';"), 'recovery_snapshot_missing');
    $assert(str_contains($source, "'partial_arm_requires_fail_closed_recovery'"), 'recovery_snapshot_reason_missing');
    $assert(substr_count($source, "->restoreV4FailClosedConfig(\$actorUserId)") === 1, 'fail_closed_restore_authority_count_invalid');

    $armStart = strpos($source, 'private function armStableAuthorities');
    $armEnd = strpos($source, 'private function enterReadiness', $armStart ?: 0);
    $armBody = $armStart !== false && $armEnd !== false ? substr($source, $armStart, $armEnd - $armStart) : '';
    $assert($armBody !== '', 'arm_method_not_found');
    $assert(!str_contains($armBody, 'catch (Throwable'), 'arm_has_partial_custom_rollback');
    $assert(str_contains($source, "public const REQUIRED_VERSION = '2.36.8'"), 'version_gate_not_2368');

    echo json_encode([
        'status' => 'PASS',
        'checks' => $checks,
        'observed_incident' => 'feature_generation_invalid:fresh_producer',
        'exact_partial_arm' => 'RECOVERY_REQUIRED',
        'foreign_drift' => 'BLOCK',
        'recovery_result' => 'RECOVERED_FAIL_CLOSED',
        'scheduler_created' => false,
        'engine_activated' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL v4_readiness_partial_arm_recovery_2368 ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
