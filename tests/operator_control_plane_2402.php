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
$apiHealth = $read('app/Services/ApiHealthService.php');
$apiIncidents = $read('app/Views/settings/api_health_incidents.php');

$assert(str_contains($syncController, 'sectionData('), 'sync_must_have_bounded_panel_data_method');
$assert(str_contains($syncController, 'panel') && str_contains($syncController, 'sync/panel'), 'sync_section_must_render_panel_view');
$assert(str_contains($syncView, "['summary', 'sync-summary'"), 'sync_summary_panel_missing');
$assert(str_contains($syncView, "['queue', 'sync-queue'"), 'sync_queue_panel_missing');
$assert(str_contains($syncView, "['finance', 'sync-finance'"), 'sync_finance_panel_missing');
$assert(str_contains($syncView, "['months', 'sync-months'"), 'sync_months_panel_missing');
$assert(str_contains($syncView, "['recent', 'sync-recent'"), 'sync_recent_panel_missing');
$assert(substr_count($syncView, "data-async-section") >= 1 && str_contains($syncView, 'data-timeout-ms'), 'sync_progressive_sections_must_have_timeout_budget');
$assert(str_contains($syncPanel, 'Resumen operativo') && str_contains($syncPanel, 'Cola financiera'), 'sync_panel_view_incomplete');

$assert(str_contains($performance, "section.dataset.timeoutMs") && !str_contains($performance, 'superó 8 segundos'), 'performance_timeout_must_be_section_budgeted');
$assert(str_contains($performance, "if (panel && !url.searchParams.has('panel'))"), 'performance_must_preserve_panel_when_search_changes');

$assert(str_contains($manual, 'Una entrada humana, un estado y una acción primaria'), 'manual_processing_must_explain_single_control_plane');
$assert(str_contains($manual, 'data-manual-legacy-campaign') && str_contains($manual, 'Opciones avanzadas de selección exacta'), 'manual_legacy_campaign_must_be_demoted_to_advanced_options');
$assert(str_contains($manual, 'Procesar siguiente trabajo'), 'manual_primary_action_must_be_clear');

$assert(str_contains($apiHealth, "\$this->incidentProtocol = 'complete';"), 'api_health_incident_protocol_must_reset_per_call');
$assert(str_contains($apiHealth, "\$this->dataAvailable = false;") && str_contains($apiHealth, "unavailable_summary_only"), 'api_health_remote429_zero_must_not_silently_look_empty');
$assert(str_contains($apiIncidents, 'Telemetría directa no agrupada') && str_contains($apiIncidents, 'Catálogo no certificado'), 'api_incident_view_must_label_degraded_or_uncertified_data');

echo "PASS operator_control_plane_2402\n";
