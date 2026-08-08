<?php

declare(strict_types=1);

putenv('APP_KEY=base64:' . base64_encode(str_repeat('k', 32)));
putenv('ML_WRITE_ENABLED=false');

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CommercialPathReadinessService;
use App\Services\SalesAuditTemporalCoverageService;
use App\Services\SalesTemporalCoverageService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$coverage = new SalesAuditTemporalCoverageService();
$window = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

$full = $coverage->classify(
    new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
    new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC')),
    $window
);
$assert($full['state'] === 'full', 'Un mes dentro de la ventana debe ser full.');
$assert((int) $full['contract_version'] === SalesAuditTemporalCoverageService::CONTRACT_VERSION, 'Debe guardar versión de contrato vigente.');

$partial = $coverage->classify(
    new DateTimeImmutable('2025-12-01 00:00:00', new DateTimeZone('UTC')),
    new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
    $window
);
$assert($partial['state'] === 'partial', 'Un mes que cruza la ventana debe ser partial.');
$assert($partial['effective_from_utc'] === '2026-01-01 00:00:00', 'La cobertura parcial debe comenzar en el inicio de ventana.');

$outside = $coverage->classify(
    new DateTimeImmutable('2025-10-01 00:00:00', new DateTimeZone('UTC')),
    new DateTimeImmutable('2025-11-01 00:00:00', new DateTimeZone('UTC')),
    $window
);
$assert($outside['state'] === 'outside', 'Un mes anterior a la ventana debe ser outside.');
$assert($coverage->canClose(['temporal_coverage_state' => 'outside', 'coverage_contract_version' => 2]) === false, 'Outside no puede cerrar.');
$assert($coverage->canClose(['temporal_coverage_state' => 'full', 'coverage_contract_version' => 2]) === true, 'Full con contrato vigente puede cerrar si las demás evidencias aprueban.');

$invalid = $coverage->classify(
    new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC')),
    new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')),
    $window
);
$assert($invalid['state'] === 'invalid', 'Un rango invertido debe ser invalid.');

$presentation = (new SalesTemporalCoverageService())->yearSummary(
    2026,
    7,
    new DateTimeImmutable('2026-07-31 12:00:00', new DateTimeZone('America/Bogota')),
    12,
    7,
    7
);
$assert(in_array($presentation['confidence'], ['complete', 'partial'], true), 'El resumen anual debe presentar confianza explícita.');
$assert($presentation['available'] === true, 'El resumen anual debe declarar disponibilidad dentro de la ventana configurada.');
$assert($presentation['remote_window_months'] === 12, 'El resumen anual conserva la ventana configurada.');
$assert(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $presentation['available_to']) === 1, 'La fecha disponible hasta debe presentarse como fecha humana.');
$assert(is_string($presentation['message']) && $presentation['message'] !== '', 'El resumen anual debe explicar la cobertura temporal.');

$snapshot = (new CommercialPathReadinessService())->snapshot();
$assert($snapshot['version'] === '2.28.1', 'La matriz comercial debe declarar 2.28.1.');
$assert($snapshot['phases'][1]['key'] === 'coverage' && $snapshot['phases'][1]['enabled'] === true, 'Fase 1 debe quedar habilitada por defecto.');
$assert($snapshot['next_phase'] !== null && $snapshot['next_phase']['key'] === 'order_change_sweep', 'La siguiente fase debe ser barrido incremental.');

echo "PASS sales_temporal_coverage_2281\n";
