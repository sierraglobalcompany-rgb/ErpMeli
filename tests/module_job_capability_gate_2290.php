<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Modules\ModuleJobCapabilityGate;

$gate = new ModuleJobCapabilityGate();
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach (['meli-ads', 'meli-growth', 'meli-insights', 'meli-postsale'] as $moduleId) {
    $check(!$gate->allows($moduleId, 'snapshot_sync', []), $moduleId . ' no debe crear snapshots sin endpoints confirmados.');
    $check(!$gate->allows($moduleId, 'event_sync', ['resource_type' => 'order', 'resource_id' => '/orders/1']), $moduleId . ' no debe crear eventos remotos sin endpoint confirmado.');
}

$check($gate->allows('meli-logistics', 'snapshot_sync', []), 'Logistics debe poder usar el endpoint confirmado de envios.');
$check($gate->allows('meli-logistics', 'event_sync', ['resource_type' => 'shipment', 'resource_id' => '/shipments/123']), 'El envio exacto confirmado debe admitirse.');
$check(!$gate->allows('meli-logistics', 'event_sync', ['resource_type' => 'stock_location', 'resource_id' => '/stock-locations/123']), 'Stock location no confirmado no debe encolarse.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK module_job_capability_gate_2290\n";
