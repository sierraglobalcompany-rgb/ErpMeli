<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$js = (string) file_get_contents($root . '/public/assets/app.js');
$css = (string) file_get_contents($root . '/public/assets/app.css');
$migration = (string) file_get_contents($root . '/database/migrations/249_cron_v3_canary_actionable_ui_2_29_5.sql');

$assert(str_contains($js, 'data-cron-v3-canary-action'), 'El JS debe controlar acciones del canario.');
$assert(str_contains($js, "lastCanary.state === 'ready_for_prepare'"), 'Preparar debe depender de ready_for_prepare.');
$assert(str_contains($js, "lastCanary.state === 'ready_for_local'"), 'Local debe depender de ready_for_local.');
$assert(str_contains($js, "lastCanary.state === 'ready_for_remote'"), 'Remoto debe depender de ready_for_remote.');
$assert(str_contains($js, "button.disabled = !enabled"), 'Los botones no disponibles deben quedar deshabilitados.');
$assert(str_contains($js, 'aria-disabled'), 'Debe existir estado accesible aria-disabled.');
$assert(str_contains($js, 'canario real se controla en la tarjeta siguiente'), 'El asistente no debe decir que V3 real sigue apagado cuando hay canario.');
$assert(str_contains($css, '.is-current-action'), 'Debe resaltarse la acción actual.');
$assert(str_contains($css, '.btn:disabled'), 'Debe existir estilo de botón deshabilitado.');
$assert(str_contains($migration, "('app.version','2.29.5'"), 'La migración 249 debe marcar app.version 2.29.5.');
$assert(!preg_match('/\bUPDATE\s+cron_v3_queue_ownership\b/i', $migration), 'La migración UI no debe cambiar ownership.');

echo "PASS cron_v3_canary_actionable_ui_2295\n";
