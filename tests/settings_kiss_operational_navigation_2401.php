<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$settings = (string) file_get_contents($root . '/app/Views/settings/index.php');
$manual = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
$incidents = (string) file_get_contents($root . '/app/Views/settings/api_health_incidents.php');
$navigation = (string) file_get_contents($root . '/app/Repositories/NavigationRepository.php');

$assert(!str_contains($settings, 'Qué necesita hacer'), 'settings_center_must_not_duplicate_primary_navigation');
$assert(!str_contains($settings, 'data-primary-settings-area'), 'duplicate_primary_settings_area_marker_present');

$settingsTabsStart = strrpos($navigation, "['href' => '/settings'");
$settingsTabsEnd = $settingsTabsStart === false ? false : strpos($navigation, '    private function item', $settingsTabsStart);
$settingsTabs = ($settingsTabsStart === false || $settingsTabsEnd === false) ? '' : substr($navigation, $settingsTabsStart, $settingsTabsEnd - $settingsTabsStart);
$relatedLabels = [];
preg_match_all("/\\['href' => '\\/settings[^']*', 'label' => '([^']+)'/", $settingsTabs, $relatedLabels);
$assert(count($relatedLabels[1] ?? []) === 5, 'primary_related_nav_model_must_have_five_items');
foreach (['Centro', 'Configuración', 'Procesamiento', 'Salud', 'Sistema'] as $label) {
    $assert(in_array($label, $relatedLabels[1] ?? [], true), 'related_nav_label_missing:' . $label);
}
$assert(!preg_match('/\b(V2|V3|B2|B3|Queue Core|Cron V3|process_sync_queue)\b/', $settingsTabs), 'primary_nav_contains_legacy_jargon');
$assert(str_contains($settings, 'Procesamiento automático, diagnóstico y actualizaciones.'), 'system_section_summary_missing');
$assert(str_contains($settings, "'system' => ['Sistema'"), 'system_configuration_link_missing');

$assert(str_contains($manual, 'Procesamiento manual seguro'), 'manual_operator_title_missing');
$assert(str_contains($manual, 'MANUAL_EXACT'), 'manual_exact_classification_missing');
$assert(str_contains($manual, 'Máximo de HTTP físicos por tanda'), 'manual_profile_physical_http_label_missing');
$assert(str_contains($manual, 'Próxima ventana segura'), 'manual_next_safe_label_missing');
$assert(str_contains($manual, 'Detalle técnico avanzado'), 'advanced_technical_disclosure_missing');
$assert(strpos($manual, 'K10') > strpos($manual, 'Detalle técnico avanzado'), 'k10_must_be_advanced_not_primary');
$assert(strpos($manual, 'Queue V4 boundary') > strpos($manual, 'Detalle técnico avanzado'), 'queue_v4_boundary_must_be_advanced_not_primary');

$assert(str_contains($incidents, '$incidentLink'), 'incident_filter_link_builder_missing');
$assert(str_contains($incidents, "'hours' => 24"), 'incident_quick_link_hours_override_missing');
$assert(str_contains($incidents, "'origin' => 'remote'"), 'incident_remote_origin_link_missing');

fwrite(STDOUT, "SETTINGS_KISS_OPERATIONAL_NAVIGATION_2401=PASS\n");
