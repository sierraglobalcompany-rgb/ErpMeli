<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use RuntimeException;
use Throwable;

final class QueueV4CleanTransportContext
{
    /** @var array{phase:string,company_id:int,meli_account_id:int}|null */
    private static ?array $current = null;

    /** @template T @param callable():T $callback @return T */
    public static function runReadiness(int $companyId, int $accountId, callable $callback): mixed
    {
        if (self::$current !== null || $companyId < 1 || $accountId < 1) {
            throw new RuntimeException('queue_v4_clean_transport_context_invalid');
        }
        self::$current = [
            'phase' => 'readiness',
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
        ];
        try {
            return $callback();
        } finally {
            self::$current = null;
        }
    }

    public static function allowsReadinessGet(string $method, string $path, int $accountId): bool
    {
        return self::$current !== null
            && self::$current['phase'] === 'readiness'
            && self::$current['meli_account_id'] === $accountId
            && strtoupper($method) === 'GET'
            && '/' . ltrim($path, '/') === '/users/me';
    }

    public static function readinessActive(): bool
    {
        return self::$current !== null && self::$current['phase'] === 'readiness';
    }
}
