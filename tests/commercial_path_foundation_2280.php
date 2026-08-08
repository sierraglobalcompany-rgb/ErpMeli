<?php

declare(strict_types=1);

putenv('APP_KEY=base64:' . base64_encode(str_repeat('k', 32)));
putenv('ML_WRITE_ENABLED=false');

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CommercialPathReadinessService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = new CommercialPathReadinessService();
$snapshot = $service->snapshot();

$assert($snapshot['version'] === '2.28.1', 'La versión comercial debe ser 2.28.1.');
$assert($snapshot['remote_writes_allowed'] === false, 'La Fase 0 no puede habilitar escrituras remotas.');
$assert($snapshot['web_remote_transport_allowed'] === false, 'Las vistas web no pueden transportar Mercado Libre.');
$assert($snapshot['api_map_required'] === true, 'Los endpoints deben requerir mapa API confirmado.');
$assert(count($snapshot['phases']) === 7, 'La matriz comercial debe tener Fase 0 a Fase 6.');
$assert($snapshot['phases'][0]['key'] === 'foundation', 'La primera fase debe ser foundation.');
$assert($snapshot['phases'][0]['enabled'] === true, 'La Fase 0 debe quedar lista por defecto.');
$assert($snapshot['next_phase'] !== null && $snapshot['next_phase']['key'] === 'order_change_sweep', 'La siguiente fase debe ser barrido incremental.');

$override = [
    'commercial_path.phase0_foundation_ready' => '1',
    'commercial_path.coverage_temporal_enabled' => '1',
    'commercial_path.order_change_sweep_enabled' => '0',
    'commercial_path.rehydrate_existing_orders_enabled' => '0',
    'commercial_path.financial_retry_v2_enabled' => '0',
    'commercial_path.item_scroll_continuity_enabled' => '0',
    'commercial_path.materialized_pacing_enabled' => '0',
    'commercial_path.remote_writes_allowed' => '0',
    'commercial_path.web_views_remote_transport_allowed' => '0',
    'commercial_path.require_api_map_confirmed_endpoints' => '1',
];
$advanced = $service->snapshot($override);
$assert($advanced['next_phase'] !== null && $advanced['next_phase']['key'] === 'order_change_sweep', 'Al aprobar cobertura, la siguiente fase debe ser barrido incremental.');

echo "PASS commercial_path_foundation_2280\n";
