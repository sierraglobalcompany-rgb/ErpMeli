<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$controller = $read('app/Controllers/SettingsController.php');
$snapshot = $read('app/Services/CronV3OperationalSnapshotService.php');

$assert(str_contains($controller, 'legacy_v2_before_v3_cutover'), 'El historial debe etiquetar ciclos legacy previos al corte V3.');
$assert(str_contains($controller, 'intervention_groups'), 'El historial debe agrupar intervención legacy.');
$assert(str_contains($controller, 'legacy_needs_diagnosis'), 'legacy_needs_diagnosis debe tratarse como grupo, no repetirse como fila roja.');
$assert(str_contains($snapshot, "'formula' => 'pendientes anteriores + entradas nuevas - finalizados = pendientes actuales'"), 'El snapshot debe publicar la fórmula de drenaje.');
$assert(str_contains($snapshot, "'can_claim_decreasing' =>"), 'El snapshot debe publicar si puede afirmar drenaje.');
$assert(str_contains($snapshot, '$criticalGaps'), 'El snapshot no debe afirmar drenaje con brechas críticas.');
$assert(str_contains($snapshot, 'v3_parked'), 'El snapshot debe considerar trabajos parqueados.');
$assert(str_contains($snapshot, 'no se declarará que la cola baja') && str_contains($snapshot, 'Hay trabajo parqueado'), 'El snapshot debe explicar por qué no afirma que la cola baja.');

$migration = $read('database/migrations/261_cron_v3_history_drainage_certification_2_31_3.sql');
$assert(str_contains($migration, "('app.version', '2.31.3'"), 'La migración 261 debe registrar app.version 2.31.3.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states)\b/i', $migration), 'La migración 261 no debe tocar datos comerciales.');

echo "PASS cron_v3_history_drainage_2313\n";
