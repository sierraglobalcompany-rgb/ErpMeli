<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\ApiExecutionMetadataContext;
use App\Services\CronBacklogSnapshotService;
use App\Services\CronWorkOutcome;
use App\Services\MeliApiClient;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(CronBacklogSnapshotService::equationState(100, 12, 10, 102) === 'complete',
    'La ecuación 100 + 12 - 10 = 102 debe quedar completa.');
$assert(CronBacklogSnapshotService::equationState(100, 12, 10, 103) === 'partial',
    'Una diferencia no explicada debe quedar parcial.');
$assert(CronBacklogSnapshotService::equationState(100, null, 10, 90) === 'unavailable',
    'Una entrada desconocida no puede convertirse en cero.');

$older = ['measurement_state' => 'complete', 'coverage_signature' => 'a:complete:total', 'total_pending' => 100];
$newer = ['measurement_state' => 'complete', 'coverage_signature' => 'a:complete:total', 'total_pending' => 90];
$trend = CronBacklogSnapshotService::windowTrend($older, $newer);
$assert($trend['trend'] === 'draining' && $trend['delta'] === -10 && $trend['comparable'],
    'La tendencia comparable de 15/60 minutos no se calculó correctamente.');
$unknown = CronBacklogSnapshotService::windowTrend($older, array_merge($newer, ['coverage_signature' => 'b:partial:eligible_only']));
$assert($unknown['trend'] === 'unknown' && !$unknown['comparable'],
    'Coberturas distintas no deben producir una tendencia falsa.');

foreach (['billing_error' => 'retry', 'db_connection' => 'retry', 'api_limit' => 'waiting_rhythm'] as $reason => $expected) {
    $outcome = CronWorkOutcome::normalize(['stop_reason' => $reason], 0, 1);
    $assert(($outcome['status'] ?? '') === $expected, $reason . ' no fue clasificado como espera automática.');
    $assert(($outcome['errors'] ?? -1) === 0 && ($outcome['deferred'] ?? 0) === 1,
        $reason . ' todavía consume un error o no conserva el aplazamiento.');
}

ApiExecutionMetadataContext::resetRemoteDispatchCount();
ApiExecutionMetadataContext::markRemoteDispatched();
ApiExecutionMetadataContext::markRemoteResponseKnown(17);
$assert(ApiExecutionMetadataContext::remoteDispatchCount() === 1, 'Se perdió el transporte iniciado.');
$assert(ApiExecutionMetadataContext::knownResponseCount() === 1, 'Se mezcló respuesta conocida con transporte.');
$assert(ApiExecutionMetadataContext::resourcesReceivedCount() === 17, 'Se perdió el número de recursos recibidos.');

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/212_http_resource_backlog_health_truth_2_28_32.sql');
$snapshot = (string) file_get_contents($root . '/app/Services/CronBacklogSnapshotService.php');
$telemetry = (string) file_get_contents($root . '/app/Services/MeliOperationTelemetryService.php');
$client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
$billingImporter = (string) file_get_contents($root . '/app/Services/OrderBillingImportService.php');
$saleFinancial = (string) file_get_contents($root . '/app/Services/SaleFinancialService.php');
$overview = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');

foreach (['previous_pending','newly_discovered','deduplicated','finalized','current_pending','http_dispatched','known_responses','resources_received'] as $field) {
    $assert(str_contains($migration, $field), 'La migración no incluye ' . $field . '.');
}
$assert(str_contains($migration, 'api_health_correction_events'), 'Falta el evento correctivo auditable de Salud API.');
$assert(!str_contains($migration, 'UPDATE api_request_logs'), 'La corrección no debe reescribir el histórico API.');
$assert(str_contains($snapshot, 'trendWindows()') && str_contains($snapshot, 'equation_state'),
    'El backend no publica tendencias honestas de 15/60 minutos o la ecuación del backlog.');
