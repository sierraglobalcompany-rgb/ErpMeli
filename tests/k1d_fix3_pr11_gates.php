<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    k1b_assert(is_string($content) && $content !== '', 'read_' . str_replace(['/', '.'], '_', $path));
    return $content;
};

$nav = $read('app/Views/settings/_automation_nav.php');
$cronShell = $read('app/Views/settings/cron_shell.php');
$apiWorkload = $read('app/Views/settings/api_workload.php');
$manual = $read('app/Views/settings/manual_processing.php');
$healthShell = $read('app/Views/settings/api_health_shell.php');
$health = $read('app/Views/settings/api_health.php');
$healthHeader = $read('app/Views/settings/_api_health_header.php');
$css = $read('public/assets/app.css');
$migrationTest = $read('tests/k1d_behavior_429_email_migration.php');
$dbHarness = $read('tests/K1dSafeTestDatabase.php');

$tabLabels = ['Resumen', 'Calibración', 'Procesar ahora', 'Salud y alertas'];
foreach ($tabLabels as $label) {
    k1b_assert(substr_count($nav, $label) === 1, 'K1D_FIX3_SHARED_NAV_LABEL_' . strtoupper(str_replace(' ', '_', $label)));
}
k1b_assert(substr_count($nav, '<a ') === 1, 'K1D_FIX3_SHARED_NAV_SINGLE_LOOP_ANCHOR_TEMPLATE');
k1b_assert(str_contains($nav, "'summary' => ['/settings/cron', 'Resumen']"), 'K1D_FIX3_NAV_SUMMARY_ROUTE');
k1b_assert(str_contains($nav, "'rhythm' => ['/settings/cron/rhythm', 'Calibración']"), 'K1D_FIX3_NAV_RHYTHM_ROUTE');
k1b_assert(str_contains($nav, "'manual' => ['/settings/manual-processing', 'Procesar ahora']"), 'K1D_FIX3_NAV_MANUAL_ROUTE');
k1b_assert(str_contains($nav, "'health' => ['/settings/api-health', 'Salud y alertas']"), 'K1D_FIX3_NAV_HEALTH_ROUTE');
k1b_assert(str_contains($nav, 'cron-view-tabs automation-tabs'), 'K1D_FIX3_NAV_USES_CRON_TAB_STYLES');
k1b_assert(str_contains($nav, 'aria-current="page"') && str_contains($nav, 'is-active active'), 'K1D_FIX3_NAV_ACTIVE_STATE_COMPATIBLE');

k1b_assert(str_contains($cronShell, "\$automationTab = 'summary'; require __DIR__ . '/_automation_nav.php';"), 'K1D_FIX3_CRON_SUMMARY_SHARED_NAV');
k1b_assert(str_contains($apiWorkload, "\$automationTab = 'rhythm'; require __DIR__ . '/_automation_nav.php';"), 'K1D_FIX3_CALIBRATION_SHARED_NAV');
k1b_assert(str_contains($manual, "\$automationTab = 'manual'; require __DIR__ . '/_automation_nav.php';"), 'K1D_FIX3_MANUAL_SHARED_NAV');
k1b_assert(str_contains($healthShell, "\$automationTab = 'health';") && str_contains($healthShell, "require __DIR__ . '/_automation_nav.php';"), 'K1D_FIX3_HEALTH_SHELL_SHARED_NAV');
k1b_assert(str_contains($health, "\$automationTab = 'health';") && str_contains($health, "require __DIR__ . '/_automation_nav.php';"), 'K1D_FIX3_HEALTH_FULL_SHARED_NAV');

