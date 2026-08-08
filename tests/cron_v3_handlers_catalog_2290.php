<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronV3DefaultHandlerBootstrap;
use App\Services\CronV3ExecutionContext;
use App\Services\CronV3HandlerRegistry;
use App\Services\CronV3Handlers\CatalogDescriptionExactHandler;
use App\Services\CronV3Handlers\ItemExactHandler;
use App\Services\CronV3Handlers\ItemsSearchPageHandler;
use App\Services\CronV3Handlers\ModuleLogisticsExactHandler;
use App\Services\CronV3Handlers\OrdersSearchPageHandler;
use App\Services\CronV3Handlers\QuestionExactHandler;
use App\Services\CronV3Handlers\QuestionsSearchPageHandler;
use App\Services\CronV3WorkTypeRegistry;
use App\Services\WorkEnvelope;

function catalogAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function catalogWork(string $type, array $payload): WorkEnvelope
{
    return WorkEnvelope::create(7, 19, $type, 'remote', $type . ':resource', $type . ':v1', $payload);
}

/** @return App\Services\CronV3ExecutionContext */
function catalogRemote(): CronV3ExecutionContext
{
    return CronV3ExecutionContext::remote(static fn (): array => [
        'allowed' => true,
        'retry_at' => null,
        'reason' => 'test',
    ]);
}

$children = [];
$ordersContext = catalogRemote();
$orders = new OrdersSearchPageHandler(
    static function (WorkEnvelope $work, int $limit, int $offset, string $from, string $to): array {
        catalogAssert($work->companyId === 7 && $work->meliAccountId === 19, 'orders perdió el scope');
        catalogAssert($limit === 20 && $offset === 0, 'orders no limitó la página a 20');
        catalogAssert($from !== '' && $to !== '', 'orders perdió el rango');
        return ['results' => [['id' => 101], ['id' => 102]], 'paging' => ['total' => 3]];
    },
    static function (WorkEnvelope $child) use (&$children): void { $children[] = $child; }
);
$ordersResult = $orders->executeOne(catalogWork('orders_search_page', [
    'limit' => 99,
    'offset' => 0,
    'date_from' => '2026-08-01T00:00:00+00:00',
    'date_to' => '2026-08-02T00:00:00+00:00',
]), $ordersContext);
catalogAssert($ordersContext->logicalCallCount() === 1, 'orders hizo más de una llamada lógica');
catalogAssert($ordersResult->status === 'completed', 'orders no completó');
catalogAssert(array_map(static fn (WorkEnvelope $w): string => $w->workType, $children) === [
    'order_exact', 'order_exact', 'orders_search_page',
], 'orders no materializó exactos y continuación correctamente');

$children = [];
$questionsContext = catalogRemote();
$questions = new QuestionsSearchPageHandler(
    static function (WorkEnvelope $work, int $limit): array {
        catalogAssert($work->companyId === 7 && $work->meliAccountId === 19, 'questions perdió el scope');
        catalogAssert($limit === 20, 'questions no limitó la página a 20');
        return ['questions' => [['id' => 201], ['question_id' => 202]]];
    },
    static function (WorkEnvelope $child) use (&$children): void { $children[] = $child; }
);
$questionPageResult = $questions->executeOne(catalogWork('questions_search_page', ['limit' => 50]), $questionsContext);
catalogAssert($questionsContext->logicalCallCount() === 1, 'questions hizo más de una llamada lógica');
catalogAssert(count($children) === 2 && $children[0]->workType === 'question_exact', 'questions no encoló exactos');
catalogAssert($questionPageResult->metadata['continuation_enabled'] === false, 'questions inventó un cursor no confirmado');

$questionContext = catalogRemote();
$question = new QuestionExactHandler(static function (WorkEnvelope $work, string $id): int {
    catalogAssert($work->companyId === 7 && $work->meliAccountId === 19 && $id === '201', 'question exact perdió scope/id');
    return 901;
});
$questionResult = $question->executeOne(catalogWork('question_exact', ['question_id' => '201']), $questionContext);
catalogAssert($questionContext->logicalCallCount() === 1 && $questionResult->metadata['local_id'] === 901, 'question exact no fue unitario');

