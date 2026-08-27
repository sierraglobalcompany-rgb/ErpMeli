<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
};

$assert(trim($read('VERSION')) === '2.40.2', 'version_must_be_2402');

$syncController = $read('app/Controllers/SyncController.php');
$syncView = $read('app/Views/sync/index.php');
$syncPanel = $read('app/Views/sync/panel.php');
$performance = $read('public/assets/performance.js');
$manual = $read('app/Views/settings/manual_processing.php');
$manualService = $read('app/Services/ManualProcessingService.php');
$settingsController = $read('app/Controllers/SettingsController.php');
$apiHealth = $read('app/Services/ApiHealthService.php');
$apiIncidents = $read('app/Views/settings/api_health_incidents.php');
$cronView = $read('app/Views/settings/cron.php');
$backlogCounter = $read('app/Services/BacklogCounterPresenter.php');

$assert(str_contains($syncController, 'sectionData('), 'sync_must_have_bounded_panel_data_method');
$assert(str_contains($syncController, 'panel') && str_contains($syncController, 'sync/panel'), 'sync_section_must_render_panel_view');
$assert(str_contains($syncView, "['summary', 'sync-summary'"), 'sync_summary_panel_missing');
$assert(str_contains($syncView, "['queue', 'sync-queue'"), 'sync_queue_panel_missing');
$assert(str_contains($syncView, "['finance', 'sync-finance'"), 'sync_finance_panel_missing');
$assert(str_contains($syncView, "['months', 'sync-months'"), 'sync_months_panel_missing');
$assert(str_contains($syncView, "['recent', 'sync-recent'"), 'sync_recent_panel_missing');
$assert(substr_count($syncView, "data-async-section") >= 1 && str_contains($syncView, 'data-timeout-ms'), 'sync_progressive_sections_must_have_timeout_budget');
$assert(str_contains($syncPanel, 'Resumen operativo') && str_contains($syncPanel, 'Cola financiera'), 'sync_panel_view_incomplete');
$assert(str_contains($syncView, 'Cuenta Mercado Libre') && str_contains($syncView, 'name="year"'), 'sync_progressive_shell_must_show_account_and_year_selectors');
$assert(str_contains($syncController, "'accounts' => \$accounts") && str_contains($syncController, "\$accounts = \$service->accounts();"), 'sync_progressive_accounts_must_be_loaded_without_full_index_data');
$progressiveBranch = substr($syncController, strpos($syncController, 'if ((string) ($_GET[\'full\'] ?? \'\') !== \'1\') {') ?: 0, 520);
$assert(str_contains($progressiveBranch, "\$accounts = \$service->accounts();") && !str_contains($progressiveBranch, 'indexData('), 'sync_progressive_shell_must_not_load_full_index_data');
$assert(!str_contains($syncPanel, '>V4_AUTOMATIC<') && !str_contains($syncPanel, 'Queue V4;'), 'sync_normal_ui_must_not_show_internal_queue_jargon');
$summaryStart = strpos($syncPanel, "if (\$panel === 'summary'):");
$summaryEnd = strpos($syncPanel, "<?php elseif (\$panel === 'queue'):", $summaryStart);
$summaryPanel = $summaryStart === false || $summaryEnd === false ? '' : substr($syncPanel, $summaryStart, $summaryEnd - $summaryStart);
$assert(substr_count($summaryPanel, 'Procesar ahora') === 0, 'sync_summary_panel_must_not_duplicate_top_primary_action');

$assert(str_contains($performance, "section.dataset.timeoutMs") && !str_contains($performance, 'superó 8 segundos'), 'performance_timeout_must_be_section_budgeted');
$assert(str_contains($performance, "if (panel && !url.searchParams.has('panel'))"), 'performance_must_preserve_panel_when_search_changes');

$assert(str_contains($manual, 'Una entrada humana, un estado y una acción primaria'), 'manual_processing_must_explain_single_control_plane');
$assert(str_contains($manual, 'manual-journey') && str_contains($manual, 'data-manual-selection'), 'manual_must_present_one_human_journey_and_selection_panel');
$assert(str_contains($manual, 'Listos ahora') && str_contains($manual, 'Esperando') && str_contains($manual, 'Revisión') && str_contains($manual, 'Requiere atención'), 'manual_processing_must_show_truth_counters');
$assert(str_contains($manual, '$manualQueueSnapshot') && str_contains($manual, '$counterValue'), 'manual_counters_must_have_initial_snapshot_not_preview_only');
$assert(str_contains($manualService, 'function initialSnapshot(') && !str_contains($settingsController, '->preview($scope, $accountId ?: null);'), 'manual_initial_counters_must_not_call_preview');
$assert(str_contains($manual, 'Preparar siguiente paso') && str_contains($manual, 'Procesar siguiente trabajo'), 'manual_primary_action_must_be_clear');
$assert(str_contains($manual, 'No hay un segundo botón de ejecución compitiendo con el drenador'), 'manual_must_declare_single_visible_execution_owner');
$assert(!str_contains($manual, '/settings/manual-processing/start'), 'manual_ui_must_not_expose_legacy_campaign_start_as_second_flow');
$assert(!str_contains($manual, '<span class="badge neutral">MANUAL_EXACT</span>') && !str_contains($manual, 'trabajador V4'), 'manual_must_not_show_internal_queue_jargon');
$assert(!str_contains($manual, 'Endpoint K10') && !str_contains($manual, 'Queue V4 boundary'), 'manual_must_not_show_internal_endpoint_jargon');
$assert(!str_contains($manual, 'data-manual-legacy-campaign') && !str_contains($manual, 'Opciones avanzadas de selección exacta'), 'manual_legacy_campaign_must_be_replaced');
$assert(substr_count($manual, '<section class="panel manual-panel manual-selection-panel"') === 1, 'manual_selection_panel_must_not_be_nested_or_duplicated');

$assert(str_contains($cronView, 'Autoridad de automatización') && str_contains($cronView, 'Lanzador físico esperado'), 'cron_must_separate_logical_state_from_physical_launcher');
$assert(str_contains($cronView, 'NO_CERTIFICADO') && str_contains($cronView, 'heartbeat reciente no equivale'), 'cron_must_not_treat_heartbeat_as_physical_cron_proof');
$assert(str_contains($cronView, '$runtimePhysicalState') && !str_contains($cronView, "'Verificada'"), 'cron_physical_evidence_must_use_explicit_observed_state');

foreach (['ACTIONABLE_NOW', 'WAITING_POLICY', 'REVIEW_REQUIRED', 'ERROR', 'NON_ACTIONABLE'] as $bucket) {
    $assert(str_contains($backlogCounter, $bucket), 'shared_backlog_counter_missing_' . $bucket);
}

$assert(str_contains($apiHealth, "\$this->incidentProtocol = 'complete';"), 'api_health_incident_protocol_must_reset_per_call');
$assert(str_contains($apiHealth, "\$this->dataAvailable = false;") && str_contains($apiIncidents, "unavailable_summary_only"), 'api_health_remote429_zero_must_not_silently_look_empty');
$assert(str_contains($apiIncidents, 'Telemetría directa no agrupada') && str_contains($apiIncidents, 'Catálogo no certificado'), 'api_incident_view_must_label_degraded_or_uncertified_data');

echo "PASS operator_control_plane_2402\n";
