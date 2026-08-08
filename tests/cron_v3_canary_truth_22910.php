<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = (string) file_get_contents($root . '/app/Services/CronV3CanaryControlService.php');
$setup = (string) file_get_contents($root . '/app/Services/CronV3SetupAssistantService.php');
$js = (string) file_get_contents($root . '/public/assets/app.js');
$migration = (string) file_get_contents($root . '/database/migrations/254_cron_v3_canary_truth_2_29_10.sql');

$assert(str_contains($service, '$canaryHasSignal'), 'El canario debe reconocer señal activa real.');
$assert(str_contains($service, "'approval_source'"), 'Debe exponerse origen de aprobación shadow/canario.');
$assert(str_contains($service, "'active_canary_evidence'"), 'Debe aceptarse evidencia real del canario activo.');
$assert(str_contains($service, 'canarySafety'), 'Debe existir evaluación de seguridad del canario.');
$assert(str_contains($service, 'El canario V3 perdió leases'), 'Lease perdido debe bloquear ampliación.');
$assert(str_contains($service, 'Mercado Libre respondió 429'), '429 debe bloquear ampliación.');
$assert(str_contains($service, 'Shadow aprobado o cerrado por evidencia'), 'Los pasos no deben volver a Shadow pendiente con canario activo.');
$assert(!str_contains($service, 'MeliApiClient'), 'El panel de canario no debe consultar Mercado Libre.');

$assert(str_contains($setup, 'Shadow histórico cerrado por canario activo'), 'El asistente debe cerrar shadow cuando V3 real está activo.');
$assert(str_contains($setup, 'if ($activeEnabled)'), 'El asistente debe pasar a canary_controlled si V3 real está activo.');

$assert(str_contains($js, 'Canario remoto activo · sano'), 'La UI debe mostrar canario remoto sano.');
$assert(str_contains($js, 'HTTP reales'), 'La UI debe mostrar HTTP reales en el estado humano.');
$assert(str_contains($js, 'Shadow aprobado por evidencia'), 'La UI debe explicar shadow aprobado por evidencia.');

$assert(str_contains($migration, "('app.version', '2.29.10'"), 'La migración 254 debe marcar app.version 2.29.10.');
$assert(!preg_match('/\bUPDATE\s+cron_v3_queue_ownership\b/i', $migration), 'La migración 254 no debe cambiar ownership.');
$assert(!preg_match('/\bUPDATE\s+cron_v3_work\b/i', $migration), 'La migración 254 no debe tocar trabajos V3.');

echo "PASS cron_v3_canary_truth_22910\n";
