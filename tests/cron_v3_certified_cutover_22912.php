<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = (string) file_get_contents($root . '/app/Services/CronV3CertifiedCutoverService.php');
$cli = (string) file_get_contents($root . '/app/Services/CronV3Cli.php');
$canary = (string) file_get_contents($root . '/app/Services/CronV3CanaryControlService.php');
$read = (string) file_get_contents($root . '/app/Services/CronV3OperationalReadService.php');
$migration = (string) file_get_contents($root . '/database/migrations/256_cron_v3_certified_cutover_2_29_12.sql');

$assert(str_contains($service, "'sale_billing_capture'"), 'El corte certificado debe incluir sale_billing_capture.');
$assert(str_contains($service, 'MIN_CANARY_HTTP = 25'), 'El corte requiere evidencia HTTP mínima del canario.');
$assert(str_contains($service, "Env::bool('ML_WRITE_ENABLED', false)"), 'El corte debe bloquear ML_WRITE_ENABLED=true.');
$assert(str_contains($service, 'cron_v3.certified_cutover.enabled'), 'El corte debe poder apagarse por setting.');
$assert(str_contains($cli, 'CronV3CertifiedCutoverService'), 'El CLI debe aplicar el corte certificado automáticamente.');
$assert(str_contains($canary, "'certified_cutover' => \$certifiedCutover"), 'El panel de canario debe exponer estado del corte certificado.');
$assert(str_contains($canary, "private const CERTIFIED_CUTOVER_TYPES = ['financial_recalc', 'pack_exact', 'shipment_exact', 'sale_billing_capture'];"), 'El rollback del canario debe incluir todas las familias certificadas.');
$assert(str_contains($canary, "self::CERTIFIED_CUTOVER_TYPES, null, false"), 'Volver a V2 debe retirar también el ownership certificado ampliado.');
$assert(str_contains($read, "WHERE snapshot_key IN ('run:local','run:remote')"), 'El resumen V3 debe leer señales por clave estable.');
$assert(!str_contains($read, "snapshot_type='run' AND snapshot_key"), 'El resumen V3 no debe quedar ciego por snapshot_type.');
$assert(str_contains($migration, "('cron_v3.certified_cutover.remote_types', 'pack_exact,shipment_exact,sale_billing_capture'"), 'La migración debe documentar tipos remotos certificados.');
$assert(str_contains($migration, 'INSERT IGNORE INTO app_settings'), 'La migración no debe sobrescribir configuración operativa existente.');
$defaultsBlock = (string) (explode('INSERT INTO app_versions', explode('INSERT IGNORE INTO app_settings', $migration, 2)[1] ?? '', 2)[0] ?? '');
$assert(!str_contains($defaultsBlock, 'ON DUPLICATE KEY UPDATE'), 'Los defaults del corte certificado no deben actualizarse por duplicado.');
$assert(!preg_match('/\bUPDATE\s+cron_v3_queue_ownership\b/i', $migration), 'La migración no debe cambiar ownership directamente.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states)\b/i', $migration), 'La migración no debe tocar datos comerciales.');

echo "PASS cron_v3_certified_cutover_22912\n";
