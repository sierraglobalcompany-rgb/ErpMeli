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
echo "STATUS=PASS CAPACITY_UI\n";
