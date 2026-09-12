<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';

use App\Core\View;
$_SESSION['_csrf'] = 'capacity-render-token';

function capacity_render(string $name, array $data): string {
    ob_start();
    View::render('settings/' . $name, $data, false);
    return (string) ob_get_clean();
}

// A missing route/security contract fails before any dependency or database access.
$routes = (string) file_get_contents(__DIR__ . '/../public/index.php');
k1b_assert(str_contains($routes, "post('/settings/manual-processing/call-budget'"), 'Manual capacity requires its own save route.');
foreach (['/settings/manual-processing/call-budget', '/settings/cron/call-budget'] as $route) {
    $metadata = (new App\Repositories\RouteMetadataRepository())->for('POST', $route);
    k1b_assert($metadata['permanent_admin'] && $metadata['csrf'] && $metadata['role'] === 'admin', 'Capacity routes require permanent admin and CSRF.');
}
$definitions = (new App\Repositories\SettingsDefinitionRepository())->sections();
k1b_assert(is_array($definitions), 'Settings definitions render without argument errors.');
foreach ([1, 2, 3, 15, 55, 100] as $current) {
    $capacity = ['module' => 'manual', 'current' => $current, 'ceiling' => 100, 'revision' => 'revision-test'];
    $manual = capacity_render('manual_processing', ['capacity' => $capacity, 'scope' => 'available_queue', 'preview' => null, 'campaignReady' => true]);
    k1b_assert(str_contains($manual, 'name="manual_api_calls_per_step" min="1" max="100" value="' . $current . '"'), 'Manual current renders exact value ' . $current);
    k1b_assert(str_contains($manual, 'name="manual_api_calls_ceiling"'), 'Manual ceiling is independent.');
    k1b_assert(!str_contains($manual, '<select name="block_size"'), 'Physical capacity is not a resource-count select.');
    $capacity['module'] = 'automation';
    $automatic = capacity_render('api_workload', ['capacity' => $capacity, 'rhythm' => []]);
    k1b_assert(str_contains($automatic, 'name="automation_max_api_calls_per_cycle" min="1" max="100" value="' . $current . '"'), 'Automatic current renders exact value ' . $current);
    k1b_assert(str_contains($automatic, 'name="automation_api_calls_ceiling"'), 'Automatic ceiling is independent.');
}
$confirmation = capacity_render('capacity_confirmation', [
    'module' => 'manual', 'action' => '/settings/manual-processing/call-budget', 'returnPath' => '/settings/manual-processing',
    'proposal' => ['nonce' => 'test-nonce', 'before' => ['current' => 3, 'ceiling' => 55], 'current' => 55, 'ceiling' => 100],
]);
k1b_assert(str_contains($confirmation, 'Cancelar') && str_contains($confirmation, 'Confirmar'), 'Confirmation offers cancel and confirm.');
k1b_assert(str_contains($confirmation, '3 → 55') && str_contains($confirmation, '55 → 100'), 'Confirmation shows old and new current and ceiling.');
k1b_assert(!str_contains($confirmation, 'type="password"'), 'No additional password.');
k1b_assert(!str_contains($confirmation, 'salud del sistema'), 'Capacity confirmation must not promise a removed health prerequisite.');
k1b_assert(!str_contains($manual, 'requieren salud'), 'Manual capacity has no health prerequisite.');
$unavailable = capacity_render('api_workload', ['capacity' => $capacity, 'rhythm' => [], 'diagnosticsAvailable' => false]);
k1b_assert(str_contains($unavailable, 'Diagnóstico no disponible'), 'Unavailable diagnostics must be explicit.');
k1b_assert(str_contains($unavailable, 'id="call-budget-form"') && str_contains($unavailable, 'value="100"'), 'Verified capacity remains editable without diagnostics.');
k1b_assert(!str_contains($unavailable, 'Sin 429') && !str_contains($unavailable, 'Sin pausa 429'), 'Unknown telemetry must not claim zero 429 or no active pause.');
k1b_assert(!str_contains($unavailable, 'name="profile"') && !str_contains($unavailable, 'name="billing_min_interval_seconds"'), 'Unavailable settings must not enable a default-filled rhythm form.');
k1b_assert(!str_contains($unavailable, '1 llamada cada 300'), 'Unavailable Billing interval must not be displayed as verified configuration.');
k1b_assert(!str_contains($unavailable, 'Subirlo requiere evidencia'), 'Automatic capacity has no health prerequisite.');
$available = capacity_render('api_workload', ['capacity' => $capacity, 'rhythm' => ['profile' => 'balanced', 'billing_min_interval_seconds' => 420], 'diagnosticsAvailable' => true]);
k1b_assert(str_contains($available, 'name="profile"') && str_contains($available, 'value="420"'), 'Available diagnostics retain the advanced rhythm form and verified settings.');
echo "STATUS=PASS CAPACITY_UI\n";