$assert(str_contains($snapshot, "'newly_discovered' => null") && str_contains($snapshot, "'deduplicated' => null"),
    'El backlog inventa ceros cuando no midió entradas o deduplicación.');
$assert(str_contains($snapshot, 'CronProducerMetricService')
    && !str_contains($snapshot, '$netEntries = $current - $previous'),
    'El backlog debe usar entradas declaradas por productores y no una diferencia algebraica circular.');
$assert(!str_contains($telemetry, "response_item_count'] ?? 1"),
    'La telemetría infla cada HTTP conocido a un recurso.');
$assert(str_contains($client, 'responseItemCount($decoded, $meta, $status)'),
    'El cliente no cuenta recursos desde la respuesta conocida.');
$assert(str_contains($client, 'response_count_strategy') && str_contains($client, "=== 'billing_orders'")
    && str_contains($client, 'billingOrderCount($decoded,'),
    'Billing agrupado no activa el conteo de órdenes recibidas.');

$clientReflection = new ReflectionClass(MeliApiClient::class);
$clientInstance = $clientReflection->newInstanceWithoutConstructor();
$counter = $clientReflection->getMethod('responseItemCount');
$billingResources = $counter->invoke($clientInstance, [
    ['order_id' => '1001', 'details' => [['amount' => 10]]],
    ['orderId' => '1002'],
    ['order_id' => '1001', 'details' => [['amount' => 20]]],
    ['order_id' => '9999'],
], ['response_count_strategy' => 'billing_orders', 'expected_resource_ids' => '1001,1002,1003'], 200);
$assert($billingResources['count'] === 2 && $billingResources['state'] === 'complete' && $billingResources['unit'] === 'orders',
    'Billing no intersecta y deduplica las órdenes realmente recibidas.');
$packResources = $counter->invoke($clientInstance, ['id' => 88, 'orders' => [['id' => 1], ['id' => 2]]], ['operation_key' => 'pack_exact'], 200);
$assert($packResources['count'] === 1 && $packResources['unit'] === 'packs', 'Un pack exacto se confundió con sus órdenes hijas.');
$claimResources = $counter->invoke($clientInstance, ['data' => [['id' => 1], ['id' => 2]]], ['operation_key' => 'claims_search'], 200);
$assert($claimResources['count'] === 2 && $claimResources['unit'] === 'claims', 'Claims no usa su colección certificada data.');
$pendingResources = $counter->invoke($clientInstance, ['status' => 'processing', 'details' => [['order_id' => '1001']]], [
    'response_count_strategy' => 'billing_orders', 'expected_resource_ids' => '1001',
], 200);
$assert($pendingResources['count'] === 0 && $pendingResources['state'] === 'partial', 'Una respuesta processing se presentó como recurso completo.');
$partialResources = $counter->invoke($clientInstance, ['results' => [['id' => 1]]], ['operation_key' => 'orders_search'], 206);
$assert($partialResources['count'] === 1 && $partialResources['state'] === 'partial', 'HTTP 206 se presentó como respuesta completa.');
$unknownResources = $counter->invoke($clientInstance, ['metadata' => ['ok' => true]], ['operation_key' => 'unknown_read'], 200);
$assert($unknownResources['count'] === 0 && $unknownResources['state'] === 'unknown', 'Un endpoint no certificado se convirtió en recurso recibido.');
$assert(str_contains($billingImporter, "'response_count_strategy' => 'billing_orders'")
    && str_contains($saleFinancial, "'response_count_strategy' => 'billing_orders'"),
    'Los dos consumidores Billing no comparten el contador certificado.');
$assert(str_contains($overview, 'snapshot_generation') && str_contains($overview, "'corrected_successful'"),
    'Salud API no comparte generación o no separa correcciones históricas.');
$assert(str_contains($health, 'raw_successful') && str_contains($health, 'api_health_correction_events'),
    'La tasa cruda y la tasa operativa todavía están mezcladas.');

echo "PASS http_resource_backlog_health_truth_22832\n";
