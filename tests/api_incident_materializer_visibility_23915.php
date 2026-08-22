<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Services/ApiIncidentMaterializerService.php';

$service = new ReflectionClass(App\Services\ApiIncidentMaterializerService::class);
$clip = $service->getMethod('clip');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert($clip->invoke(null, str_repeat('x', 501), 500) === str_repeat('x', 500), 'legacy_safe_message_not_bounded');
$assert($clip->invoke(null, str_repeat('e', 121), 120) === str_repeat('e', 120), 'legacy_error_code_not_bounded');
$assert($clip->invoke(null, '   ', 80) === null, 'blank_read_model_value_not_normalized');

$readModel = (string) file_get_contents($root . '/app/Services/ApiIncidentReadModelService.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$scheduler = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanScheduler.php');
$diagnostic = (string) file_get_contents($root . '/jobs/api_incident_materializer_diagnose.php');
$assert(substr_count($readModel, 'self::isCurrent($pdo)') === 4, 'stale_materialized_read_model_gate_missing');
$assert(str_contains($health, 'directIncidentFallbackSafe') && str_contains($health, 'EXPLAIN SELECT l.id') && str_contains($health, 'LIMIT 500'), 'bounded_direct_fallback_missing');
$assert(str_contains($health, "'degraded_direct'") && str_contains($health, 'min(50') && str_contains($health, 'LIMIT 500'), 'direct_fallback_not_explicitly_bounded');
$assert(str_contains($health, 'SQL_CALC_FOUND_ROWS ') && str_contains($health, 'count($rows)'), 'direct_fallback_must_not_count_unbounded_groups');
$assert(str_contains($controller, 'no está al día') && str_contains($controller, 'No se mostrará como vacío'), 'stale_incident_catalogue_message_missing');
$assert(strpos($scheduler, 'QueueV4CleanMaintenanceService())->run(200)') < strpos($scheduler, 'QueueV4CleanWorker($this->pdo, $repository))->run'), 'incident_materializer_can_be_starved_by_worker');
$assert(str_contains($diagnostic, 'SET SESSION TRANSACTION READ ONLY') && str_contains($diagnostic, 'production_mutations'), 'read_only_materializer_diagnostic_missing');
$assert(str_contains($diagnostic, 'diagnose($pdo)'), 'diagnostic_must_use_the_read_only_connection');
$assert(str_contains((string) file_get_contents($root . '/app/Services/ApiIncidentMaterializerService.php'), 'SAVEPOINT api_incident_row'), 'materializer_row_isolation_missing');
$assert(str_contains((string) file_get_contents($root . '/app/Services/ApiIncidentMaterializerService.php'), 'recordRejectedRow'), 'rejected_row_evidence_missing');
$assert(str_contains((string) file_get_contents($root . '/app/Views/settings/api_health_incidents.php'), 'Detalle al recuperar catálogo'), 'degraded_view_must_not_link_mutable_incident_detail');

echo "PASS api_incident_materializer_visibility_23915\n";
