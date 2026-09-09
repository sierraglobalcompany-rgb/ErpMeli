<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Exact CURRENT transport capabilities for hardened automated sources. */
final class MeliTransportSourcePolicy
{
    public const QUEUE_V4_OAUTH = 'queue_v4_clean_oauth';
    public const QUEUE_V4_SALES_AUDIT = 'queue_v4_clean_sales_audit';
    public const QUEUE_V4_SALES_REPAIR = 'queue_v4_clean_sales_repair';
    public const QUEUE_V4_DOMAIN_EXACT = 'queue_v4_clean_domain_exact';

    /** @return array{known:bool,single_dispatch:bool,blocks_redirects:bool,queue_rate_limit_deferral:bool,current_oauth_fence:bool} */
    public static function capabilities(string $source): array
    {
        $hardened = [
            'cron' => [false, false, false, false],
            'web' => [false, false, false, false],
            'queue_core' => [true, true, true, false],
            'queue_core_webhook' => [true, true, true, false],
            'queue_v4_clean' => [true, true, true, false],
            self::QUEUE_V4_SALES_AUDIT => [true, true, true, false],
            self::QUEUE_V4_SALES_REPAIR => [true, true, true, false],
            self::QUEUE_V4_DOMAIN_EXACT => [true, true, true, false],
            'queue_v4_clean_readiness' => [true, true, true, false],
            'cron_v3_remote' => [true, true, false, false],
            'cron_v3' => [true, true, false, false],
            'manual_campaign' => [true, false, false, false],
            'manual_exact' => [true, true, false, false],
            'webhook_worker' => [true, true, false, false],
            'manual_emergency_canary' => [true, true, false, false],
            'manual_emergency_oauth_refresh' => [true, true, false, false],
            self::QUEUE_V4_OAUTH => [true, true, true, true],
        ];
        $row = str_starts_with($source, 'module:')
            ? [true, true, false, false]
            : ($hardened[$source] ?? null);
        return [
            'known' => $row !== null,
            'single_dispatch' => (bool) ($row[0] ?? false),
            'blocks_redirects' => (bool) ($row[1] ?? false),
            'queue_rate_limit_deferral' => (bool) ($row[2] ?? false),
            'current_oauth_fence' => (bool) ($row[3] ?? false),
        ];
    }

    public static function assertAllowed(string $source, string $method, string $path): void
    {
        if (\App\QueueV4Clean\QueueV4CleanCycleBudget::snapshot()['limit'] > 0
            && $source !== 'queue_core'
            && !self::authorizedTechnicalPath($source, $method, $path)
            && !self::requiresQueueV4ReadFence($source) && !self::requiresCurrentOAuthFence($source)) {
            throw new RuntimeException('queue_v4_clean_cycle_transport_source_denied');
        }
        if (!self::capabilities($source)['known']) {
            throw new RuntimeException('meli_transport_source_unknown');
        }
        $path = '/' . ltrim((string) (parse_url($path, PHP_URL_PATH) ?: $path), '/');
        if ($source === 'queue_v4_clean') {
            if (strtoupper($method) !== 'GET'
                || (!hash_equals('/orders/search', $path) && preg_match('#^/orders/[0-9]+$#D', $path) !== 1)) {
                throw new RuntimeException('queue_v4_clean_transport_capability_denied');
            }
            return;
        }
        if ($source === self::QUEUE_V4_SALES_AUDIT) {
            if (strtoupper($method) !== 'GET' || !hash_equals('/orders/search', $path)) {
                throw new RuntimeException('queue_v4_clean_sales_transport_capability_denied');
            }
            return;
        }
        if ($source === self::QUEUE_V4_SALES_REPAIR) {
            if (strtoupper($method) !== 'GET' || preg_match('#^/orders/[0-9]+$#D', $path) !== 1) {
                throw new RuntimeException('queue_v4_clean_sales_repair_transport_capability_denied');
            }
            return;
        }
        if ($source === self::QUEUE_V4_DOMAIN_EXACT) {
            if (!self::allowsDomainExact($method, $path)) {
                throw new RuntimeException('queue_v4_clean_domain_transport_capability_denied');
            }
            return;
        }
        if ($source !== self::QUEUE_V4_OAUTH) {
            return;
        }
        if (strtoupper($method) !== 'POST' || !hash_equals('/oauth/token', $path)) {
            throw new RuntimeException('queue_v4_clean_oauth_transport_capability_denied');
        }
    }