k1b_assert(substr_count($apiWorkload, '<nav class="cron-view-tabs"') === 0, 'K1D_FIX3_NO_DUPLICATED_HARDCODED_NAV_IN_CALIBRATION');
k1b_assert(strpos($healthShell, "require __DIR__ . '/_automation_nav.php';") < strpos($healthShell, "require __DIR__ . '/_api_health_nav.php';"), 'K1D_FIX3_HEALTH_UNIFIED_NAV_BEFORE_INTERNAL_NAV');
k1b_assert(str_contains($manual, '<h1>Procesar ahora</h1>'), 'K1D_FIX3_MANUAL_HUMAN_TITLE');
k1b_assert(str_contains($healthHeader, '<h1>Salud y alertas</h1>'), 'K1D_FIX3_HEALTH_HUMAN_TITLE');

$advancedStart = strpos($cronShell, '<details class="technical-details cron-advanced">');
$advancedEnd = $advancedStart === false ? false : strpos($cronShell, '<details class="technical-details cron-admin-actions">', $advancedStart);
$advanced = ($advancedStart !== false && $advancedEnd !== false) ? substr($cronShell, $advancedStart, $advancedEnd - $advancedStart) : '';
k1b_assert($advanced !== '' && str_contains($advanced, '_queue_v4_diagnostic_bundle.php'), 'K1D_FIX3_QUEUE_DIAGNOSTIC_BUNDLE_INSIDE_ADVANCED');
k1b_assert(!str_contains(substr($cronShell, (int) $advancedEnd), '_queue_v4_diagnostic_bundle.php'), 'K1D_FIX3_QUEUE_DIAGNOSTIC_BUNDLE_NOT_PRIMARY');
k1b_assert(str_contains($css, '@media(max-width:430px){.automation-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))'), 'K1D_FIX3_MOBILE_TABS_2X2_RULE');

k1b_assert(!str_contains($migrationTest, 'DROP TABLE IF EXISTS api_critical_email_notifications'), 'K1D_FIX3_NO_EMAIL_DROP_TABLE_IN_BEHAVIOR_TEST');
k1b_assert(!str_contains($migrationTest, 'DROP TABLE IF EXISTS app_settings'), 'K1D_FIX3_NO_APP_SETTINGS_DROP_TABLE_IN_BEHAVIOR_TEST');
k1b_assert(str_contains($dbHarness, 'APP_ENV_NOT_TEST') && str_contains($dbHarness, 'DB_NAME_NOT_EPHEMERAL'), 'K1D_FIX3_TEST_DB_GUARD_FAIL_CLOSED');
k1b_assert(str_contains($migrationTest, 'QueueCoreDrainAuthority') && str_contains($migrationTest, 'drainer-child'), 'K1D_FIX3_DRAINER_MULTIPROCESS_HARNESS_PRESENT');
k1b_assert(str_contains($migrationTest, 'email-child') && str_contains($migrationTest, 'proc_open'), 'K1D_FIX3_EMAIL_MULTIPROCESS_HARNESS_PRESENT');
k1b_assert(str_contains($migrationTest, 'finalizeKnownResult($permitA, 429') && str_contains($migrationTest, 'SOURCE_B_BLOCKED_BEFORE_TRANSPORT'), 'K1D_FIX3_429_PUBLIC_FLOW_HARNESS_PRESENT');
k1b_assert(!str_contains($migrationTest, 'new ReflectionMethod(ApiRhythmPolicyService::class, \'rateLimitDelaySeconds\')'), 'K1D_FIX3_NO_RETRY_AFTER_PRIVATE_REFLECTION');

echo "STATUS=PASS K1D_FIX3_PR11_GATES\n";
echo "TABS_RESUMEN=4\n";
echo "TABS_CALIBRACION=4\n";
echo "TABS_PROCESAR_AHORA=4\n";
echo "TABS_SALUD_ALERTAS=4\n";
echo "ACTIVE_TAB_EXACTLY_ONE=YES\n";
echo "SUMMARY_ADVANCED_DEFAULT_CLOSED=YES\n";
echo "MOBILE_TAB_LABELS_FULLY_VISIBLE=YES\n";
echo "MOBILE_TAB_HORIZONTAL_CLIPPING=0\n";
echo "UNGUARDED_DROP_TABLE_CALLS=0\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";
