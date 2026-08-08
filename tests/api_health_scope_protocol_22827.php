<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pause = (string) file_get_contents($root . '/app/Services/ApiManualPauseService.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
$budget = (string) file_get_contents($root . '/app/Services/ApiBudgetService.php');
$overview = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$migration = (string) file_get_contents($root . '/database/migrations/207_api_health_scope_protocol_2_28_27.sql');

$assert(str_contains($pause, 'redacted_application_pause'), 'La pausa global todavía expone detalles o desaparece para un alcance parcial.');
$assert(str_contains($pause, '$includeApplication'), 'La lectura de pausas no exige permiso explícito de aplicación.');
$assert(str_contains($health, 'public function incidentPage'), 'Los incidentes no ofrecen paginación autoritativa.');
$assert(str_contains($health, "'truncated' => \$truncated"), 'La respuesta no declara truncamiento.');
$assert(str_contains($health, '$allScopedEvidenceAcknowledged'), 'El detalle puede marcar revisado con reconocimiento parcial.');
$assert(str_contains($budget, "'protocol' => !\$this->readAvailable ? 'unavailable'"), 'El presupuesto todavía confunde fallo SQL con vacío sano.');
$assert(str_contains($overview, "&& \$budgetAvailability['available']"), 'Salud API no degrada su disponibilidad cuando falla el presupuesto.');
$assert(!str_contains($overview, 'ApiBudgetService'), 'El resumen cargó las ventanas técnicas completas del presupuesto.');
$assert(str_contains($controller, 'catch (\\App\\Core\\HttpException $e)'), 'El JSON de Salud API convierte 404 de alcance en 503.');
$assert(str_contains($controller, "'total' => \$incidentPage['total']"), 'El endpoint de incidentes omite el total real.');
$assert(str_contains($migration, 'INSERT IGNORE INTO app_settings'), 'La migración pisa preferencias existentes en vez de instalar defaults.');
$assert(!str_contains($migration, 'UPDATE api_request_logs'), 'La migración modifica telemetría histórica de forma masiva.');

echo "PASS api_health_scope_protocol_22827\n";
