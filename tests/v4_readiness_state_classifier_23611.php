<?php

declare(strict_types=1);

use App\Services\V4ReadinessBootstrapService;

require dirname(__DIR__) . '/bootstrap.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$classifier = new ReflectionMethod(V4ReadinessBootstrapService::class, 'classifyReadinessState');
$runtime = new ReflectionMethod(V4ReadinessBootstrapService::class, 'runtimeAuthority');
$features = new ReflectionMethod(V4ReadinessBootstrapService::class, 'featureGenerationAuthority');

$disabled = [
    'fresh_producer' => false,
    'webhook_producer' => false,
    'pack_shipment_followups' => false,
    'remote_financial' => false,
    'historical_importer' => false,
];
$armed = $disabled;
$armed['fresh_producer'] = true;
$armed['webhook_producer'] = true;
$armed['pack_shipment_followups'] = true;
$generations = static fn (int $g): array => [
    'fresh_producer' => $g,
    'webhook_producer' => $g,
    'pack_shipment_followups' => $g,
    'remote_financial' => 0,
    'historical_importer' => 0,
];
$engine = static fn (string $mode, int $generation): array => [
    'active_engine' => 'disabled',
    'readiness_mode' => $mode,
    'readiness_context_hash' => $mode === 'preparing' ? str_repeat('a', 64) : '',
    'generation' => $generation,
];
$runtimeObserved = static fn (bool $v4, string $api): array => [
    'cron_v4_enabled' => $v4,
    'cron_v3_enabled' => false,
    'cron_v3_shadow_enabled' => false,
    'ml_write_enabled' => false,
    'api' => $api,
    'automation' => 'stopped',
];
$pre = static function (
    array $engineState,
    array $flags,
    array $generationRows,
    array $runtimeState,
    bool $baseOk = true,
) use ($features, $runtime): array {
    $runtimeAuthority = $runtime->invoke(null, $runtimeState);
    $featureAuthority = $features->invoke(null, $engineState, $flags, $generationRows);
    return [
        'ok' => $baseOk && $runtimeAuthority['profile'] === 'armed',
        'base_ok' => $baseOk,
        'reason' => $baseOk ? 'ready' : 'scheduler_absence_authority_missing',
        'feature_generation_authority' => $featureAuthority,
        'runtime_authority' => $runtimeAuthority,
        'scheduler_absent_recorded' => $baseOk,
        'file_version' => '2.36.15',
        'app_version' => '2.36.15',
        'schema' => 293,
        'schema_count' => 293,
        'migration_293_count' => 1,
        'v3_active_ownership' => 0,
        'v3_retired' => true,
        'active_leases' => 0,
        'uncertain_executions' => 0,
        'canary_uncertain_recovery' => [
            'total_uncertain' => 0,
            'eligible_canary_get' => 0,
            'recoverable' => false,
            'blockers' => [],
        ],
        'canary_uncertain_recovery_base_ok' => false,
        'active_runs' => 0,
        'historical_importer' => false,
        'queue_core_preflight_ok' => true,
        'oauth_current_accounts' => 3,
        'cron_v4_enabled' => (bool) $runtimeState['cron_v4_enabled'],
        'cron_v3_enabled' => (bool) $runtimeState['cron_v3_enabled'],
        'cron_v3_shadow_enabled' => (bool) $runtimeState['cron_v3_shadow_enabled'],
        'ml_write_enabled' => (bool) $runtimeState['ml_write_enabled'],
        'api' => (string) $runtimeState['api'],
        'automation' => (string) $runtimeState['automation'],
    ];
};
$classify = static function (array $engineState, array $flags, array $preState, bool $stable = true) use ($classifier): array {
    return $classifier->invoke(null, $engineState, $flags, $preState, $stable, null, null);
};

