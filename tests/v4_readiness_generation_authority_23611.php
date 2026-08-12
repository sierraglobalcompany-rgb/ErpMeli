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

$authorityMethod = new ReflectionMethod(V4ReadinessBootstrapService::class, 'featureGenerationAuthority');
$recoverMethod = new ReflectionMethod(V4ReadinessBootstrapService::class, 'isRecoverablePartialArm');

$disabled = [
    'fresh_producer' => false,
    'webhook_producer' => false,
    'pack_shipment_followups' => false,
    'remote_financial' => false,
    'historical_importer' => false,
];
$ready = $disabled;
$ready['fresh_producer'] = true;
$ready['webhook_producer'] = true;
$ready['pack_shipment_followups'] = true;

$generations = static fn (int $generation): array => [
    'fresh_producer' => $generation,
    'webhook_producer' => $generation,
    'pack_shipment_followups' => $generation,
    'remote_financial' => 0,
    'historical_importer' => 0,
];
$engine = static fn (string $mode, int $generation): array => [
    'active_engine' => 'disabled',
    'readiness_mode' => $mode,
    'readiness_context_hash' => $mode === 'preparing' ? str_repeat('a', 64) : '',
    'generation' => $generation,
];

try {
    foreach ([0, 2, 9] as $baseline) {
        $failClosed = $authorityMethod->invoke(null, $engine('idle', $baseline), $disabled, $generations($baseline));
        $assert($failClosed['ok'] === true, 'fail_closed_generation_rejected:' . $baseline);
        $assert($failClosed['profile'] === 'fail_closed', 'fail_closed_profile_invalid:' . $baseline);
        $assert($failClosed['engine_generation'] === $baseline, 'fail_closed_engine_generation_invalid:' . $baseline);

        $armed = $authorityMethod->invoke(null, $engine('idle', $baseline), $ready, $generations($baseline + 1));
        $assert($armed['ok'] === true, 'armed_generation_rejected:' . $baseline);
        $assert($armed['profile'] === 'armed', 'armed_profile_invalid:' . $baseline);

        $preparing = $authorityMethod->invoke(
            null,
            $engine('preparing', $baseline + 1),
            $ready,
            $generations($baseline + 1),
        );
        $assert($preparing['ok'] === true, 'preparing_generation_rejected:' . $baseline);
        $assert($preparing['profile'] === 'preparing', 'preparing_profile_invalid:' . $baseline);
    }

    foreach (array_keys($generations(2)) as $feature) {
        $drift = $generations(2);
        $drift[$feature]++;
        $result = $authorityMethod->invoke(null, $engine('idle', 2), $disabled, $drift);
        $assert($result['ok'] === false, 'generation_drift_accepted:' . $feature);
        $assert(($result['mismatches'][0]['feature'] ?? '') === $feature, 'generation_mismatch_not_exposed:' . $feature);
    }

    $missing = $generations(2);
    unset($missing['fresh_producer']);
    $assert(
        $authorityMethod->invoke(null, $engine('idle', 2), $disabled, $missing)['ok'] === false,
        'missing_generation_accepted',
    );
    $extra = $generations(2);
    $extra['foreign_feature'] = 2;
    $assert(
        $authorityMethod->invoke(null, $engine('idle', 2), $disabled, $extra)['ok'] === false,
        'foreign_generation_accepted',
    );
    $activeEngine = $engine('idle', 2);
    $activeEngine['active_engine'] = 'v4';
    $assert(
        $authorityMethod->invoke(null, $activeEngine, $disabled, $generations(2))['profile'] === 'invalid',
        'active_engine_profile_accepted',
    );

    $incidentEngine = $engine('idle', 2);
    $incidentAuthority = $authorityMethod->invoke(null, $incidentEngine, $disabled, $generations(2));
    $incidentPre = [
        'feature_generation_authority' => $incidentAuthority,
        'runtime_authority' => [
            'ok' => true,
            'profile' => 'config_only_partial',
            'observed' => [
                'cron_v4_enabled' => true,
                'cron_v3_enabled' => false,
                'cron_v3_shadow_enabled' => false,
                'ml_write_enabled' => false,
                'api' => 'stopped',
                'automation' => 'stopped',
            ],
            'mismatches' => [],
        ],
        'file_version' => '2.36.11',
        'app_version' => '2.36.11',
        'schema' => 293,
        'schema_count' => 293,
        'migration_293_count' => 1,
        'v3_active_ownership' => 0,
        'v3_retired' => true,
        'active_leases' => 0,
        'uncertain_executions' => 0,
        'active_runs' => 0,
        'historical_importer' => false,
        'queue_core_preflight_ok' => true,
        'oauth_current_accounts' => 3,
        'scheduler_absent_recorded' => true,
        'cron_v4_enabled' => true,
        'cron_v3_enabled' => false,
        'cron_v3_shadow_enabled' => false,
        'ml_write_enabled' => false,
        'api' => 'stopped',
        'automation' => 'stopped',
    ];
    $assert($recoverMethod->invoke(null, $incidentEngine, $disabled, $incidentPre), 'generation_2_incident_not_recoverable');
    $incidentPre['feature_generation_authority']['ok'] = false;
    $assert(!$recoverMethod->invoke(null, $incidentEngine, $disabled, $incidentPre), 'invalid_authority_recovery_accepted');

    $source = file_get_contents(dirname(__DIR__) . '/app/Services/V4ReadinessBootstrapService.php');
    $assert(is_string($source), 'service_source_unreadable');
    $assert(
        !str_contains($source, "(int) (\$transition['generation'] ?? -1) !== 1"),
        'hardcoded_transition_generation_one_remains',
    );
    $assert(str_contains($source, "'feature_generation_authority' => \$featureGenerationAuthority"), 'snapshot_authority_missing');
    $assert(str_contains($source, '$nextGeneration = (int) $pre[\'engine\'][\'generation\'] + 1'), 'dynamic_arm_generation_missing');
    $assert(str_contains($source, "\$runtimeProfile === 'armed' && \$contextStable && !empty(\$pre['ok'])"), 'preparing_preconditions_gate_missing');

    echo 'V4 generation authority 2.36.11: PASS checks=' . $checks . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'V4 generation authority 2.36.11: FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
