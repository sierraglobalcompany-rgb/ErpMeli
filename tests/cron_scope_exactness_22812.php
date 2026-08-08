<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\MeliEndpointRegistry;

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$enrichment = (string) file_get_contents($root . '/app/Services/OrderEnrichmentService.php');
$check(str_contains($enrichment, 'last_error_diagnostic_id'), 'Enriquecimiento debe persistir diagnóstico.');
$check(str_contains($enrichment, 'failure_class'), 'Enriquecimiento debe clasificar el fallo.');
$check(str_contains($enrichment, 'reached_remote'), 'Enriquecimiento debe distinguir transporte remoto.');
$check(str_contains($enrichment, 'ApiBudgetExhaustedException'), 'Enriquecimiento debe conservar espera de presupuesto.');

$billing = (string) file_get_contents($root . '/app/Services/OrderBillingImportService.php');
$check(str_contains($billing, 'int $companyId') && str_contains($billing, 'int $accountId'), 'Billing debe exigir scope explícito.');
$check(str_contains($billing, 'a.company_id=?') && str_contains($billing, 'o.meli_account_id=?'), 'Billing debe filtrar empresa y cuenta en SQL.');

$questions = (string) file_get_contents($root . '/app/Services/QuestionSyncService.php');
$check(str_contains($questions, 'question_sync_account_state'), 'Preguntas debe mantener cooldown por cuenta.');
$check(!str_contains($questions, "get('questions.last_sync_at'"), 'Preguntas no debe aplicar throttle global.');
$check(str_contains($questions, "accountPredicate('q.meli_account_id'") && str_contains($questions, '$scope->account('), 'Listado de preguntas debe exigir scope autorizado.');

$questionController = (string) file_get_contents($root . '/app/Controllers/QuestionController.php');
$check(str_contains($questionController, "accountPredicate('a.id'") && !str_contains($questionController, "query('SELECT id,account_name FROM meli_accounts"), 'Selector de preguntas debe listar solo cuentas autorizadas.');

$alerts = (string) file_get_contents($root . '/app/Services/AlertService.php');
$check(str_contains($alerts, 'private function alertScope('), 'Alertas debe centralizar el alcance autorizado.');
$check(substr_count($alerts, '$this->alertScope(') >= 4, 'Lecturas y mutaciones de alertas deben aplicar el mismo scope.');
$check(str_contains($alerts, 'new BusinessScopeContext())->account($accountId'), 'Alta de alertas por cuenta debe validar empresa y cuenta autorizadas.');
$check(str_contains($alerts, 'recentGrouped(50, $accountIds)') && str_contains($alerts, 'openCircuits(100, $accountIds)'), 'Alertas técnicas deben filtrar en SQL las cuentas autorizadas.');

$apiGuard = (string) file_get_contents($root . '/app/Services/ApiGuardService.php');
$openCircuits = strstr($apiGuard, 'public function openCircuits');
$check(
    is_string($openCircuits)
        && !str_contains(substr($openCircuits, 0, 1800), 'closeExpiredForAccounts(')
        && str_contains(substr($openCircuits, 0, 1800), 'b.meli_account_id IN ('),
    'Una lectura scoped debe filtrar el tenant sin cerrar circuitos desde GET.'
);

$manualAdapter = (string) file_get_contents($root . '/app/Services/RegisteredManualCampaignAdapter.php');
$check(substr_count($manualAdapter, 'new ManualCampaignSourceInspector()') >= 2, 'La ejecución exacta debe revalidar tenant inmediatamente antes de procesar.');

$growthSync = (string) file_get_contents($root . '/app/Modules/MeliGrowth/Services/GrowthSyncService.php');
$growthDashboard = (string) file_get_contents($root . '/app/Modules/MeliGrowth/Services/GrowthDashboardService.php');
$check(str_contains($growthSync, 'stageEndpointConfirmed') && str_contains($growthSync, 'MeliEndpointRegistry::isConfirmed'), 'Growth debe omitir endpoints no confirmados sin reintentos remotos.');
$check(str_contains($growthDashboard, '$scope->accountIds()') && str_contains($growthDashboard, 'meli_account_id IN ('), 'Growth web debe filtrar cuentas y caches por tenant autorizado.');

$moduleRunner = (string) file_get_contents($root . '/app/Core/Modules/ModuleJobRunner.php');
$logistics = (string) file_get_contents($root . '/app/Modules/MeliLogistics/Services/LogisticsSyncService.php');
$postSale = (string) file_get_contents($root . '/app/Modules/MeliPostSale/Services/PostSaleSyncService.php');
$check(str_contains($moduleRunner, "status=?") && str_contains($moduleRunner, "'ignored'"), 'Evento incompatible debe terminar ignored.');
$check(str_contains($logistics, 'syncExactEvent') && str_contains($logistics, "'resource_id'"), 'Logística debe procesar recurso exacto.');
$check(str_contains($postSale, 'syncExactEvent') && str_contains($postSale, 'isConfirmed'), 'Posventa exacta debe fallar cerrado con el mapa API.');

foreach (['/orders/123', '/packs/123', '/shipments/123', '/questions/123'] as $path) {
    try {
        MeliEndpointRegistry::assertDocumented('GET', $path);
    } catch (Throwable $error) {
        $failures[] = 'Endpoint confirmado bloqueado: ' . $path . ' (' . $error->getMessage() . ')';
    }
}
foreach (['/messages/unread', '/shipment_labels', '/advertising/advertisers'] as $path) {
    $blocked = false;
    try {
        MeliEndpointRegistry::assertDocumented('GET', $path);
    } catch (Throwable) {
        $blocked = true;
    }
    $check($blocked, 'Endpoint ausente del mapa debe estar bloqueado: ' . $path);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS cron_scope_exactness_22812\n";
