<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = new App\Services\CronV3CapabilityMatrixService(new class extends PDO {
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return false;
    }
});

$transferable = $service->transferableWorkTypes();
$blocked = $service->blockedWorkTypes();

foreach ([
    'notification_spool',
    'notification_normalize',
    'notification_backfill',
    'recurring_schedule',
    'pack_exact',
    'shipment_exact',
    'financial_recalc',
    'sale_billing_capture',
    'sales_audit_page',
    'sales_repair_exact',
] as $type) {
    $assert(in_array($type, $transferable, true), 'Debe conservar transferible certificado: ' . $type);
    $assert(!in_array($type, $blocked, true), 'Un tipo transferible no puede quedar bloqueado: ' . $type);
}

foreach (['sales_fiscal_exact'] as $type) {
    $assert(in_array($type, $blocked, true), 'Tipo sin camino completo debe quedar bloqueado explícitamente: ' . $type);
}

$code = (string) file_get_contents($root . '/app/Services/CronV3OperationalSnapshotService.php');
$assert(str_contains($code, 'CronV3CapabilityMatrixService'), 'El snapshot operativo debe leer la matriz de capacidades.');
$assert(str_contains($code, 'waiting_capability'), 'El snapshot debe publicar waiting_capability.');
$assert(str_contains($code, 'legacy_readonly_backlog'), 'El snapshot debe exponer histórico sin dueño como legacy_readonly_backlog.');

$routes = (string) file_get_contents($root . '/public/index.php');
foreach ([
    '/settings/cron/operational-snapshot.json',
    '/settings/cron/queues.json',
    '/settings/cron/history.json',
    '/settings/api-health/operational-snapshot.json',
] as $route) {
    $assert(str_contains($routes, $route), 'Falta ruta nueva: ' . $route);
}

$migration = (string) file_get_contents($root . '/database/migrations/258_cron_v3_capability_matrix_2_31_0.sql');
$assert(str_contains($migration, 'cron_v3_capability_matrix'), 'La migración debe crear la matriz.');
$assert(str_contains($migration, "('app.version', '2.31.0'"), 'La migración debe registrar app.version 2.31.0.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states)\b/i', $migration), 'La migración no debe tocar datos comerciales.');

echo "PASS cron_v3_capability_matrix_2310\n";
