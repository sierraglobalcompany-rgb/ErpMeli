<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Services\ManagedRuntimePublicationPolicy;

function historicalProfileAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $message);
    }
    echo 'PASS:' . $message . PHP_EOL;
}

$profiles = [
    '2.40.0' => [
        'build_id' => 'erp-meli-2.40.0-k10-smart-manual-drain-rc1-20260826',
        'minimum_migration' => '300_manual_drain_sessions_2_40_0.sql',
    ],
    '2.40.1' => [
        'build_id' => 'erp-meli-2.40.1-worker-cycle-control-single-truth-rc1-20260827',
        'minimum_migration' => '301_k1d_api_safety_2_40_1.sql',
    ],
    '2.41.0' => [
        'build_id' => 'erp-meli-2.41.0-financial-v2-billing-capture-authority-rc1-20261006',
        'minimum_migration' => '302_financial_v2_billing_capture_authority.sql',
    ],
    '2.41.1' => [
        'build_id' => 'erp-meli-2.41.1-outer-cron-http-receipt-rc1-20261007',
        'minimum_migration' => '303_outer_cron_http_receipt.sql',
    ],
];

foreach ($profiles as $version => $expected) {
    $manifest = ['version' => $version] + $expected;
    historicalProfileAssert(
        ManagedRuntimePublicationPolicy::recognizesInstalledManifest($manifest),
        'profile_' . $version . '_recognizes_exact_migration_' . $expected['minimum_migration']
    );
}

$wrongHistoricalProfile = [
    'version' => '2.40.0',
    'build_id' => $profiles['2.40.0']['build_id'],
    'minimum_migration' => $profiles['2.41.0']['minimum_migration'],
];
historicalProfileAssert(
    !ManagedRuntimePublicationPolicy::recognizesInstalledManifest($wrongHistoricalProfile),
    'profile_2_40_0_rejects_current_schema_302'
);

$wrong2410 = ['version' => '2.41.0', 'build_id' => $profiles['2.41.0']['build_id'],
    'minimum_migration' => $profiles['2.41.1']['minimum_migration']];
historicalProfileAssert(!ManagedRuntimePublicationPolicy::recognizesInstalledManifest($wrong2410),
    'profile_2_41_0_rejects_schema_303');
$wrong2411 = ['version' => '2.41.1', 'build_id' => $profiles['2.41.1']['build_id'],
    'minimum_migration' => $profiles['2.41.0']['minimum_migration']];
historicalProfileAssert(!ManagedRuntimePublicationPolicy::recognizesInstalledManifest($wrong2411),
    'profile_2_41_1_rejects_schema_302');
echo "HISTORICAL_RELEASE_PROFILES=7/7_PASS\n";
$diagnostics = (string) file_get_contents(__DIR__ . '/../app/Services/QueueV4DiagnosticBundleService.php');
historicalProfileAssert(str_contains($diagnostics, "'production_version_expected' => '2.41.1'"),
    'diagnostic_bundle_current_release_2411');
historicalProfileAssert(str_contains($diagnostics, "if (\$versionFile !== '2.41.0')"),
    'legacy_429_reconciliation_guard_remains_2410_fail_closed');
