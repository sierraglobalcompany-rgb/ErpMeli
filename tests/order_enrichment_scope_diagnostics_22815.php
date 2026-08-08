<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\MeliEndpointRegistry;
use App\Services\WorkAttentionPresenter;

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$enrichment = (string) file_get_contents($root . '/app/Services/OrderEnrichmentService.php');
$registry = (string) file_get_contents($root . '/app/Services/WorkQueueRegistry.php');
$projection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
$adapter = (string) file_get_contents($root . '/app/Services/SqlWorkQueueAdapter.php');
$sync = (string) file_get_contents($root . '/app/Services/OrderSyncService.php');
$view = (string) file_get_contents($root . '/app/Views/settings/automation_work.php');
$settingsController = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');

$check(str_contains($enrichment, 'last_error_diagnostic_id=:diagnostic'), 'El fallo de enriquecimiento no persiste la referencia segura.');
$check(str_contains($enrichment, 'j.reached_remote=1'), 'Una finalización remota aprobada no conserva evidencia del transporte.');
$check(str_contains($enrichment, '$error->requestId !== null'), 'Un transporte sin respuesta HTTP se presenta como si no hubiera salido.');
$check(str_contains($enrichment, '$terminal = true;') && str_contains($enrichment, "'terminal' => \$terminal"), 'Un 404 esperado no termina definitivamente el recurso.');
$check(str_contains($enrichment, "'company_id' => (int) \$job['company_id']"), 'El diagnóstico no conserva la empresa del trabajo.');
$check(str_contains($enrichment, 'orderBelongsToAccountCompany'), 'La cola acepta una orden sin comprobar su cuenta y empresa.');
$check(str_contains($enrichment, 'JOIN meli_accounts a ON a.id=j.meli_account_id'), 'Los cierres de lease no cercan la empresa de la cuenta.');
$check(str_contains($enrichment, 'JOIN companies c ON c.id=a.company_id AND c.status=1'), 'El alta de recursos no exige empresa activa.');

$check(str_contains($registry, 'j.last_error_code normalized_error_code'), 'La proyección pierde el código seguro de order_enrichment.');
$check(str_contains($registry, 'j.failure_class remediation_key'), 'La proyección pierde la clasificación del fallo.');
$check(str_contains($registry, 'j.reached_remote'), 'La proyección pierde la evidencia de transporte.');
$check(str_contains($registry, 'j.external_resource_id') && str_contains($registry, 'j.meli_order_id'), 'El detalle no identifica recurso y orden.');
$check(str_contains($projection, "'normalized_error_code','retry_policy','remediation_key','reached_remote','next_retry_at'"), 'La escritura de la proyección descarta diagnóstico operativo.');
$check(str_contains($adapter, "'reached_remote' => array_key_exists('reached_remote', \$row)"), 'El adaptador no conserva remoto=true/false/null.');
$check(str_contains($view, 'Detalle seguro:') && str_contains($view, 'normalized_error_code'), 'La vista no muestra el mensaje seguro ni el código diagnóstico.');
$automationWork = strstr($settingsController, 'public function automationWork');
$check(is_string($automationWork)
    && str_contains(substr($automationWork, 0, 1800), "(int) \$work['meli_account_id']")
    && str_contains(substr($automationWork, 0, 1800), "(int) (\$work['company_id'] ?? 0)"),
    'El detalle directo no revalida la pareja empresa/cuenta antes de renderizar.');

$check(str_contains($sync, "'job_type' => 'order_enrichment'"), 'Las llamadas de enriquecimiento siguen atribuidas a orders_sync.');
$check(str_contains($sync, "'source_work_id' =>"), 'La telemetría no identifica el job exacto.');

$presented = (new WorkAttentionPresenter())->present([
    'display_status' => 'error',
    'safe_error_message' => 'El trabajo no tiene una identidad de pack o envío válida y requiere corrección local.',
    'queue_key' => 'order_enrichment',
    'source_id' => '19',
    'meli_account_id' => 7,
    'is_api_task' => 1,
    'reached_remote' => 0,
]);
$check(($presented['reached_remote'] ?? null) === false, 'La UI ignora la evidencia de que no hubo transporte remoto.');
$check(($presented['label'] ?? '') === 'Necesita corregir el recurso', 'Una identidad inválida no tiene acción humana específica.');

foreach (['/advertising/advertisers', '/messages/unread', '/shipment_labels', '/categories/MCO1'] as $path) {
    $check(!MeliEndpointRegistry::isConfirmed('GET', $path), 'Endpoint no confirmado habilitado: ' . $path);
}
foreach (['/orders/search', '/orders/123', '/packs/123', '/shipments/123'] as $path) {
    $check(MeliEndpointRegistry::isConfirmed('GET', $path), 'Endpoint documentado bloqueado: ' . $path);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS order_enrichment_scope_diagnostics_22815\n";
