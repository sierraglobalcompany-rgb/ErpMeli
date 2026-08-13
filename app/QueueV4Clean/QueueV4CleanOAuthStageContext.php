<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\ApiExecutionMetadataContext;
use App\Services\MeliTransportSourcePolicy;
use RuntimeException;

/** Contexto de diagnóstico local; nunca contiene payloads ni credenciales. */
final class QueueV4CleanOAuthStageContext
{
    public const SCHEDULER_START = 'SCHEDULER_START';
    public const OAUTH_RUNTIME_PREFLIGHT = 'OAUTH_RUNTIME_PREFLIGHT';
    public const OAUTH_OPERATION_REPAIR = 'OAUTH_OPERATION_REPAIR';
    public const OAUTH_OPERATION_CLAIM = 'OAUTH_OPERATION_CLAIM';
    public const OAUTH_OPERATION_CLAIMED = 'OAUTH_OPERATION_CLAIMED';
    public const OAUTH_REFRESH_SERVICE = 'OAUTH_REFRESH_SERVICE';
    public const OPERATION_PROFILE = 'OPERATION_PROFILE';
    public const OWNERSHIP_GUARD = 'OWNERSHIP_GUARD';
    public const METADATA_GUARD = 'METADATA_GUARD';
    public const API_GUARD = 'API_GUARD';
    public const RHYTHM_RESERVATION = 'RHYTHM_RESERVATION';
    public const BUDGET_RESERVATION = 'BUDGET_RESERVATION';
    public const TRANSPORT_PREPARE = 'TRANSPORT_PREPARE';
    public const CURL_INIT = 'CURL_INIT';
    public const CURL_OPTIONS = 'CURL_OPTIONS';
    public const OAUTH_DISPATCH_FENCE = 'OAUTH_DISPATCH_FENCE';
    public const CURL_EXEC = 'CURL_EXEC';
    public const RESPONSE_KNOWN = 'RESPONSE_KNOWN';
    public const TOKEN_ESCROW = 'TOKEN_ESCROW';
    public const TOKEN_DB_CAS = 'TOKEN_DB_CAS';

    private static string $stage = self::SCHEDULER_START;
    /** @var (\Closure(string):void)|null */
    private static ?\Closure $testHook = null;

    public static function reset(): void
    {
        self::$stage = self::SCHEDULER_START;
    }

    public static function set(string $stage): void
    {
        if (!in_array($stage, self::all(), true)) {
            throw new RuntimeException('queue_v4_clean_oauth_stage_invalid');
        }
        self::$stage = $stage;
        if (self::$testHook !== null) {
            (self::$testHook)($stage);
        }
    }

    public static function setForCurrentOAuth(string $stage): void
    {
        if ((string) (ApiExecutionMetadataContext::current()['source'] ?? '') === MeliTransportSourcePolicy::QUEUE_V4_OAUTH) {
            self::set($stage);
        }
    }

    public static function current(): string
    {
        return self::$stage;
    }

    public static function installTestHook(?callable $hook): void
    {
        if (!defined('ERP_TEST_RUNTIME') || constant('ERP_TEST_RUNTIME') !== true) {
            throw new RuntimeException('queue_v4_clean_oauth_test_hook_denied');
        }
        self::$testHook = $hook === null ? null : \Closure::fromCallable($hook);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SCHEDULER_START, self::OAUTH_RUNTIME_PREFLIGHT, self::OAUTH_OPERATION_REPAIR,
            self::OAUTH_OPERATION_CLAIM, self::OAUTH_OPERATION_CLAIMED,
            self::OAUTH_REFRESH_SERVICE, self::OPERATION_PROFILE,
            self::OWNERSHIP_GUARD, self::METADATA_GUARD, self::API_GUARD,
            self::RHYTHM_RESERVATION, self::BUDGET_RESERVATION, self::TRANSPORT_PREPARE,
            self::CURL_INIT, self::CURL_OPTIONS, self::OAUTH_DISPATCH_FENCE,
            self::CURL_EXEC, self::RESPONSE_KNOWN, self::TOKEN_ESCROW, self::TOKEN_DB_CAS,
        ];
    }
}
