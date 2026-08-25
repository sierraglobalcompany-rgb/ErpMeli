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

    /**
     * @return array{
     *   known:bool,
     *   single_dispatch:bool,
     *   blocks_redirects:bool,
     *   queue_rate_limit_deferral:bool,
     *   current_oauth_fence:bool,
     *   queue_v4_read_fence:bool,
     *   primary_rhythm:bool,
     *   pacing_compatibility:bool,
     *   legacy_cron_v3:bool,
     *   queue_core_context:bool,
     *   manual_emergency_canary:bool,
     *   manual_emergency_oauth_refresh:bool,
     *   module_source:bool,
     *   target:string
     * }
     */
    public static function capabilities(string $source): array
    {
        $hardened = [
            'cron' => ['single_dispatch' => false, 'blocks_redirects' => false, 'queue_rate_limit_deferral' => false, 'current_oauth_fence' => false, 'pacing_compatibility' => true, 'target' => 'compatibility_only'],
            'web' => ['single_dispatch' => false, 'blocks_redirects' => false, 'queue_rate_limit_deferral' => false, 'current_oauth_fence' => false, 'pacing_compatibility' => true, 'target' => 'manual_only'],
            'queue_core' => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => true, 'current_oauth_fence' => false, 'pacing_compatibility' => false, 'queue_core_context' => true, 'target' => 'compatibility_only'],
            'queue_core_webhook' => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => true, 'current_oauth_fence' => false, 'pacing_compatibility' => false, 'queue_core_context' => true, 'target' => 'compatibility_only'],
            'queue_v4_clean' => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => true, 'current_oauth_fence' => false, 'queue_v4_read_fence' => true, 'primary_rhythm' => true, 'pacing_compatibility' => false, 'target' => 'active_automatic'],
            self::QUEUE_V4_SALES_AUDIT => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => true, 'current_oauth_fence' => false, 'queue_v4_read_fence' => true, 'primary_rhythm' => true, 'pacing_compatibility' => false, 'target' => 'active_automatic'],
            self::QUEUE_V4_SALES_REPAIR => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => true, 'current_oauth_fence' => false, 'queue_v4_read_fence' => true, 'primary_rhythm' => true, 'pacing_compatibility' => false, 'target' => 'active_automatic'],
            self::QUEUE_V4_DOMAIN_EXACT => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => true, 'current_oauth_fence' => false, 'queue_v4_read_fence' => true, 'primary_rhythm' => true, 'pacing_compatibility' => false, 'target' => 'active_automatic'],
            'queue_v4_clean_readiness' => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => false, 'current_oauth_fence' => false, 'queue_v4_read_fence' => false, 'primary_rhythm' => true, 'pacing_compatibility' => false, 'target' => 'preflight_readiness'],
            'cron_v3_remote' => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => false, 'current_oauth_fence' => false, 'pacing_compatibility' => true, 'legacy_cron_v3' => true, 'target' => 'logically_retired'],
            'cron_v3' => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => false, 'current_oauth_fence' => false, 'pacing_compatibility' => true, 'legacy_cron_v3' => true, 'target' => 'logically_retired'],
            'manual_campaign' => ['single_dispatch' => true, 'blocks_redirects' => false, 'queue_rate_limit_deferral' => false, 'current_oauth_fence' => false, 'primary_rhythm' => true, 'pacing_compatibility' => false, 'target' => 'manual_only'],
            'manual_exact' => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => false, 'current_oauth_fence' => false, 'pacing_compatibility' => true, 'target' => 'manual_only'],
            'webhook_worker' => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => false, 'current_oauth_fence' => false, 'pacing_compatibility' => true, 'target' => 'compatibility_only'],
            'manual_emergency_canary' => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => false, 'current_oauth_fence' => false, 'pacing_compatibility' => true, 'manual_emergency_canary' => true, 'target' => 'manual_only'],
            'manual_emergency_oauth_refresh' => ['single_dispatch' => true, 'blocks_redirects' => false, 'queue_rate_limit_deferral' => false, 'current_oauth_fence' => false, 'pacing_compatibility' => true, 'manual_emergency_oauth_refresh' => true, 'target' => 'manual_only'],
            self::QUEUE_V4_OAUTH => ['single_dispatch' => true, 'blocks_redirects' => true, 'queue_rate_limit_deferral' => true, 'current_oauth_fence' => true, 'pacing_compatibility' => false, 'target' => 'active_automatic'],
        ];
        $row = $hardened[$source] ?? null;
        return [
            'known' => $row !== null,
            'single_dispatch' => (bool) ($row['single_dispatch'] ?? false),
            'blocks_redirects' => (bool) ($row['blocks_redirects'] ?? false),
            'queue_rate_limit_deferral' => (bool) ($row['queue_rate_limit_deferral'] ?? false),
            'current_oauth_fence' => (bool) ($row['current_oauth_fence'] ?? false),
            'queue_v4_read_fence' => (bool) ($row['queue_v4_read_fence'] ?? false),
            'primary_rhythm' => (bool) ($row['primary_rhythm'] ?? false),
            'pacing_compatibility' => (bool) ($row['pacing_compatibility'] ?? false),
            'legacy_cron_v3' => (bool) ($row['legacy_cron_v3'] ?? false),
            'queue_core_context' => (bool) ($row['queue_core_context'] ?? false),
            'manual_emergency_canary' => (bool) ($row['manual_emergency_canary'] ?? false),
            'manual_emergency_oauth_refresh' => (bool) ($row['manual_emergency_oauth_refresh'] ?? false),
            'module_source' => (bool) ($row['module_source'] ?? false),
            'target' => (string) ($row['target'] ?? 'unknown'),
        ];
    }

    public static function assertAllowed(string $source, string $method, string $path): void
    {
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
            if (strtoupper($method) !== 'GET'
                || !hash_equals('/billing/integration/group/ML/order/details', $path)) {
                throw new RuntimeException('queue_v4_clean_domain_transport_capability_denied');
            }
            return;
        }
        if ($source === 'queue_v4_clean_readiness') {
            if (strtoupper($method) !== 'GET' || !hash_equals('/users/me', $path)) {
                throw new RuntimeException('queue_v4_clean_readiness_transport_capability_denied');
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
        return self::capabilities($source)['primary_rhythm'];
    }

    public static function requiresCurrentOAuthFence(string $source): bool
    {
        return self::capabilities($source)['current_oauth_fence'];
    }

    public static function requiresQueueV4ReadFence(string $source): bool
    {
        return self::capabilities($source)['queue_v4_read_fence'];
    }

    public static function requiresPacingCompatibility(string $source): bool
    {
        return self::capabilities($source)['pacing_compatibility'];
    }

    public static function isQueueCoreContext(string $source): bool
    {
        return self::capabilities($source)['queue_core_context'];
    }

    public static function isLegacyCronV3(string $source): bool
    {
        return self::capabilities($source)['legacy_cron_v3'];
    }

    public static function isManualEmergencyCanary(string $source): bool
    {
        return self::capabilities($source)['manual_emergency_canary'];
    }

    public static function isManualEmergencyOAuthRefresh(string $source): bool
    {
        return self::capabilities($source)['manual_emergency_oauth_refresh'];
    }
}
