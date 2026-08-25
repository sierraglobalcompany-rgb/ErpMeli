<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$runtime = $source('app/Services/AutomationRuntimeStatusService.php');
$assert(str_contains($runtime, "'read_only' => true"), 'automation_status_must_declare_read_only');
$assert(str_contains($runtime, "'health_authority' => QueueV4CleanReadinessService::class"), 'existing_health_authority_not_reused');
$assert(str_contains($runtime, "'legacy_state_consulted' => false"), 'health_must_not_consult_legacy_state');
foreach ([
    'physical_cron',
    'canonical_fifo',
    'drain_authority',
    'oauth',
    'notifications_k2',
    'finance_k3',
    'api_rhythm_k4',
    'legacy_compatibility',
] as $authority) {
    $assert(str_contains($runtime, "'" . $authority . "' => ["), 'missing_k5_authority:' . $authority);
}
$assert(str_contains($runtime, 'jobs/queue_v4_clean.php --runtime=45 --max-jobs=3'), 'single_physical_cron_not_declared');
$assert(!str_contains($runtime, 'process_sync_queue.php --runtime'), 'legacy_cron_declared_in_health');
$assert(!str_contains($runtime, "'status' => 'PRIMARY'"), 'topology_must_not_report_primary_as_live_health');
$assert(!str_contains($runtime, "'status' => 'INSIDE_SINGLE_LAUNCHER'"), 'topology_must_not_report_launcher_membership_as_live_health');
$assert(substr_count($runtime, "'status' => 'TOPOLOGY_ONLY'") >= 3, 'topology_only_authorities_not_labeled');
$assert(str_contains($runtime, "'live_state_authority' => 'CronApiRiskSummaryService'"), 'api_rhythm_live_state_authority_missing');

$controller = $source('app/Controllers/SettingsController.php');
$cronStatusAt = strpos($controller, 'public function cronStatus(): void');
$cronStatus = $cronStatusAt === false ? '' : substr($controller, $cronStatusAt, 2200);
$assert(str_contains($cronStatus, 'releaseReadOnlySession()'), 'cron_status_must_be_read_only_surface');
$assert(str_contains($cronStatus, 'AutomationRuntimeStatusService'), 'cron_status_must_use_existing_health_authority');
$assert(!str_contains($cronStatus, 'begin(') && !str_contains($cronStatus, 'finish('), 'cron_status_must_not_write_health_rows');

$testCronAt = strpos($controller, 'public function testCron(): void');
$testCron = $testCronAt === false ? '' : substr($controller, $testCronAt, 4200);
$assert(str_contains($testCron, '/jobs/queue_v4_clean.php'), 'web_preflight_must_check_v4_launcher');
$assert(str_contains($testCron, "'probe' => 'queue_v4_read_only'"), 'web_preflight_must_identify_read_only_probe');
$assert(!str_contains($testCron, 'process_sync_queue') && !str_contains($testCron, 'CronHealthService()->begin'), 'web_preflight_must_not_start_legacy_or_health_run');

$cronShell = $source('app/Views/settings/cron_shell.php');
$assert(str_contains($cronShell, 'Queue V4') && str_contains($cronShell, 'Legado fuera de autoridad'), 'cron_shell_must_show_v4_and_legacy_boundary');
$assert(str_contains($cronShell, 'Riesgos API · últimos 30 días'), 'cron_shell_must_show_api_risk_card');
$assert(str_contains($cronShell, 'data-app-base'), 'cron_risk_links_must_preserve_app_base');
$assert(!str_contains($cronShell, 'process_sync_queue.php'), 'cron_shell_must_not_advertise_legacy_launcher');

$appJs = $source('public/assets/app.js');
$assert(str_contains($appJs, 'timeoutMs = 8000'), 'health_async_timeout_missing');
$assert(str_contains($appJs, "cache: 'no-store'"), 'risk_card_must_avoid_stale_cache');
$assert(str_contains($appJs, 'return `${appBase}${path}`;'), 'risk_card_links_must_not_escape_app_prefix');

$inventory = $source('app/Services/RuntimeProcessInventoryService.php');
$assert(str_contains($inventory, "if (\$name === 'queue_v4_clean.php')"), 'inventory_must_special_case_current_launcher');
$assert(str_contains($inventory, "'process_sync_queue.php', 'cron_v3_local.php', 'cron_v3_remote.php', 'cron_v4.php'"), 'inventory_must_classify_known_legacy');
$assert(str_contains($inventory, "str_starts_with(\$name, 'queue_core_')"), 'inventory_must_classify_queue_core_by_behavior');

$legacy = $source('jobs/process_sync_queue.php');
$assert(str_contains($legacy, 'reason=LEGACY_AUTOMATION_RETIRED'), 'process_sync_queue_normal_invocation_not_retired');
$assert(strpos($legacy, '$blockedFlags') !== false
    && strpos($legacy, "require __DIR__ . '/_bootstrap.php'") !== false
    && strpos($legacy, '$blockedFlags') < strpos($legacy, "require __DIR__ . '/_bootstrap.php'"),
    'legacy_mutating_flags_must_block_before_bootstrap');

$deleteCandidates = [
    'jobs/process_sync_queue.php',
    'jobs/cron_v4.php',
    'jobs/queue_core_canary.php',
    'jobs/queue_core_convergence.php',
    'jobs/queue_core_deploy_check.php',
    'jobs/queue_core_health_snapshot.php',
    'jobs/queue_core_historical.php',
    'jobs/queue_core_preflight.php',
    'jobs/queue_core_release_certify.php',
    'jobs/queue_core_rollback.php',
];
foreach ($deleteCandidates as $candidate) {
    $assert(is_file($root . '/' . $candidate), 'candidate_missing_without_delete_proof:' . $candidate);
    $matches = [];
    exec('git -C ' . escapeshellarg($root) . ' grep -n ' . escapeshellarg($candidate) . ' -- .', $matches);
    $assert(count($matches) > 0, 'candidate_has_no_source_trace:' . $candidate);
}

$manifest = $source('resources/runtime-manifest.json');
$assert(str_contains($manifest, '"path": "jobs/queue_v4_clean.php"'), 'runtime_manifest_missing_current_launcher');
foreach ($deleteCandidates as $candidate) {
    $assert(str_contains($manifest, '"path": "' . $candidate . '"'), 'manifest_drift_without_delete_wave:' . $candidate);
}

foreach ([
    'docs/kiss/ERP_EXECUTION_INDEX.md',
    'docs/kiss/ERP_WORK_CATALOG.md',
    'docs/kiss/ERP_CHANGE_SOP.md',
    'docs/kiss/KISS_DELETION_BACKLOG.md',
] as $doc) {
    $contents = $source($doc);
    $assert(str_contains($contents, 'K5'), 'durable_doc_missing_k5_note:' . $doc);
}

$releaseSuite = $source('tests/current_release_suite_23919.php');
$assert(str_contains($releaseSuite, 'k5_health_legacy_delete_23919.php'), 'current_release_suite_missing_k5_test');

fwrite(STDOUT, 'K5_HEALTH_LEGACY_DELETE_23919=PASS checks=' . $checks
    . ' health_business_mutations=0 health_queue_mutations=0 health_remote_http=0 files_deleted=0' . PHP_EOL);
