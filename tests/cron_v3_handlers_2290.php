<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronV3ExecutionContext;
use App\Services\CronV3Handlers\ClaimExactHandler;
use App\Services\CronV3Handlers\ClaimsSearchPageHandler;
use App\Services\CronV3Handlers\FinancialGapScanHandler;
use App\Services\CronV3Handlers\FinancialLocalProjectionHandler;
use App\Services\CronV3Handlers\FinancialRecalcHandler;
use App\Services\CronV3Handlers\OAuthRefreshHandler;
use App\Services\CronV3Handlers\OrderExactHandler;
use App\Services\CronV3Handlers\OrderResourceExactHandler;
use App\Services\CronV3Handlers\SaleBillingCaptureHandler;
use App\Services\WorkEnvelope;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$remote = static fn (): CronV3ExecutionContext => CronV3ExecutionContext::remote(
    static fn (): array => ['allowed' => true, 'retry_at' => null, 'reason' => 'test']
);
$work = static fn (string $type, string $lane, array $payload = []): WorkEnvelope =>
    WorkEnvelope::create(7, 19, $type, $lane, $type . ':resource', $type . ':v1', $payload);

$calls = 0;
$context = $remote();
(new OAuthRefreshHandler(static function () use (&$calls): array {
    $calls++;
    return ['ok' => true];
}))->executeOne($work('oauth_refresh', 'remote'), $context);
$assert($calls === 1 && $context->logicalCallCount() === 1, 'oauth_refresh must perform one logical remote call.');

$children = [];
$context = $remote();
$result = (new ClaimsSearchPageHandler(
    static fn (): array => ['claim_ids' => ['100', '101'], 'next_offset' => 20],
    static function (WorkEnvelope $child) use (&$children): array {
        $children[] = $child;
        return ['id' => count($children), 'created' => true, 'status' => 'ready'];
    }
))->executeOne($work('claims_search_page', 'remote', ['limit' => 20, 'offset' => 0]), $context);
$assert($context->logicalCallCount() === 1, 'claims_search_page must perform one logical remote call.');
$assert(count($children) === 3, 'claims_search_page must enqueue two exact claims and one next page.');
$assert($children[0]->workType === 'claim_exact' && $children[2]->workType === 'claims_search_page', 'Claims child mapping is invalid.');
$assert($result->metadata['claim_jobs_enqueued'] === 2, 'Claims materialization count is invalid.');

$remoteCases = [
    [new ClaimExactHandler(static fn (): int => 9), $work('claim_exact', 'remote', ['claim_id' => '100'])],
    [new OrderExactHandler(static fn (): int => 11), $work('order_exact', 'remote', ['external_order_id' => '200'])],
    [new SaleBillingCaptureHandler(static fn (): array => ['processed' => 1, 'completed' => 1]), $work('sale_billing_capture', 'remote', ['job_id' => 12])],
];
foreach ($remoteCases as [$handler, $envelope]) {
    $context = $remote();
    $handler->executeOne($envelope, $context);
    $assert($context->logicalCallCount() === 1, $envelope->workType . ' must perform exactly one logical remote call.');
}

$spawned = [];
$context = $remote();
(new OrderResourceExactHandler(
    'pack',
    static fn (): array => ['spawned_shipment_id' => '777'],
    static function (WorkEnvelope $child) use (&$spawned): array {
        $spawned[] = $child;
        return ['id' => 1, 'created' => true, 'status' => 'ready'];
    }
))->executeOne($work('pack_exact', 'remote', [
    'meli_order_id' => 44,
    'external_resource_id' => '555',
]), $context);
$assert($context->logicalCallCount() === 1, 'pack_exact must perform exactly one logical remote call.');
$assert(count($spawned) === 1 && $spawned[0]->workType === 'shipment_exact', 'pack_exact must enqueue its discovered shipment.');

$context = $remote();
(new OrderResourceExactHandler('shipment', static fn (): array => ['spawned_shipment_id' => null]))
    ->executeOne($work('shipment_exact', 'remote', [
        'meli_order_id' => 44,
        'external_resource_id' => '777',
    ]), $context);
$assert($context->logicalCallCount() === 1, 'shipment_exact must perform exactly one logical remote call.');

$local = CronV3ExecutionContext::local();
(new FinancialLocalProjectionHandler(static fn (): array => [
    'sale_key' => 'O:200',
    'provisional_status' => 'complete',
    'official_status' => 'missing',
]))->executeOne($work('financial_local_projection', 'local', ['meli_order_id' => 44]), $local);
(new FinancialRecalcHandler(static fn (): array => ['processed' => 1, 'errors' => 0, 'stop_reason' => 'complete']))
    ->executeOne($work('financial_recalc', 'local', ['job_id' => 12]), $local);
(new FinancialGapScanHandler(static fn (): array => [
    'read' => 50,
    'enqueued' => 20,
    'local_jobs' => 10,
    'billing_jobs' => 10,
    'next_cursor' => null,
]))->executeOne($work('financial_gap_scan', 'local'), $local);
$assert($local->logicalCallCount() === 0, 'Local finance handlers must never use remote transport.');

$root = dirname(__DIR__);
$adapter = file_get_contents($root . '/app/Services/CronV3LegacyQueueAdapter.php');
$bootstrap = file_get_contents($root . '/app/Services/CronV3DefaultHandlerBootstrap.php');
$kernel = file_get_contents($root . '/app/Services/CronV3.php');
$assert(
    str_contains((string) $kernel, 'CronV3DefaultHandlerBootstrap::register($this->handlers)'),
    'Cron V3 kernel must register the executable default handlers.'
);
$assert(is_string($adapter) && !str_contains($adapter, 'MeliApiClient'), 'Legacy adapter must not use Mercado Libre transport.');
$assert(str_contains($adapter, 'min(50, $readLimit)') && str_contains($adapter, 'min(20, $materializeLimit)'), 'Legacy adapter hard caps are missing.');
$assert(str_contains($adapter, 'owner_engine="v3"') && str_contains($adapter, 'cron_v3_snapshots'), 'Legacy ownership/checkpoint contract is missing.');
foreach (['oauth_refresh', 'claims_search_page', 'claim_exact', 'order_exact', 'pack_exact', 'shipment_exact',
          'financial_local_projection', 'financial_recalc', 'financial_gap_scan', 'sale_billing_capture'] as $type) {
    $assert(
        is_string($bootstrap) && str_contains($bootstrap, '$handlers->register(\'' . $type . '\''),
        "Missing bootstrap registration for {$type}."
    );
}

echo "cron_v3_handlers_2290: PASS\n";
