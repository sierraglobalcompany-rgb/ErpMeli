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
$assert(substr_count($readModel, 'self::isCurrent($pdo)') === 4, 'stale_materialized_read_model_gate_missing');
$assert(str_contains($health, 'Web/API Health is materialized-only'), 'web_raw_telemetry_fallback_reintroduced');
$assert(str_contains($controller, 'no está al día') && str_contains($controller, 'No se mostrará como vacío'), 'stale_incident_catalogue_message_missing');
$assert(strpos($scheduler, 'QueueV4CleanMaintenanceService())->run(200)') < strpos($scheduler, 'QueueV4CleanWorker($this->pdo, $repository))->run'), 'incident_materializer_can_be_starved_by_worker');

echo "PASS api_incident_materializer_visibility_23915\n";