try {
    $profiles = [
        [false, 'stopped', 'fail_closed'],
        [true, 'enabled', 'armed'],
        [false, 'enabled', 'api_started_partial'],
        [true, 'stopped', 'config_only_partial'],
    ];
    foreach ($profiles as [$v4, $api, $expected]) {
        $actual = $runtime->invoke(null, $runtimeObserved($v4, $api));
        $assert($actual['profile'] === $expected && $actual['ok'], 'runtime_profile_invalid:' . $expected);
    }
    foreach ([0, 2, 11] as $g) {
        $idle = $engine('idle', $g);
        $baseline = $pre($idle, $disabled, $generations($g), $runtimeObserved(false, 'stopped'));
        $result = $classify($idle, $disabled, $baseline);
        $assert($result['state'] === 'ready_to_arm', 'baseline_not_ready_to_arm:' . $g);

        $armedPre = $pre($idle, $armed, $generations($g + 1), $runtimeObserved(true, 'enabled'));
        $result = $classify($idle, $armed, $armedPre);
        $assert($result['state'] === 'ready_for_context', 'armed_not_ready_for_context:' . $g);

        $preparingEngine = $engine('preparing', $g + 1);
        $preparingPre = $pre(
            $preparingEngine,
            $armed,
            $generations($g + 1),
            $runtimeObserved(true, 'enabled'),
        );
        $result = $classify($preparingEngine, $armed, $preparingPre);
        $assert($result['state'] === 'preparing', 'preparing_not_recognized:' . $g);

        foreach ([[false, 'stopped'], [false, 'enabled'], [true, 'stopped']] as [$v4, $api]) {
            $partial = $pre($idle, $armed, $generations($g + 1), $runtimeObserved($v4, $api));
            $result = $classify($idle, $armed, $partial);
            $assert($result['state'] === 'recovery_required', 'armed_partial_not_recoverable:' . $g . ':' . $api);
            $assert($result['reason'] !== 'ready', 'recovery_reason_ready:' . $g);
        }

        $legacyPartial = $pre($idle, $disabled, $generations($g), $runtimeObserved(true, 'stopped'));
        $assert(
            $classify($idle, $disabled, $legacyPartial)['state'] === 'recovery_required',
            'config_only_partial_not_recoverable:' . $g,
        );

        $preparingPartial = $pre(
            $preparingEngine,
            $armed,
            $generations($g + 1),
            $runtimeObserved(false, 'stopped'),
        );
        $assert(
            $classify($preparingEngine, $armed, $preparingPartial)['state'] === 'recovery_required',
            'preparing_partial_not_recoverable:' . $g,
        );

        $certifiedResult = $classifier->invoke(
            null,
            $preparingEngine,
            $armed,
            $preparingPre,
            true,
            ['generation' => $g + 1],
            ['ok' => true],
        );
        $assert($certifiedResult['state'] === 'certified', 'certified_current_not_recognized:' . $g);

        $certifiedDrift = $preparingPre;
        $certifiedDrift['base_ok'] = false;
        $certifiedDrift['ok'] = false;
        $certifiedDrift['reason'] = 'app_version_invalid';
        $certifiedResult = $classifier->invoke(
            null,
            $preparingEngine,
            $armed,
            $certifiedDrift,
            true,
            ['generation' => $g + 1],
            ['ok' => true],
        );
        $assert(
            $certifiedResult['state'] === 'blocked'
                && $certifiedResult['reason'] === 'app_version_invalid',
            'certified_base_drift_not_blocked:' . $g,
        );
    }

    $invalidRuntime = $runtimeObserved(false, 'stopped');
    $invalidRuntime['cron_v3_enabled'] = true;
    $invalidPre = $pre($engine('idle', 2), $disabled, $generations(2), $invalidRuntime);
    $invalidPre['base_ok'] = false;
    $invalidPre['reason'] = 'runtime_authority_invalid:cron_v3_enabled';
    $blocked = $classify($engine('idle', 2), $disabled, $invalidPre);
    $assert($blocked['state'] === 'blocked', 'v3_runtime_not_blocked');
    $assert($blocked['reason'] !== 'ready', 'blocked_reason_ready');

    $missingScheduler = $pre(
        $engine('idle', 2),
        $disabled,
        $generations(2),
        $runtimeObserved(false, 'stopped'),
        false,
    );
    $blocked = $classify($engine('idle', 2), $disabled, $missingScheduler);
    $assert($blocked['state'] === 'blocked' && $blocked['reason'] === 'scheduler_absence_authority_missing', 'base_gate_not_exact');

    $unknown = $pre($engine('idle', 2), $disabled, $generations(2), $runtimeObserved(false, 'stopped'));
    $unknown['feature_generation_authority']['profile'] = 'foreign';
    $unknown['feature_generation_authority']['ok'] = true;
    $blocked = $classify($engine('idle', 2), $disabled, $unknown);
    $assert($blocked['state'] === 'blocked', 'foreign_profile_not_blocked');
    $assert(str_starts_with($blocked['reason'], 'readiness_state_unclassified:'), 'unclassified_reason_missing');

    $uncertainIdle = $pre(
        $engine('idle', 10),
        $disabled,
        $generations(10),
        $runtimeObserved(false, 'stopped'),
    );
    $uncertainIdle['base_ok'] = false;
    $uncertainIdle['ok'] = false;
    $uncertainIdle['reason'] = 'uncertain_execution_present';
    $uncertainIdle['uncertain_executions'] = 1;
    $uncertainIdle['canary_uncertain_recovery_base_ok'] = true;
    $uncertainIdle['canary_uncertain_recovery'] = [
        'total_uncertain' => 1,
        'eligible_canary_get' => 1,
        'recoverable' => true,
        'blockers' => [],
    ];
    $result = $classify($engine('idle', 10), $disabled, $uncertainIdle);
    $assert(
        $result['state'] === 'canary_uncertain_recovery_required'
            && $result['reason'] === 'safe_canary_get_uncertain_recovery_available',
        'idle_fail_closed_canary_get_uncertainty_not_actionable',
    );

    $preparingEngine = $engine('preparing', 9);
    $uncertainPreparing = $pre(
        $preparingEngine,
        $armed,
        $generations(9),
        $runtimeObserved(true, 'enabled'),
    );
    $uncertainPreparing['base_ok'] = false;
    $uncertainPreparing['ok'] = false;
    $uncertainPreparing['reason'] = 'uncertain_execution_present';
    $uncertainPreparing['uncertain_executions'] = 1;
    $uncertainPreparing['canary_uncertain_recovery_base_ok'] = true;
    $uncertainPreparing['canary_uncertain_recovery'] = $uncertainIdle['canary_uncertain_recovery'];
    $result = $classify($preparingEngine, $armed, $uncertainPreparing, true);
    $assert($result['state'] === 'canary_uncertain_recovery_required', 'preparing_canary_get_uncertainty_not_actionable');
    $assert(
        $classify($preparingEngine, $armed, $uncertainPreparing, false)['state'] === 'blocked',
        'context_drift_canary_uncertainty_was_recovered',
    );
    $result = $classifier->invoke(
        null,
        $preparingEngine,
        $armed,
        $uncertainPreparing,
        true,
        ['generation' => 9],
        ['ok' => true],
    );
    $assert($result['state'] === 'blocked', 'certified_authority_allowed_canary_uncertain_recovery');

    echo 'V4 readiness state classifier 2.36.15: PASS checks=' . $checks . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'V4 readiness state classifier 2.36.15: FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
