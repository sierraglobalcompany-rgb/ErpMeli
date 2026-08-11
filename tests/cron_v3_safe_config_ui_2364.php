<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/public/assets/app.js');
$view = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(str_contains($js, 'syncLegacyV3PanelsForOperationalState'), 'Falta sincronización de visibilidad 2.36.4.');
$assert(str_contains($js, "root.querySelectorAll('[data-cron-v3-setup]')"), 'El panel seguro no está separado.');
$assert(str_contains($js, "root.querySelectorAll('[data-cron-v3-canary]')"), 'El canario no tiene visibilidad separada.');
$assert(str_contains($js, 'panel.hidden = false'), 'El panel seguro puede volver a ocultarse.');
$assert(str_contains($js, 'retirement.process_override_conflicts'), 'La UI no pinta conflictos efectivos.');
$assert(str_contains($view, '>Preparar configuración segura</button>'), 'La acción certificada cambió de nombre.');
$assert(str_contains($view, 'data-cron-v3-shadow-action'), 'Shadow no puede ocultarse durante retiro.');
foreach (['CRON_V3_ENABLED', 'CRON_V3_SHADOW_ENABLED', 'CRON_V4_ENABLED', 'ML_WRITE_ENABLED'] as $key) {
    $assert(str_contains($view, 'data-cron-v3-retirement-flag="' . $key . '"'), 'Flag ausente en UI: ' . $key);
}
$assert(substr_count($routes, "/settings/cron/v3-setup.json") === 1, 'El hotfix debe reutilizar exactamente un GET existente.');
$assert(substr_count($routes, "/settings/cron/v3-setup/prepare-safe-config") === 1, 'El hotfix debe reutilizar exactamente un POST existente.');
$assert(str_contains($controller, '$this->requireAdminPermanent();'), 'Falta gate de administrador.');
$assert(str_contains($controller, '$this->assertSameOrigin();'), 'Falta gate same-origin.');
$assert(str_contains($controller, "Csrf::validate(\$_POST['_token'] ?? null);"), 'Falta gate CSRF.');

echo "PASS cron_v3_safe_config_ui_2364\n";
