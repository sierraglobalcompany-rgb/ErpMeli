<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$version = trim((string) file_get_contents($root . '/VERSION'));
$manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
$assert(version_compare($version, '2.28.59', '>='), 'VERSION debe conservar la base 2.28.59 o una versión posterior.');
$assert(is_array($manifest), 'El manifiesto debe ser JSON válido.');
$assert(($manifest['version'] ?? null) === $version, 'La versión del manifiesto no coincide.');
$assert(is_string($manifest['build_id'] ?? null) && (string) $manifest['build_id'] !== '', 'Build ID inesperado.');
$minimumMigration = (string) ($manifest['minimum_migration'] ?? '');
$assert((int) $minimumMigration >= 239, 'Migración mínima inesperada.');

foreach ([
    '215_cron_capacity_producer_contract_2_28_35.sql',
    '216_historical_intervention_scoped_ack_2_28_36.sql',
    '217_progressive_read_models_retention_2_28_37.sql',
    '218_cron_useful_execution_campaign_source_isolation_2_28_38.sql',
    '219_operational_snapshots_health_retention_2_28_39.sql',
    '220_cron_doctor_blocker_map_2_28_40.sql',
    '221_cron_readonly_fast_shell_2_28_41.sql',
    '222_http_rhythm_rolling_window_2_28_42.sql',
    '223_incremental_drainable_scheduler_2_28_43.sql',
    '224_campaign_executable_projection_2_28_44.sql',
    '225_queue_batch_contracts_2_28_45.sql',
    '226_actionable_cron_history_2_28_46.sql',
    '227_cron_retention_rollups_2_28_47.sql',
    '228_cron_certification_gate_2_28_48.sql',
    '229_cron_doctor_clean_graph_2_28_49.sql',
    '230_clean_local_cron_replay_2_28_50.sql',
    '231_true_cron_counters_2_28_51.sql',
    '232_capacity_resource_planner_2_28_52.sql',
    '233_non_destructive_http_rhythm_2_28_53.sql',
    '234_campaign_executable_truth_2_28_54.sql',
    '235_exact_queue_contracts_2_28_55.sql',
    '236_actionable_history_resolution_2_28_56.sql',
    '237_fast_snapshot_panels_2_28_57.sql',
    '238_cron_retention_final_certification_2_28_58.sql',
    '239_sales_detail_item_currency_recovery_2_28_59.sql',
] as $migration) {
    $path = $root . '/database/migrations/' . $migration;
    $assert(is_file($path), 'Falta migración acumulativa: ' . $migration);
    if (!is_file($path)) {
        continue;
    }
    $sql = (string) file_get_contents($path);
    $assert(!preg_match('/\b(?:UPDATE|DELETE\s+FROM|TRUNCATE)\s+(?:orders|order_items|payments|shipments|packs|meli_accounts|companies)\b/i', $sql), 'La migración toca datos comerciales: ' . $migration);
}

foreach (($manifest['components'] ?? []) as $name => $component) {
    $path = $root . '/' . ($component['path'] ?? '');
    $assert(is_file($path), 'Falta componente del manifiesto: ' . $name);
    if (is_file($path)) {
        $assert(hash_file('sha256', $path) === ($component['sha256'] ?? ''), 'Hash inválido: ' . $name);
    }
}

$routes = (string) file_get_contents($root . '/public/index.php');
$assert(str_contains($routes, '/settings/api-health/section.html'), 'Falta la ruta progresiva de Salud API.');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$assert(str_contains($controller, 'apiHealthSection'), 'Falta el controlador progresivo de Salud API.');
$assert(str_contains($controller, "'snapshot_state' => 'partial'"), 'El shell Cron debe declarar estado parcial, no ceros autoritativos.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL release_22837_contract\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS release_22837_contract\n";