    public static function authorizedTechnicalPath(string $source, string $method, string $path): bool
    {
        if (ApiExecutionMetadataContext::technicalOperation() === 'oauth_profile') {
            $meta = ApiExecutionMetadataContext::current();
            if ((int)($meta['company_id'] ?? 0)<1 || (int)($meta['account_id'] ?? 0)<1) { return false; }
        }
        $expected = match (ApiExecutionMetadataContext::technicalOperation()) {
            'readiness' => ['queue_v4_clean_readiness','GET','/users/me'],
            'emergency_canary' => ['manual_emergency_canary','GET','/users/me'],
            'emergency_oauth' => ['manual_emergency_oauth_refresh','POST','/oauth/token'],
            'initial_oauth' => ['web','POST','/oauth/token'],
            'oauth_profile' => ['web','GET','/users/me'],
            default => null,
        };
        $path = '/' . ltrim((string) (parse_url($path, PHP_URL_PATH) ?: $path), '/');
        return $expected !== null && $expected === [$source,strtoupper($method),$path];
    }

    /** Closed GET map; identity is supplied by the tenant-bound source row. */
    public static function allowsDomainExact(string $method, string $path): bool
    {
        if (strtoupper($method) !== 'GET') {
            return false;
        }
        $meta = ApiExecutionMetadataContext::current();
        $type = (string) ($meta['domain_resource_type'] ?? '');
        $id = (string) ($meta['domain_remote_resource_id'] ?? '');
        if ($type === '' && $id === '') {
            return hash_equals('/billing/integration/group/ML/order/details', $path);
        }
        $prefix = match ($type) {
            'pack' => '/packs/', 'order' => '/orders/', 'shipment' => '/shipments/',
            'question' => '/questions/', 'claim' => '/post-purchase/v1/claims/', 'item' => '/items/',
            default => null,
        };
        return $prefix !== null && self::domainEndpointKey($path) !== null
            && hash_equals($prefix . $id, $path);
    }

    public static function domainEndpointKey(string $path): ?string
    {
        return match (true) {
            hash_equals('/billing/integration/group/ML/order/details', $path) => 'billing_orders',
            preg_match('#^/packs/[0-9]+$#D', $path) === 1 => 'pack_exact',
            preg_match('#^/orders/[0-9]+$#D', $path) === 1 => 'order_exact',
            preg_match('#^/shipments/[0-9]+$#D', $path) === 1 => 'shipment_exact',
            preg_match('#^/questions/[0-9]+$#D', $path) === 1 => 'question_exact',
            preg_match('#^/post-purchase/v1/claims/[0-9]+$#D', $path) === 1 => 'claim_exact',
            preg_match('#^/items/[A-Z]{2,4}[0-9]+$#D', $path) === 1 => 'item_exact',
            default => null,
        };
    }

    public static function isSingleDispatch(string $source): bool
    {
        return self::capabilities($source)['single_dispatch'];
    }

    public static function blocksRedirects(string $source): bool
    {
        return self::capabilities($source)['blocks_redirects'];
    }

    public static function usesQueueRateLimitDeferral(string $source): bool
    {
        return self::capabilities($source)['queue_rate_limit_deferral'];
    }

    public static function usesPrimaryRhythmAuthority(string $source): bool
    {
        return in_array($source, [
            'queue_v4_clean',
            self::QUEUE_V4_SALES_AUDIT,
            self::QUEUE_V4_SALES_REPAIR,
            self::QUEUE_V4_DOMAIN_EXACT,
            'manual_campaign',
        ], true);
    }

    public static function requiresCurrentOAuthFence(string $source): bool
    {
        return self::capabilities($source)['current_oauth_fence'];
    }

    public static function requiresQueueV4ReadFence(string $source): bool
    {
        return $source === 'queue_v4_clean'
            || $source === self::QUEUE_V4_SALES_AUDIT
            || $source === self::QUEUE_V4_SALES_REPAIR
            || $source === self::QUEUE_V4_DOMAIN_EXACT;
    }
}
