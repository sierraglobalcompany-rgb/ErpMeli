<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronV3ProducerService;
use App\Services\WorkEnvelope;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$captured = [];
$producer = new CronV3ProducerService(
    static fn (string $workType, string $lane): bool => in_array(
        $workType . ':' . $lane,
        [
            'financial_local_projection:local',
            'sale_billing_capture:remote',
            'claims_search_page:remote',
        ],
        true
    ),
    static function (WorkEnvelope $work) use (&$captured): array {
        $captured[] = $work;
        return ['id' => count($captured), 'created' => true, 'status' => 'ready'];
    }
);

$version = hash('sha256', 'financial-input-v1');
$assert($producer->financialLocalProjection(7, 19, 44, 'O:200', $version), 'Falta productor de proyección local.');
$assert($producer->saleBillingCapture(7, 19, 12, 'O:200', $version), 'Falta productor Billing canónico.');
$assert(!$producer->claimsSearchPage(7, 19, 'claims-manual:2026-08-02 22:15'), 'El productor huérfano de reclamos debe permanecer retirado.');
$assert(count($captured) === 2, 'Sólo los dos productores no incluidos en B1 deben crear sobres.');

$assert($captured[0]->workType === 'financial_local_projection' && $captured[0]->lane === 'local', 'La proyección debe ser local.');
$assert((int) $captured[0]->payload['meli_order_id'] === 44, 'La proyección debe ser exacta por orden.');
$assert($captured[1]->workType === 'sale_billing_capture' && $captured[1]->lane === 'remote', 'Billing debe ser remoto.');
$assert((int) $captured[1]->payload['job_id'] === 12, 'Billing debe referenciar el job legacy canónico.');
$billingDedupe = $captured[1]->dedupeKey;
$billingInput = $captured[1]->inputVersion;
$assert($producer->saleBillingCapture(7, 19, 12, 'O:200', $version), 'La reemisión Billing debe ser aceptada por el encolador idempotente.');
$assert(
    $captured[2]->dedupeKey === $billingDedupe && $captured[2]->inputVersion === $billingInput,
    'La misma venta y versión deben conservar dedupe_key e input_version estables.'
);

$disabledCalls = 0;
$disabled = new CronV3ProducerService(
    static fn (string $workType, string $lane): bool => false,
    static function (WorkEnvelope $work) use (&$disabledCalls): array {
        $disabledCalls++;
        return ['id' => 1, 'created' => true, 'status' => 'ready'];
    }
);
$assert(!$disabled->financialLocalProjection(7, 19, 44, 'O:200', $version), 'Ownership deshabilitado debe conservar V2.');
$assert(!$disabled->saleBillingCapture(7, 19, 12, 'O:200', $version), 'Billing no debe saltar ownership.');
$assert(!$disabled->claimsSearchPage(7, 19, 'claims-manual:fallback'), 'Reclamos debe conservar fallback manual/V2.');
$assert($disabledCalls === 0, 'Ownership deshabilitado no debe tocar cron_v3_work.');

$failing = new CronV3ProducerService(
    static fn (string $workType, string $lane): bool => true,
    static function (WorkEnvelope $work): array {
        throw new RuntimeException('temporary enqueue outage');
    }
);
$assert(
    !$failing->saleBillingCapture(7, 19, 12, 'O:200', $version),
    'A producer outage must degrade to the legacy adapter/fallback.'
);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/app/Services/CronV3ProducerService.php');
$finance = (string) file_get_contents($root . '/app/Services/SaleFinancialService.php');
$state = (string) file_get_contents($root . '/app/Services/SaleFinancialStateService.php');
$claims = (string) file_get_contents($root . '/app/Controllers/ClaimController.php');

$assert(str_contains($source, "owner_engine=\"v3\"") && str_contains($source, 'enabled=1'), 'El productor debe exigir ownership V3 explícito.');
$assert(str_contains($source, "hasTable('cron_v3_work')"), 'El productor debe tolerar migración V3 ausente.');
$assert(!str_contains($source, 'MeliApiClient'), 'Un productor no puede ejecutar HTTP Mercado Libre.');
$assert(!str_contains($source, 'manual_campaign'), 'Los productores V3 no pueden crear campañas persistentes.');
$assert(!str_contains($finance, 'saleBillingCapture('), 'Las fuentes financieras automáticas no pueden depender del productor V3.');
$assert(str_contains($state, 'financialLocalProjection('), 'projectOrder debe materializar la unidad local conforme ownership.');
$assert(!str_contains($claims, 'claimsSearchPage('), '/claims/sync no debe materializar una página V3 retirada.');
$assert(!str_contains($claims, '/settings/manual-processing'), 'Reclamos no debe simular un fallback manual inexistente.');
$assert(!str_contains(substr($claims, (int) strpos($claims, 'public function sync'), 900), 'syncOpened('), 'La petición web de reclamos no puede hacer transporte directo.');

echo "cron_v3_producers_2290: PASS\n";
