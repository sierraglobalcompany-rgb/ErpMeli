<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\Services\ApiExecutionMetadataContext;
use App\Services\MeliTransportSourcePolicy;

// Break caught: exact domain reads either rejected or escape their durable resource identity.
$cases = [
    ['pack', '100', '/packs/100'], ['order', '101', '/orders/101'],
    ['shipment', '102', '/shipments/102'], ['question', '103', '/questions/103'],
    ['claim', '104', '/post-purchase/v1/claims/104'], ['item', 'MCO105', '/items/MCO105'],
];
foreach ($cases as [$type, $id, $path]) {
    ApiExecutionMetadataContext::run([
        'source' => MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT,
        'domain_resource_type' => $type, 'domain_remote_resource_id' => $id,
    ], static function () use ($path, $type): void {
        MeliTransportSourcePolicy::assertAllowed(MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT, 'GET', $path);
        foreach ([['GET', $path . '9'], ['GET', $path . '/description'], ['POST', $path], ['GET', '/orders/search']] as [$method, $bad]) {
            $denied = false;
            try { MeliTransportSourcePolicy::assertAllowed(MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT, $method, $bad); }
            catch (RuntimeException) { $denied = true; }
            k1b_assert($denied, $type . '_mismatched_resource_or_secondary_denied');
        }
    });
}
QueueV4CleanCycleBudget::start(3);
try {
    foreach (['cron', 'web', 'webhook_worker', 'queue_core_webhook', 'manual_exact'] as $source) {
        $denied = false;
        try { MeliTransportSourcePolicy::assertAllowed($source, 'GET', '/orders/101'); }
        catch (RuntimeException) { $denied = true; }
        k1b_assert($denied, 'active_cycle_incompatible_source_denied_' . $source);
    }
    k1b_assert(QueueV4CleanCycleBudget::snapshot()['used'] === 0, 'rejected_sources_consume_zero');
} finally { QueueV4CleanCycleBudget::clear(); }
echo "STATUS=PASS CAP2_DOMAINS_POLICY\nREAL_MELI_HTTP=0\n";
