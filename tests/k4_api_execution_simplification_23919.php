<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$allowed = static function (string $source, string $method, string $path): bool {
    try {
        App\Services\MeliTransportSourcePolicy::assertAllowed($source, $method, $path);
        return true;
    } catch (RuntimeException) {
        return false;
    }
};

$active = [
    App\Services\MeliTransportSourcePolicy::QUEUE_V4_OAUTH => ['POST', '/oauth/token', 'GET', '/orders/search'],
    'queue_v4_clean' => ['GET', '/orders/123', 'GET', '/billing/integration/group/ML/order/details'],
    App\Services\MeliTransportSourcePolicy::QUEUE_V4_SALES_AUDIT => ['GET', '/orders/search', 'GET', '/orders/123'],
    App\Services\MeliTransportSourcePolicy::QUEUE_V4_SALES_REPAIR => ['GET', '/orders/123', 'GET', '/orders/search'],
    App\Services\MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT => ['GET', '/billing/integration/group/ML/order/details', 'GET', '/orders/search'],
];
foreach ($active as $source => [$okMethod, $okPath, $badMethod, $badPath]) {
    $capabilities = App\Services\MeliTransportSourcePolicy::capabilities((string) $source);
    $assert($capabilities['known'], 'SOURCE_NOT_KNOWN:' . $source);
    $assert($capabilities['target'] === 'active_automatic', 'ACTIVE_SOURCE_TARGET_DRIFT:' . $source);
    $assert($allowed((string) $source, (string) $okMethod, (string) $okPath), 'ACTIVE_SOURCE_ALLOWED_ENDPOINT_DENIED:' . $source);
    $assert(!$allowed((string) $source, (string) $badMethod, (string) $badPath), 'ACTIVE_SOURCE_PERMISSION_EXPANDED:' . $source);
}

$assert(!$allowed('not_registered_anywhere', 'GET', '/orders/search'), 'UNKNOWN_SOURCE_NOT_FAIL_CLOSED');
$assert(!$allowed(App\Services\MeliTransportSourcePolicy::QUEUE_V4_OAUTH, 'GET', '/users/me'), 'OAUTH_SOURCE_CAN_READ_BUSINESS_ENDPOINT');
$assert(!$allowed(App\Services\MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT, 'GET', '/orders/123'), 'DOMAIN_EXACT_CAN_READ_ORDER_EXACT');

$readiness = App\Services\MeliTransportSourcePolicy::capabilities('queue_v4_clean_readiness');
$assert($readiness['known'], 'READINESS_SOURCE_NOT_KNOWN');
$assert($readiness['target'] === 'preflight_readiness', 'READINESS_SOURCE_TARGET_NOT_PREFLIGHT');
$assert($readiness['primary_rhythm'], 'READINESS_SOURCE_PRIMARY_RHYTHM_DISABLED');
$assert(!$readiness['queue_rate_limit_deferral'], 'READINESS_SOURCE_CAN_DEFER_QUEUE');
$assert(!$readiness['queue_v4_read_fence'], 'READINESS_SOURCE_USES_QUEUE_POINTER_FENCE');
$assert($allowed('queue_v4_clean_readiness', 'GET', '/users/me'), 'READINESS_USERS_ME_DENIED');
$assert(!$allowed('queue_v4_clean_readiness', 'GET', '/orders/search'), 'READINESS_CAN_READ_BUSINESS_ENDPOINT');
$assert(!$allowed('queue_v4_clean_readiness', 'POST', '/oauth/token'), 'READINESS_CAN_REFRESH_OAUTH');

$module = App\Services\MeliTransportSourcePolicy::capabilities('module:meli-safe-local');
$assert(!$module['known'], 'MODULE_WILDCARD_STILL_AUTHORIZES_SOURCE');
$assert(!$module['module_source'], 'MODULE_WILDCARD_STILL_CLASSIFIES_SOURCE');
$assert(!$allowed('module:meli-safe-local', 'GET', '/orders/search'), 'MODULE_WILDCARD_STILL_ALLOWS_TRANSPORT');
$assert(!$allowed('module:unknown', 'GET', '/orders/search'), 'UNKNOWN_MODULE_SOURCE_NOT_FAIL_CLOSED');

$queueV4 = App\Services\MeliTransportSourcePolicy::capabilities('queue_v4_clean');
$assert($queueV4['primary_rhythm'], 'QUEUE_V4_PRIMARY_RHYTHM_DISABLED');
$assert(!$queueV4['pacing_compatibility'], 'QUEUE_V4_CAN_USE_LEGACY_PACING');
$assert($queueV4['queue_v4_read_fence'], 'QUEUE_V4_READ_FENCE_MISSING');

$legacy = App\Services\MeliTransportSourcePolicy::capabilities('cron_v3_remote');
$assert($legacy['known'], 'LEGACY_CRON_V3_NOT_CLASSIFIED');
$assert($legacy['legacy_cron_v3'], 'LEGACY_CRON_V3_FLAG_MISSING');
$assert($legacy['target'] === 'logically_retired', 'LEGACY_CRON_V3_NOT_LOGICALLY_RETIRED');
$assert($legacy['pacing_compatibility'], 'LEGACY_CRON_V3_COMPATIBILITY_BOUNDARY_MISSING');
$assert(!$legacy['primary_rhythm'], 'LEGACY_CRON_V3_PRIMARY_RHYTHM_ENABLED');

