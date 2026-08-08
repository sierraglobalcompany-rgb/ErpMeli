<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$cronView = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$healthShell = (string) file_get_contents($root . '/app/Views/settings/api_health_shell.php');
$healthJs = (string) file_get_contents($root . '/public/assets/api-health.js');
$appJs = (string) file_get_contents($root . '/public/assets/app.js');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$cronMethod = strstr($controller, 'public function cron(): void');
$cronMethod = is_string($cronMethod) ? strstr($cronMethod, 'public function cronSection(): void', true) : false;
$assert(is_string($cronMethod), 'No se encontró el shell de Cron.');
$assert(!str_contains($cronMethod, 'CronOperationalReadService'), 'El primer HTML de Cron todavía construye el overview pesado.');
$assert(str_contains($cronMethod, "'snapshot_state' => 'partial'"), 'Cron no declara su shell progresivo como parcial.');

$healthMethod = strstr($controller, 'public function apiHealth(): void');
$healthMethod = is_string($healthMethod) ? strstr($healthMethod, 'public function apiHealthSection(): void', true) : false;
$assert(is_string($healthMethod), 'No se encontró el shell de Salud API.');
$assert(!str_contains($healthMethod, 'ApiHealthOverviewService'), 'El primer HTML de Salud API todavía construye el overview pesado.');
$assert(str_contains($routes, "'/settings/api-health/section.html'"), 'Falta la sección progresiva de Salud API.');
$assert(str_contains($healthShell, 'data-api-health-shell') && str_contains($healthShell, 'aria-busy="true"'), 'Salud API no muestra un estado de carga accesible.');
$assert(str_contains($healthJs, 'loadSection()') && str_contains($healthJs, 'initializeLiveOverview()'), 'El cliente no carga inmediatamente la sección ni inicia su monitor.');
$assert(str_contains($cronView, 'data-cron-overview-updated') && str_contains($cronView, 'data-cron-tasks-updated'), 'Cron no separa la frescura del resumen y de las colas.');
$assert(str_contains($appJs, "[data-cron-overview-updated]") && str_contains($appJs, "[data-cron-tasks-updated]"), 'El polling de Cron todavía mezcla el estado de dos endpoints.');

echo "PASS progressive_cron_api_shell_22837\n";
