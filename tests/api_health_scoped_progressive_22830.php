<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$overview = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$accounts = (string) file_get_contents($root . '/app/Views/settings/api_health_accounts.php');
$view = (string) file_get_contents($root . '/app/Views/settings/api_health.php');
$javascript = (string) file_get_contents($root . '/public/assets/api-health.js');

$assert(str_contains($overview, '$accountId !== null || !$access[\'application\']'), 'La evidencia global de Cron todavía se expone a una cuenta o administrador parcial.');
$assert(str_contains($overview, 'métricas globales protegidas'), 'La UI no explica por qué se protegen métricas globales fuera de scope.');
$assert(strpos($controller, 'if (!$service->dataAvailable())') < strpos($controller, 'if ($incident === null)'), 'El detalle devuelve 404 antes de distinguir un fallo de lectura.');
$assert(substr_count($health, '$this->dataAvailable = false;') >= 8, 'Las lecturas fallidas de incidentes todavía pueden parecer vacías sanas.');
$assert(str_contains($routes, "'/settings/api-health/incidents.json'"), 'Falta la colección JSON paginada de incidentes.');
$assert(str_contains($routes, "'/settings/api-health/incidents/show.json'"), 'Falta el detalle JSON de incidentes.');
$assert(str_contains($controller, "'pages' => max(1"), 'La paginación JSON no declara el total de páginas.');
$assert(str_contains($controller, "'protocol' => 'unavailable'"), 'Los fallos de detalle no declaran protocolo unavailable.');
$assert(str_contains($controller, "'account_id' => \$accountId ?: null"), 'La protección JSON no conserva el scope de cuenta elegido.');
$assert(str_contains($accounts, "'aging' => 'Evidencia antigua'"), 'El filtro de cuentas no permite revisar evidencia antigua.');
$assert(str_contains($view, 'data-api-health-live'), 'La vista no activa la actualización progresiva.');
$assert(str_contains($javascript, 'Se conserva el último estado comprobado'), 'Una lectura fallida puede borrar el último estado válido.');
$assert(str_contains($javascript, 'controller?.abort()'), 'El polling no cancela la petición anterior.');
$assert(str_contains($javascript, 'document.hidden ? 60000 : 30000'), 'El polling no reduce frecuencia con la pestaña oculta.');

echo "PASS api_health_scoped_progressive_22830\n";