$logistics = App\Services\MeliTransportSourcePolicy::capabilities(App\Services\MeliTransportSourcePolicy::MODULE_MELI_LOGISTICS);
$assert($logistics['known'], 'MODULE_LOGISTICS_SOURCE_NOT_KNOWN');
$assert($logistics['target'] === 'module_confirmed', 'MODULE_LOGISTICS_TARGET_DRIFT');
$assert($logistics['module_source'], 'MODULE_LOGISTICS_NOT_MODULE_SOURCE');
$assert($logistics['single_dispatch'], 'MODULE_LOGISTICS_NOT_SINGLE_DISPATCH');
$assert($logistics['blocks_redirects'], 'MODULE_LOGISTICS_REDIRECTS_NOT_BLOCKED');
$assert(!$logistics['queue_v4_read_fence'], 'MODULE_LOGISTICS_GOT_QUEUE_FENCE');
$assert(!$logistics['current_oauth_fence'], 'MODULE_LOGISTICS_GOT_OAUTH_FENCE');
$assert(!$logistics['queue_rate_limit_deferral'], 'MODULE_LOGISTICS_GOT_QUEUE_RATE_DEFERRAL');
$assert($logistics['pacing_compatibility'], 'MODULE_LOGISTICS_LOST_PACING_COMPATIBILITY');
$assert($allowed(App\Services\MeliTransportSourcePolicy::MODULE_MELI_LOGISTICS, 'GET', '/shipments/123'), 'MODULE_LOGISTICS_SHIPMENT_DENIED');
$assert(!$allowed(App\Services\MeliTransportSourcePolicy::MODULE_MELI_LOGISTICS, 'GET', '/orders/123'), 'MODULE_LOGISTICS_CAN_READ_ORDERS');
$assert(!$allowed(App\Services\MeliTransportSourcePolicy::MODULE_MELI_LOGISTICS, 'GET', '/orders/search'), 'MODULE_LOGISTICS_CAN_SEARCH_ORDERS');
$assert(!$allowed(App\Services\MeliTransportSourcePolicy::MODULE_MELI_LOGISTICS, 'GET', '/users/me'), 'MODULE_LOGISTICS_CAN_READ_USERS_ME');
$assert(!$allowed(App\Services\MeliTransportSourcePolicy::MODULE_MELI_LOGISTICS, 'GET', '/billing/integration/group/ML/order/details'), 'MODULE_LOGISTICS_CAN_READ_BILLING');

$client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
$policy = (string) file_get_contents($root . '/app/Services/MeliTransportSourcePolicy.php');
$assert(str_contains($client, '$transportCapabilities = MeliTransportSourcePolicy::capabilities($source);'), 'CLIENT_DOES_NOT_RESOLVE_SOURCE_ONCE');
$assert(substr_count($client, 'MeliTransportSourcePolicy::capabilities($source)') === 1, 'CLIENT_REINTERPRETS_SOURCE_AFTER_SNAPSHOT');
$assert(!str_contains($client, "'cron_v3_rate_gate'"), 'CLIENT_STILL_HAS_CRON_V3_RHYTHM_BYPASS');
$assert(!str_contains($client, "=== 'cron_v3_remote'"), 'CLIENT_STILL_INTERPRETS_CRON_V3_LITERAL');
$assert(!str_contains($client, "=== 'queue_core'"), 'CLIENT_STILL_INTERPRETS_QUEUE_CORE_LITERAL');
$assert(!str_contains($client, 'MeliTransportSourcePolicy::requiresCurrentOAuthFence($source)'), 'CLIENT_RECHECKS_OAUTH_SOURCE_POLICY_AFTER_SNAPSHOT');
$assert(str_contains($client, '$pacingCompatibility && empty($rhythmPermit[\'enabled\'])'), 'PACING_COMPATIBILITY_NOT_EXPLICIT');
$assert(str_contains($client, 'pacing_authority_unavailable'), 'PACING_COMPATIBILITY_NOT_FAIL_CLOSED');
$assert(str_contains($client, '$primaryRhythmAuthority'), 'PRIMARY_RHYTHM_SNAPSHOT_NOT_USED');
$assert(str_contains($client, '$queueRateLimitDeferral'), 'QUEUE_RATE_LIMIT_DEFERRAL_SNAPSHOT_NOT_USED');
$assert(substr_count($policy, 'active_automatic') >= 5, 'ACTIVE_AUTOMATIC_SOURCES_NOT_EXPLICIT');
$assert(!str_contains($policy, 'preg_match(\'/^module:meli-'), 'MODULE_SOURCE_REGEX_STILL_PRESENT');

$transport = (string) file_get_contents($root . '/app/Services/CurlMeliHttpTransport.php');
$assert(substr_count($transport, 'curl_exec(') === 1, 'PHYSICAL_TRANSPORT_NOT_SINGLE_CURL_EXEC');
$assert(str_contains($transport, 'MeliTransportSourcePolicy::assertAllowed'), 'TRANSPORT_LAYER_LACKS_SOURCE_ASSERTION');

$direct = [];
$paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($paths as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $source = (string) file_get_contents($file->getPathname());
    $looksLikeMeliTransport = str_contains($source, 'api.mercadolibre.com')
        || str_contains($source, 'MELI_API_BASE')
        || str_contains($source, 'Mercado Libre API');
    if ($looksLikeMeliTransport
        && str_contains($source, 'curl_exec(')
        && $relative !== 'app/Services/CurlMeliHttpTransport.php') {
        $direct[] = $relative;
    }
}
$assert($direct === [], 'UNAUTHORIZED_DIRECT_CURL_EXEC:' . implode(',', $direct));

fwrite(STDOUT, 'K4_API_EXECUTION_SIMPLIFICATION_23919=PASS checks=' . $checks . PHP_EOL);