$children = [];
$itemsContext = catalogRemote();
$items = new ItemsSearchPageHandler(
    static function (WorkEnvelope $work, int $limit, int $offset): array {
        catalogAssert($work->companyId === 7 && $work->meliAccountId === 19, 'items perdió el scope');
        catalogAssert($limit === 20 && $offset === 20, 'items no aplicó página máxima 20');
        return ['results' => ['MCO1', 'MCO2'], 'paging' => ['total' => 50]];
    },
    static function (WorkEnvelope $child) use (&$children): void { $children[] = $child; }
);
$items->executeOne(catalogWork('items_search_page', ['limit' => 40, 'offset' => 20]), $itemsContext);
catalogAssert($itemsContext->logicalCallCount() === 1, 'items hizo más de una llamada lógica');
catalogAssert(array_map(static fn (WorkEnvelope $w): string => $w->workType, $children) === [
    'item_exact', 'item_exact', 'items_search_page',
], 'items no separó descubrimiento y exactos');

$itemContext = catalogRemote();
$item = new ItemExactHandler(static function (WorkEnvelope $work, string $id): int {
    catalogAssert($work->companyId === 7 && $work->meliAccountId === 19 && $id === 'MCO1', 'item exact perdió scope/id');
    return 301;
});
$itemResult = $item->executeOne(catalogWork('item_exact', ['external_item_id' => 'MCO1']), $itemContext);
catalogAssert($itemContext->logicalCallCount() === 1 && $itemResult->metadata['local_item_id'] === 301, 'item exact no fue unitario');

$descriptionContext = catalogRemote();
$description = new CatalogDescriptionExactHandler(
    static function (WorkEnvelope $work, int $localId, string $externalId): array {
        catalogAssert($work->companyId === 7 && $work->meliAccountId === 19, 'description perdió el scope');
        catalogAssert($localId === 301 && $externalId === 'MCO1', 'description perdió identidades');
        return ['source_status' => 'confirmed'];
    }
);
$descriptionResult = $description->executeOne(catalogWork('catalog_description_exact', [
    'meli_item_id' => 301,
    'external_item_id' => 'MCO1',
]), $descriptionContext);
catalogAssert($descriptionContext->logicalCallCount() === 1 && $descriptionResult->status === 'completed', 'description no fue unitaria');

$preflightCount = 0;
$transportCount = 0;
$logisticsContext = catalogRemote();
$logistics = new ModuleLogisticsExactHandler(
    static function (WorkEnvelope $work, string $id) use (&$preflightCount): void {
        catalogAssert($work->companyId === 7 && $work->meliAccountId === 19 && $id === '7001', 'logistics perdió scope/id');
        $preflightCount++;
    },
    static function (WorkEnvelope $work, string $id) use (&$transportCount): array {
        $transportCount++;
        return ['processed' => 1, 'errors' => 0, 'status' => 'completed'];
    }
);
$logisticsResult = $logistics->executeOne(catalogWork('module_logistics_exact', ['shipment_id' => '7001']), $logisticsContext);
catalogAssert($preflightCount === 1 && $transportCount === 1, 'logistics no hizo preflight + transporte exacto');
catalogAssert($logisticsContext->logicalCallCount() === 1 && $logisticsResult->status === 'completed', 'logistics no fue unitario');

$availability = CronV3DefaultHandlerBootstrap::availability();
catalogAssert(($availability['sales_fiscal_exact']['enabled'] ?? true) === false, 'fiscal se habilitó sin contrato exacto');
catalogAssert(
    ($availability['sales_fiscal_exact']['reason'] ?? '') === 'legacy_service_claims_unscoped_due_job_and_has_no_public_exact_persistence_contract',
    'fiscal no documentó el bloqueo técnico'
);

$registry = new CronV3HandlerRegistry(new CronV3WorkTypeRegistry());
CronV3DefaultHandlerBootstrap::register($registry);
$remoteTypes = $registry->typesForLane('remote');
$localTypes = $registry->typesForLane('local');
foreach (array_filter($availability, static fn (array $row): bool => $row['enabled']) as $type => $row) {
    $laneTypes = ((string) ($row['lane'] ?? 'remote')) === 'local' ? $localTypes : $remoteTypes;
    catalogAssert(in_array((string) $type, $laneTypes, true), 'Falta registrar handler habilitable: ' . $type);
}
catalogAssert(!in_array('sales_fiscal_exact', $remoteTypes, true), 'Fiscal no debe registrarse todavía');

echo "cron_v3_handlers_catalog_2290_ok\n";
