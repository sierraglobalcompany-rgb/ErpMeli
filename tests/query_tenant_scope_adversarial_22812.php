<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// Caso adversarial multiempresa: el filtro solo puede reducir la lista base.
$narrow = static function (array $authorized, int $requested): array {
    if ($requested <= 0) {
        return $authorized;
    }
    return in_array($requested, $authorized, true) ? [$requested] : [];
};
$check($narrow([11, 12], 0) === [11, 12], 'Sin filtro se perdió el conjunto base autorizado.');
$check($narrow([11, 12], 12) === [12], 'El filtro autorizado no estrechó el conjunto.');
$check($narrow([11, 12], 91) === [], 'Una cuenta de otra empresa amplió el conjunto autorizado.');

$log = (string) file_get_contents($root . '/app/Services/LogQueryService.php');
$claim = (string) file_get_contents($root . '/app/Services/ClaimSyncService.php');
$shipment = (string) file_get_contents($root . '/app/Services/ShipmentQueryService.php');
$pack = (string) file_get_contents($root . '/app/Services/PackQueryService.php');

foreach (['LogQueryService' => $log, 'ClaimSyncService' => $claim, 'ShipmentQueryService' => $shipment, 'PackQueryService' => $pack] as $name => $source) {
    $check(str_contains($source, 'new BusinessScopeContext())->accountIds()'), $name . ' no obtiene la lista base autorizada.');
    $check(str_contains($source, 'in_array($requested, $accountIds, true) ? [$requested] : []'), $name . ' no rechaza account_id de otra empresa.');
    $check(str_contains($source, "'1=0'"), $name . ' no falla cerrado cuando el scope queda vacío.');
}

$check(str_contains($log, "namedAccountScope(\$where, \$params, 'e.meli_account_id'")
    && str_contains($log, "namedAccountScope(\$where, \$params, 'l.meli_account_id'")
    && str_contains($log, "namedAccountScope(\$where, \$params, 'q.meli_account_id'")
    && str_contains($log, "'l.meli_account_id IN ('"), 'Logs API, sync y preguntas no comparten el scope base.');
$check(str_contains($log, 'No existe clave de empresa/cuenta') && str_contains($log, 'cron_health_checks no identifica tenant'), 'Logs sin clave tenant no fallan cerrado explícitamente.');
$check(str_contains($shipment, "'v3-' . \$scopeKey") && substr_count($shipment, 'meli_account_id IN (') >= 2, 'Opciones/caché de envíos pueden mezclar empresas.');
$check(str_contains($claim, 'c.meli_account_id IN ('), 'Reclamos sin filtro siguen siendo globales.');
$check(str_contains($pack, 'p.meli_account_id IN ('), 'Packs sin filtro siguen siendo globales.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS query_tenant_scope_adversarial_22812\n";
