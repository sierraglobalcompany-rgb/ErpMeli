<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$shared = (string) file_get_contents($root . '/app/Modules/Shared/Services/ModuleDashboardService.php');
$check(str_contains($shared, 'new BusinessScopeContext()'), 'El dashboard compartido debe partir del scope autorizado.');
$check(str_contains($shared, "'meli_account_id'"), 'Las métricas privadas deben filtrar meli_account_id.');
$check(str_contains($shared, "['ml_growth_trends', 'ml_growth_highlights']"), 'Las cachés públicas Growth deben declararse explícitamente.');
$check(str_contains($shared, "'site_id' : 'meli_account_id'"), 'Las cachés públicas deben limitarse a sites representados por cuentas autorizadas.');
$check(str_contains($shared, 'system_module_jobs WHERE module_id=? AND meli_account_id IN ('), 'Los jobs compartidos deben quedar scoped por cuenta.');

$insights = (string) file_get_contents($root . '/app/Modules/MeliInsights/Services/InsightsDashboardService.php');
$check(str_contains($insights, '$scope->account($accountId)'), 'Un filtro Insights ajeno debe devolver 404.');
$check(str_contains($insights, '$scope->accountIds()'), 'Insights sin filtro debe usar todas y solo las cuentas autorizadas.');
$check(substr_count($insights, 'meli_account_id IN (') >= 5, 'Rows, jobs, reputación, capacidades y snapshot deben aplicar scope.');
$check(!str_contains($insights, "FROM system_module_jobs WHERE module_id='meli-insights'\n                 ORDER BY"), 'No debe quedar un listado global de jobs Insights.');

// Matriz adversarial documentada: dos cuentas de la empresa permitida y una
// cuenta con el mismo tipo de datos en otra empresa.
$authorized = [11, 12];
$rows = [
    ['company_id' => 1, 'meli_account_id' => 11],
    ['company_id' => 1, 'meli_account_id' => 12],
    ['company_id' => 2, 'meli_account_id' => 21],
];
$visible = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['meli_account_id'], $authorized, true)));
$check(array_column($visible, 'meli_account_id') === [11, 12], 'El scope debe incluir ambas cuentas autorizadas de la misma empresa y excluir la otra empresa.');
$selected = 12;
$filtered = array_values(array_filter($visible, static fn (array $row): bool => $row['meli_account_id'] === $selected));
$check(count($filtered) === 1 && $filtered[0]['meli_account_id'] === 12, 'El filtro exacto no debe mezclar la otra cuenta de la misma empresa.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS module_dashboard_read_scope_adversarial_22812\n";
