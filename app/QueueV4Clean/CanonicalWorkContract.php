<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

/**
 * Neutral data-plane contract for executable ERP work.
 *
 * Queue V4 remains the current storage adapter, but business modules and any
 * future drainer should speak these canonical work types instead of depending
 * on Queue V4's storage detail (`domain_exact` + payload capability).
 */
final class CanonicalWorkContract
{
    public const VERSION = 1;
    public const PAYLOAD_VERSION_KEY = 'work_contract_version';

    public const TYPE_FRESH_ORDERS_DISCOVERY = 'fresh_orders_discovery';
    public const TYPE_ORDER_EXACT = 'order_exact';
    public const TYPE_FINANCIAL_RECALC = 'financial_recalc';
    public const TYPE_FINANCIAL_RECONCILIATION = 'financial_reconciliation';

    public const DRAIN_LEASE_KEY = 'canonical_fifo_drainer';
    public const DRAIN_LEASE_TTL_SECONDS = 60;

    /** @return list<string> */
    public static function supportedTypes(): array
    {
        return [
            self::TYPE_FRESH_ORDERS_DISCOVERY,
            self::TYPE_ORDER_EXACT,
            self::TYPE_FINANCIAL_RECALC,
            self::TYPE_FINANCIAL_RECONCILIATION,
        ];
    }

    /** @param array<string,mixed> $work */
    public static function canonicalType(array $work): ?string
    {
        $jobType = (string) ($work['job_type'] ?? '');
        if ($jobType === self::TYPE_FRESH_ORDERS_DISCOVERY || $jobType === self::TYPE_ORDER_EXACT) {
            return $jobType;
        }
        if ($jobType !== 'domain_exact') {
            return null;
        }
        $payload = $work['payload'] ?? null;
        if (!is_array($payload)) {
            $decoded = json_decode((string) ($work['payload_json'] ?? ''), true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $capability = (string) ($payload['capability'] ?? '');
        if ($capability === self::TYPE_FINANCIAL_RECALC || $capability === self::TYPE_FINANCIAL_RECONCILIATION) {
            return $capability;
        }
        return null;
    }

    /** @param array<string,mixed> $work */
    public static function versionOf(array $work): int
    {
        $payload = $work['payload'] ?? null;
        if (!is_array($payload)) {
            $decoded = json_decode((string) ($work['payload_json'] ?? ''), true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $version = $payload[self::PAYLOAD_VERSION_KEY] ?? null;
        if ($version === null || $version === '') {
            return self::VERSION;
        }
        if (is_int($version)) {
            return $version;
        }
        if (is_string($version) && ctype_digit($version)) {
            return (int) $version;
        }
        return -1;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function payloadWithVersion(array $payload): array
    {
        if (!array_key_exists(self::PAYLOAD_VERSION_KEY, $payload)) {
            $payload[self::PAYLOAD_VERSION_KEY] = self::VERSION;
        }
        return $payload;
    }

    /** @param array<string,mixed> $work @param list<string> $supportedTypes */
    public static function isSupportedBy(array $work, int $supportedVersion, array $supportedTypes): bool
    {
        $canonicalType = self::canonicalType($work);
        return self::versionOf($work) === $supportedVersion
            && $canonicalType !== null
            && in_array($canonicalType, $supportedTypes, true);
    }
}
