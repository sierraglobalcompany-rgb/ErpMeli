<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = (string) file_get_contents($root . '/app/Services/CronV3CanaryControlService.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$view = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$js = (string) file_get_contents($root . '/public/assets/app.js');
$migration = (string) file_get_contents($root . '/database/migrations/248_cron_v3_canary_control_2_29_4.sql');

foreach ([
    "/settings/cron/v3-canary.json",
    "/settings/cron/v3-canary/prepare",
    "/settings/cron/v3-canary/enable-local",
    "/settings/cron/v3-canary/enable-remote",
    "/settings/cron/v3-canary/rollback",
] as $route) {
    $assert(str_contains($routes, $route), 'Falta ruta canario ' . $route);
}

$assert(str_contains($controller, 'requireAdminPermanent();'), 'Los endpoints del canario deben exigir admin permanente.');
$assert(substr_count($controller, "cronV3CanaryMutation('") === 4, 'Deben existir cuatro mutaciones del canario.');
$assert(substr_count($controller, 'Csrf::validate') >= 6, 'Los POST sensibles deben validar CSRF.');
$assert(substr_count($controller, 'assertSameOrigin') >= 6, 'Los POST sensibles deben validar origen.');

$assert(str_contains($service, "'CRON_V3_ENABLED' => 'true'"), 'Preparar canario debe encender CRON_V3_ENABLED.');
$assert(str_contains($service, "'CRON_V3_SHADOW_ENABLED' => 'true'"), 'El canario debe conservar shadow habilitado.');
$assert(str_contains($service, "'CRON_V3_RATE_LIMIT' => '10'"), 'El canario debe iniciar con 10 rpm.');
$assert(str_contains($service, 'Env::bool(\'ML_WRITE_ENABLED\', false)'), 'El canario debe bloquear si ML_WRITE_ENABLED no está en false.');
$assert(str_contains($service, "private const LOCAL_CANARY_TYPES = ['financial_recalc'];"), 'El canario local debe limitarse a financial_recalc.');
$assert(str_contains($service, "private const REMOTE_CANARY_TYPES = ['pack_exact', 'shipment_exact'];"), 'El canario remoto debe limitarse a pack_exact/shipment_exact.');
$assert(!str_contains($service, 'MeliApiClient'), 'El servicio del panel no debe consultar Mercado Libre.');
$assert(str_contains($service, "'CRON_V3_ENABLED' => 'false'"), 'Rollback debe apagar CRON_V3_ENABLED.');
$assert(str_contains($service, "'owner_engine' => \$enabled ? 'v3' : 'disabled'"), 'Rollback debe retirar ownership V3.');

$assert(str_contains($view, 'Canario V3 real controlado'), 'Falta tarjeta humana de canario.');
$assert(str_contains($view, 'Volver a V2'), 'Falta acción rollback humana.');
$assert(str_contains($view, 'data-v3-canary-url'), 'La vista no expone endpoint canario.');
$assert(str_contains($js, 'renderCanary'), 'El JS no renderiza el canario.');
$assert(str_contains($js, 'data-cron-v3-canary-action'), 'El JS no intercepta acciones del canario.');

$assert(str_contains($migration, "('app.version','2.29.4'"), 'La migración 248 debe marcar app.version 2.29.4.');
$assert(str_contains($migration, "cron_v3.canary.remote_types"), 'La migración debe registrar tipos remotos del canario.');
$assert(!preg_match('/\bUPDATE\s+cron_v3_queue_ownership\b/i', $migration), 'La migración no debe activar ownership.');

echo "PASS cron_v3_canary_control_2294\n";
