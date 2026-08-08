<?php

declare(strict_types=1);

putenv('APP_KEY=base64:' . base64_encode(str_repeat('k', 32)));
putenv('ML_WRITE_ENABLED=false');

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\SalesAuditTemporalCoverageService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = new SalesAuditTemporalCoverageService();
$window = new DateTimeImmutable('2025-07-31 00:00:00', new DateTimeZone('UTC'));

$full = $service->classify(
    new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
    new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
    $window
);
$assert($full['state'] === 'full', 'Enero 2026 debe ser full cuando está dentro de ventana.');
$assert($full['effective_from_utc'] === '2026-01-01 00:00:00', 'Full conserva inicio efectivo.');
$assert($full['effective_to_utc'] === '2026-02-01 00:00:00', 'Full conserva fin efectivo.');

$partial = $service->classify(
    new DateTimeImmutable('2025-07-01 00:00:00', new DateTimeZone('UTC')),
    new DateTimeImmutable('2025-08-01 00:00:00', new DateTimeZone('UTC')),
    $window
);
$assert($partial['state'] === 'partial', 'Un mes que cruza la ventana debe ser parcial.');
$assert($partial['effective_from_utc'] === '2025-07-31 00:00:00', 'Parcial empieza en la ventana disponible.');

$outside = $service->classify(
    new DateTimeImmutable('2025-06-01 00:00:00', new DateTimeZone('UTC')),
    new DateTimeImmutable('2025-07-01 00:00:00', new DateTimeZone('UTC')),
    $window
);
$assert($outside['state'] === 'outside', 'Un mes anterior a la ventana debe ser outside.');
$assert($outside['effective_from_utc'] === null && $outside['effective_to_utc'] === null, 'Outside no tiene rango efectivo remoto.');

$invalid = $service->classify(
    new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
    new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
    $window
);
$assert($invalid['state'] === 'invalid', 'Un rango vacío debe ser invalid.');

$assert($service->canClose([
    'temporal_coverage_state' => 'full',
    'coverage_contract_version' => SalesAuditTemporalCoverageService::CONTRACT_VERSION,
]) === true, 'Solo cobertura full con contrato vigente permite cierre.');
$assert($service->canClose([
    'temporal_coverage_state' => 'partial',
    'coverage_contract_version' => SalesAuditTemporalCoverageService::CONTRACT_VERSION,
]) === false, 'Cobertura parcial no permite cierre.');
$assert($service->canClose([
    'temporal_coverage_state' => 'full',
    'coverage_contract_version' => 1,
]) === false, 'Contrato antiguo no permite cierre nuevo.');

$missingRange = $service->classifyRun(
    ['created_at' => '2026-07-31 00:00:00'],
    new DateTimeImmutable('2026-07-31 00:00:00', new DateTimeZone('UTC'))
);
$assert($missingRange['state'] === 'invalid', 'Una ejecución sin rango UTC debe quedar inválida, no convertirse en ahora.');

echo "PASS sales_audit_temporal_coverage_2281\n";
