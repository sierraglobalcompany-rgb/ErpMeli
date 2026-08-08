<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\SyncSettingsService;

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// Hard limits must survive malformed POST values and extreme legacy/database
// settings. They are runtime contracts, not only HTML min/max attributes.
$assert(SyncSettingsService::clampOrdersPerRun(PHP_INT_MAX) === 500, 'El límite de órdenes no cercó un valor extremo.');
$assert(SyncSettingsService::clampOrdersPerRun(0) === 1, 'El límite de órdenes debe conservar un mínimo válido.');
$assert(SyncSettingsService::clampApiPages(PHP_INT_MAX) === 10, 'El límite de páginas API no cercó un valor extremo.');
$assert(SyncSettingsService::clampApiPages(-100) === 1, 'El límite de páginas API debe conservar un mínimo válido.');

$settings = (string) file_get_contents($root . '/app/Services/SyncSettingsService.php');
$definitions = (string) file_get_contents($root . '/app/Repositories/SettingsDefinitionRepository.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$orders = (string) file_get_contents($root . '/app/Services/OrderSyncService.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');
$healthView = (string) file_get_contents($root . '/app/Views/settings/api_health.php');

$assert(str_contains($settings, "int('sync.max_orders_per_run', 500)"), 'La configuración extrema de base debe pasar por el cerco central.');
$assert(str_contains($settings, "int('sync.max_api_pages_per_run', 5)"), 'Falta el límite configurable de páginas API.');
$assert(str_contains($definitions, "'sync.max_orders_per_run', 'Máximo por ejecución', 500, 1, 500"), 'La UI todavía permite superar el límite duro de órdenes.');
$assert(str_contains($definitions, "'sync.max_api_pages_per_run'"), 'La UI no expone el límite seguro de páginas.');
$assert(str_contains($controller, "SyncSettingsService::clampOrdersPerRun(\$value)"), 'SettingsController no cerca el POST de órdenes.');
$assert(str_contains($controller, "SyncSettingsService::clampApiPages(\$value)"), 'SettingsController no cerca el POST de páginas.');
$assert(!str_contains($orders, 'PHP_INT_MAX'), 'OrderSyncService todavía permite páginas API ilimitadas.');
$assert(substr_count($orders, 'maxApiPagesPerRun(') >= 2, 'Los dos caminos de sincronización no comparten el límite de páginas.');
$assert(str_contains($orders, "'api_pages' => \$pages"), 'El resultado no informa cuántas páginas consumió.');

// Salud API and automation are deliberately separate. The stopped state must
// short-circuit before SQL; active evidence is a cached single-row read.
$stopPosition = strpos($health, "if (\$automationState === 'stopped')");
$cachePosition = strpos($health, "'api-health-automation-evidence'");
$queryPosition = strpos($health, 'FROM system_work_queue_runs');
$assert($stopPosition !== false && $cachePosition !== false && $queryPosition !== false, 'Falta el estado ligero de automatización.');
$assert($stopPosition < $cachePosition && $cachePosition < $queryPosition, 'Automatización detenida debe evitar SQL y la lectura activa debe usar caché.');
$assert(str_contains($health, "'delayed' => 'Automatización atrasada'"), 'Salud API no distingue automatización atrasada.');
$assert(str_contains($health, "'stopped'"), 'Salud API no distingue automatización detenida.');
$assert(str_contains($healthView, 'Son dos comprobaciones distintas.'), 'La vista no explica la diferencia entre API y Cron.');
$assert(str_contains($healthView, 'Salud API indica si Mercado Libre responde'), 'La vista no comunica el alcance de Salud API.');
$assert(str_contains($healthView, 'Abrir Cron'), 'Falta una acción directa cuando la automatización requiere revisión.');

echo "PASS sync_hard_caps_api_health_qa\n";
