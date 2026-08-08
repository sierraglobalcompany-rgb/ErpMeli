<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$adapter = $read('app/Services/CronV3LegacyQueueAdapter.php');
$matrix = $read('app/Services/CronV3CapabilityMatrixService.php');

foreach (['orders_search_page', 'items_search_page', 'catalog_description_exact'] as $type) {
    $assert(str_contains($adapter, "'{$type}' => ["), 'Falta importador legacy V3 para ' . $type);
    $assert(str_contains($matrix, "'{$type}'"), 'La matriz debe declarar work_type ' . $type);
}

$assert(str_contains($adapter, 'sync_batch_chunks'), 'orders_sync debe importar desde sync_batch_chunks.');
$assert(str_contains($adapter, 'meli_item_sync_jobs'), 'items_sync debe importar desde meli_item_sync_jobs.');
$assert(str_contains($adapter, 'catalog_description_job_items'), 'catalog_descriptions debe importar ítems exactos.');
$assert(str_contains($matrix, "'manual_campaign' => ["), 'La campaña dirigida debe quedar visible en la matriz V3.');
$assert(str_contains($matrix, 'legacy_readonly_backlog'), 'La campaña/legacy debe mostrarse como solo diagnóstico, no como lista.');

$migration = $read('database/migrations/260_cron_v3_legacy_bridge_contracts_2_31_2.sql');
$assert(str_contains($migration, "('app.version', '2.31.2'"), 'La migración 260 debe registrar app.version 2.31.2.');
$assert(str_contains($migration, "'orders_sync','orders','remote','v3_active'"), 'La migración 260 debe actualizar orders_sync.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states)\b/i', $migration), 'La migración 260 no debe tocar datos comerciales.');

echo "PASS cron_v3_legacy_bridge_contracts_2312\n";
