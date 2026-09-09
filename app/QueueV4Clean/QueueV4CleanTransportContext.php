<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use RuntimeException;
use Throwable;
use App\Services\ApiExecutionMetadataContext;

final class QueueV4CleanTransportContext
{
    /** @var array{phase:string,company_id:int,meli_account_id:int}|null */
    private static ?array $current = null;
    private static ?\Closure $guard = null;
    /** Private, request-local evidence; never hydrated from caller metadata. */
    private static ?array $transportEvidence = null;

    /** @template T @param callable():T $callback @return T */
    public static function runReadiness(int $companyId, int $accountId, callable $callback, ?callable $guard = null): mixed
    {
        if (self::$current !== null || $companyId < 1 || $accountId < 1) {
            throw new RuntimeException('queue_v4_clean_transport_context_invalid');
        }
        self::$current = [
            'phase' => 'readiness',
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
        ];
        self::$guard = $guard === null ? null : \Closure::fromCallable($guard);
        try {
            return $callback();
        } finally {
            self::$current = null;
            self::$guard = null;
            self::$transportEvidence = null;
        }
    }

    public static function assertBeforeTransport(string $method, string $path, int $accountId): void
    {
        $meta = ApiExecutionMetadataContext::current();
        if (!self::allowsReadinessGet($method, $path, $accountId) || self::$guard === null
            || (int) ($meta['company_id'] ?? 0) !== self::$current['company_id']
            || (int) ($meta['account_id'] ?? 0) !== $accountId
            || (string) ($meta['source'] ?? '') !== 'queue_v4_clean_readiness') {
            throw new RuntimeException('queue_v4_clean_readiness_transport_guard_required');
        }
        (self::$guard)();
    }

    /** Wrap only the actual cURL adapter, including its local preparation. */
    public static function captureReadinessTransport(callable $callback): mixed
    {
        $meta = ApiExecutionMetadataContext::current();
        $id = (string) ($meta['transport_request_id'] ?? '');
        if (!self::readinessActive() || self::$transportEvidence !== null
            || !preg_match('/^[a-f0-9]{40}$/D', $id)
            || (int) ($meta['company_id'] ?? 0) !== self::$current['company_id']
            || (int) ($meta['transport_meli_account_id'] ?? 0) !== self::$current['meli_account_id']) {
            throw new RuntimeException('queue_v4_clean_readiness_evidence_context_invalid');
        }
        self::$transportEvidence = self::$current + ['request_id'=>$id,'state'=>'preparing'];
        try {
            return $callback();
        } catch (Throwable $error) {
            if (self::$transportEvidence['state'] === 'preparing'
                && !$error instanceof \App\Services\RemoteResultUncertainException) {
                self::$transportEvidence['state'] = 'cancelled';
            }
            throw $error;
        }
    }

    public static function readinessEnteringTransport(): void
    {
        if (self::$transportEvidence !== null) {self::$transportEvidence['state'] = 'sent';}
    }

    public static function consumePreTransportCancellation(string $requestId, int $companyId, int $accountId): bool
    {
        if (self::$transportEvidence === null || self::$transportEvidence['state'] !== 'cancelled'
            || self::$transportEvidence['request_id'] !== $requestId
            || self::$transportEvidence['company_id'] !== $companyId
            || self::$transportEvidence['meli_account_id'] !== $accountId) {return false;}
        self::$transportEvidence['state'] = 'consumed';
        return true;
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
