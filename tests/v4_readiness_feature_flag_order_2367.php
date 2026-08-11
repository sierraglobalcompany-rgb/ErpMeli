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
    $method = new ReflectionMethod(V4ReadinessBootstrapService::class, 'flagsMatch');
    $invoke = static fn (array $actual, array $expected): bool => (bool) $method->invoke(null, $actual, $expected);

    $disabledExpected = [
        'fresh_producer' => false,
        'webhook_producer' => false,
        'pack_shipment_followups' => false,
        'remote_financial' => false,
        'historical_importer' => false,
    ];
    $disabledAlphabetical = [
        'fresh_producer' => false,
        'historical_importer' => false,
        'pack_shipment_followups' => false,
        'remote_financial' => false,
        'webhook_producer' => false,
    ];
    $readyExpected = [
        'fresh_producer' => true,
        'webhook_producer' => true,
        'pack_shipment_followups' => true,
        'remote_financial' => false,
        'historical_importer' => false,
    ];
    $readyReordered = [
        'webhook_producer' => true,
        'historical_importer' => false,
        'fresh_producer' => true,
        'remote_financial' => false,
        'pack_shipment_followups' => true,
    ];

    $disabledBefore = serialize($disabledAlphabetical);
    $expectedBefore = serialize($disabledExpected);
    $assert($disabledAlphabetical !== $disabledExpected, 'old_comparison_did_not_reproduce_bug');
    $assert($invoke($disabledAlphabetical, $disabledExpected), 'same_disabled_flags_different_order_blocked');
    $assert($invoke($readyReordered, $readyExpected), 'same_ready_flags_different_order_blocked');
    $assert(serialize($disabledAlphabetical) === $disabledBefore, 'actual_input_mutated');
    $assert(serialize($disabledExpected) === $expectedBefore, 'expected_input_mutated');

    $missing = $disabledAlphabetical;
    unset($missing['historical_importer']);
    $assert(!$invoke($missing, $disabledExpected), 'missing_flag_accepted');

    $extra = $disabledAlphabetical + ['foreign_feature' => false];
    $assert(!$invoke($extra, $disabledExpected), 'extra_flag_accepted');

    $wrongBoolean = $disabledAlphabetical;
    $wrongBoolean['fresh_producer'] = true;
    $assert(!$invoke($wrongBoolean, $disabledExpected), 'wrong_boolean_accepted');

    $wrongType = $disabledAlphabetical;
    $wrongType['fresh_producer'] = 0;
    $assert(!$invoke($wrongType, $disabledExpected), 'wrong_type_accepted');

    $source = (string) file_get_contents($root . '/app/Services/V4ReadinessBootstrapService.php');
    $assert(!str_contains($source, '$flags === self::FLAGS_READY'), 'order_sensitive_ready_snapshot_comparison_remains');
    $assert(!str_contains($source, '$flags === self::FLAGS_DISABLED'), 'order_sensitive_disabled_snapshot_comparison_remains');
    $assert(!str_contains($source, '$current === self::FLAGS_READY'), 'order_sensitive_ready_rollback_comparison_remains');
    $assert(!str_contains($source, '$current !== self::FLAGS_DISABLED'), 'order_sensitive_disabled_rollback_comparison_remains');
    $assert(substr_count($source, 'self::flagsMatch(') === 5, 'semantic_comparison_call_count_invalid');
    $assert(str_contains(
        $source,
        "self::flagsMatch(\$flags, self::FLAGS_DISABLED)) {\n                \$state = 'ready_to_arm';\n                \$reason = 'preconditions_pass';",
    ), 'ready_to_arm_branch_not_bound_to_semantic_comparison');
    $assert(str_contains(
        $source,
        "self::flagsMatch(\$flags, self::FLAGS_READY)\n                && \$pre['scheduler_absent_recorded']) {\n                \$state = 'ready_for_context';\n                \$reason = 'stable_authorities_ready';",
    ), 'ready_for_context_branch_not_bound_to_semantic_comparison');
    $assert(str_contains($source, "\$state = \$contextStable ? 'preparing' : 'blocked';"), 'preparing_context_gate_changed');
    $assert(str_contains($source, "\$state = 'certified';\n            \$reason = 'ready';"), 'certified_gate_changed');
    $assert(str_contains($source, "\$reason = \$contextStable ? 'readiness_in_progress' : 'readiness_context_changed';"), 'context_drift_gate_changed');
    $assert(str_contains($source, "!self::flagsMatch(\$current, self::FLAGS_DISABLED)"), 'rollback_disabled_authority_not_semantic');

    echo json_encode([
        'status' => 'PASS',
        'checks' => $checks,
        'bug_reproduction' => 'PASS',
        'old_comparison_result' => false,
        'new_comparison_result' => true,
        'same_flags_different_order' => 'PASS',
        'missing_flag' => 'BLOCK',
        'extra_flag' => 'BLOCK',
        'wrong_value' => 'BLOCK',
        'ready_to_arm' => 'PASS',
        'ready_for_context' => 'PASS',
        'preparing' => 'PASS',
        'certified' => 'PASS',
        'context_drift' => 'BLOCK',
        'rollback_authority' => 'PASS',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL v4_readiness_feature_flag_order_2367 ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
