<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$version = trim((string) file_get_contents($root . '/VERSION'));
$manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
$assert(version_compare($version, '2.29.7', '>='), 'VERSION debe ser 2.29.7 o superior.');
$assert(is_array($manifest) && ($manifest['version'] ?? null) === $version, 'Manifiesto y VERSION no coinciden.');
$buildId = (string) ($manifest['build_id'] ?? '');
$assert(str_starts_with($buildId, 'erp-meli-2.29.') || str_starts_with($buildId, 'erp-meli-2.30.') || str_starts_with($buildId, 'erp-meli-2.31.') || str_starts_with($buildId, 'erp-meli-2.32.') || str_starts_with($buildId, 'erp-meli-2.33.') || str_starts_with($buildId, 'erp-meli-2.34.') || str_starts_with($buildId, 'erp-meli-2.35.'), 'Build ID inesperado.');
$assert(in_array(($manifest['minimum_migration'] ?? null), [
    '251_asset_hash_stable_line_endings_2_29_7.sql',
    '252_migration_line_ending_drift_recovery_2_29_8.sql',
    '253_applied_migration_015_drift_recovery_2_29_9.sql',
    '254_cron_v3_canary_truth_2_29_10.sql',
    '255_cron_v3_active_evidence_truth_2_29_11.sql',
    '256_cron_v3_certified_cutover_2_29_12.sql',
    '257_cron_v3_operational_cutover_2_30_0.sql',
    '258_cron_v3_capability_matrix_2_31_0.sql',
    '259_cron_v3_single_operational_truth_2_31_1.sql',
    '260_cron_v3_legacy_bridge_contracts_2_31_2.sql',
    '261_cron_v3_history_drainage_certification_2_31_3.sql',
    '262_cron_v3_visual_truth_api_health_2_31_4.sql',
    '263_cron_v3_queue_capability_owners_2_31_5.sql',
    '264_cron_v3_history_drainage_v3_truth_2_31_6.sql',
    '265_cron_v3_full_operational_drainage_2_32_0.sql',
    '268_cron_v3_fifo_operational_snapshot_2_33_0.sql',
    '273_cron_v3_parking_action_center_2_34_0.sql',
    '274_clean_release_api_health_429_2_34_1.sql',
    '275_recover_274_app_settings_key_2_34_2.sql',
    '276_emergency_v3_api_start_without_canary_2_34_3.sql',
    '277_release_integrity_text_hash_recovery_2_34_4.sql',
    '278_cron_v3_fifo_drainage_truth_2_35_0.sql',
    '279_cron_v3_fifo_legacy_finalizer_2_35_1.sql',
], true), 'Migración mínima inesperada.');

foreach ([
    'database/migrations/248_cron_v3_canary_control_2_29_4.sql',
    'database/migrations/249_cron_v3_canary_actionable_ui_2_29_5.sql',
    'database/migrations/250_updater_safe_transition_2_29_6.sql',
    'database/migrations/251_asset_hash_stable_line_endings_2_29_7.sql',
    'database/migrations/252_migration_line_ending_drift_recovery_2_29_8.sql',
    'database/migrations/253_applied_migration_015_drift_recovery_2_29_9.sql',
    'app/Services/CronV3CanaryControlService.php',
    'app/Services/ReleaseIntegrityService.php',
    'app/Recovery/RecoveryKernel.php',
    'public/assets/app.js',
    'public/assets/app.css',
] as $required) {
    $assert(is_file($root . '/' . $required), 'Falta runtime ' . $required);
}

foreach (($manifest['components'] ?? []) as $name => $component) {
    $path = $root . '/' . (string) ($component['path'] ?? '');
    $assert(is_file($path), 'Falta componente ' . $name);
    $assert(hash_file('sha256', $path) === ($component['sha256'] ?? ''), 'Hash inválido ' . $name);
}

$assert(isset($manifest['components']['frontend_app_js'], $manifest['components']['frontend_app_css']), 'El manifest debe firmar assets del panel.');
$assert(isset($manifest['components']['release_integrity'], $manifest['components']['recovery_kernel']), 'El manifest debe firmar el actualizador corregido.');

echo "release_2297_contract_ok\n";











